<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace Lisachenko\NativePhpCoroutines\Parallel;

use Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;
use Lisachenko\SharedData\Ipc\SharedError;
use Lisachenko\SharedData\Ipc\SlotResult;

/**
 * Every result this process is still waiting for, and who is parked on it.
 *
 * The table is what turns a record arriving on a socket — or a poke on the wake pipe — into a
 * coroutine becoming runnable again. It is also what makes a dead worker *loud*:
 * {@see self::failPendingOf()} completes every slot the worker owned with the crash, so the
 * coroutines parked on them resume and throw instead of waiting for a record that can never arrive.
 *
 * # Two backings, one surface
 *
 * Without an arena the slot's value is the 16-byte record that came off the control socket, which
 * is complete for `NIL`/`TRUE`/`FALSE`/`INT`/`FLOAT` and nothing else.
 *
 * With an arena the slot itself lives in the substrate's `ResultSlotTable` in shared memory: ids are
 * allocated there, any process in the family may settle one, and this class only ever *reads* it —
 * {@see self::refresh()} materializes settled slots and wakes their local waiters. The read is the
 * substrate's `readSlot()`, which takes the slot's mutex for the `{state, tag, payload}` triple and
 * materializes the value after releasing it, so a reader can never observe a tag from one generation
 * and a payload from another.
 *
 * # A local view lives as long as somebody here can still read it, and no longer
 *
 * The authority is shared memory, so the entry in this table is only a per-process convenience: the
 * identity, the parked coroutines and the answer once it has been materialized. It is therefore
 * **claimed** — {@see self::open()} and {@see self::adopt()} hand out one claim, a {@see JoinHandle}
 * carries it, and the handle's destructor gives it back. A settled slot with no claims and no
 * waiters is forgotten.
 *
 * Keeping settled slots instead is a real leak and a quiet one: a `spawnParallel()->await()` loop
 * retains a `ResultSlot` per spawn (~220 bytes with its array bucket) for the life of the run, which
 * is far below the 2 MiB chunk granularity of `memory_get_usage(true)` and costs the arena nothing,
 * so it shows up only as the parent's RSS climbing — the shape reported in issue #24.
 *
 * Forgetting the view does **not** free the shared slot. Ids are bump-allocated from a pre-sized
 * table and never recycled, on purpose, and the settled answer stays readable by every process of
 * the family — {@see self::adopt()} takes a fresh view of it whenever one is wanted again.
 */
final class SlotTable
{
    /** @var array<int, ResultSlot> */
    private array $slots = [];

    private int $nextId = 1;

    /**
     * @param SharedArena|null $arena When present, slot ids and slot state live in shared memory
     *                                and this table is the per-process view of them.
     */
    public function __construct(
        private readonly SchedulerInterface $scheduler,
        private readonly ?SharedArena $arena = null,
    ) {
        $arena?->registerListener(function (): void {
            $this->refresh();
        });
    }

    /** Whether the slots of this table live in the shared arena. */
    public function isShared(): bool
    {
        return $this->arena !== null;
    }

    /**
     * How many slots this process still holds a local view of.
     *
     * A diagnostic, and the one a memory gate wants: in a steady state it tracks the work actually
     * in flight, so a number that grows with the *total* number of spawns is the leak this class's
     * claim counting exists to prevent.
     */
    public function liveViews(): int
    {
        return count($this->slots);
    }

    /**
     * Reserve a slot for work about to be dispatched to $workerId.
     *
     * The slot comes back holding **one claim**, which the caller owns and must hand to a
     * {@see JoinHandle} — or {@see self::release()} itself if the dispatch never happens.
     */
    public function open(int $workerId): ResultSlot
    {
        $id = $this->arena?->slotTable()->allocateSlot() ?? $this->nextId++;

        $slot = new ResultSlot($id, $workerId);

        $slot->claims           = 1;
        $this->slots[$slot->id] = $slot;

        return $slot;
    }

    /**
     * Take a local view of a slot some *other* process allocated.
     *
     * This is what makes a result awaitable from a process that did not spawn the task: the slot id
     * is the whole handle, the state is in shared memory, and a process that has attached to the
     * arena can read it whether or not it has ever heard of the worker that will settle it.
     *
     * Like {@see self::open()} this hands out a claim, so adopting a slot twice is two claims and
     * the view survives until both handles are gone.
     */
    public function adopt(int $slotId, int $workerId = -1): ResultSlot
    {
        if ($this->arena === null) {
            throw new \LogicException(
                'a result slot can only be adopted from another process when the slots live in the '
                . 'shared arena; construct the runtime with workers > 0',
            );
        }

        $slot = $this->slots[$slotId] ??= new ResultSlot($slotId, $workerId);

        ++$slot->claims;

        return $slot;
    }

    /**
     * Give up one claim on a slot's local view, and forget the view if that was the last one.
     *
     * Paired with the claim {@see self::open()} and {@see self::adopt()} hand out — {@see JoinHandle}
     * releases in its destructor, so the view lives exactly as long as something in this process can
     * still ask for the result. Releasing a slot this table does not know is a no-op: it has already
     * been forgotten, which is the same outcome.
     */
    public function release(int $slotId): void
    {
        $slot = $this->slots[$slotId] ?? null;

        if ($slot === null) {
            return;
        }

        $slot->claims = max(0, $slot->claims - 1);

        $this->forgetIfSettled($slot);
    }

    public function slot(int $slotId): ResultSlot
    {
        return $this->slots[$slotId] ?? throw new \OutOfBoundsException(
            sprintf('there is no result slot #%d in this process', $slotId),
        );
    }

    /**
     * Slots the worker still owes an answer for.
     *
     * @return list<int>
     */
    public function pendingSlotsOf(int $workerId): array
    {
        $pending = [];

        foreach ($this->slots as $slot) {
            if ($slot->workerId === $workerId && !$slot->complete) {
                $pending[] = $slot->id;
            }
        }

        return $pending;
    }

    /**
     * Read shared memory for every slot still open here, and wake whoever was waiting on a settled
     * one.
     *
     * Called on every wake-pipe readiness and before every park, so an already-settled slot never
     * costs a park. A slot that is still pending is left exactly as it is: nothing is consumed and
     * nothing is guessed.
     *
     * @return int How many slots were settled by this pass.
     */
    public function refresh(): int
    {
        $arena = $this->arena;

        if ($arena === null) {
            return 0;
        }

        $settled = 0;

        foreach ($this->slots as $slot) {
            if ($slot->complete) {
                continue;
            }

            $result = $arena->slotTable()->readSlot($slot->id);

            if ($result->isPending()) {
                continue;
            }

            $this->settle($slot, $result);
            ++$settled;
        }

        return $settled;
    }

    /** A `RESULT` record arrived: put the value down and wake whoever wanted it. */
    public function completeWithValue(int $slotId, TaggedRecord $value): void
    {
        $slot = $this->slots[$slotId] ?? null;

        // A record for a slot this process does not know about is not worth a panic: it can only
        // come from a worker that outlived the run that dispatched to it.
        if ($slot === null || $slot->complete) {
            return;
        }

        $slot->complete = true;
        $slot->value    = $value;

        $this->wake($slot);
    }

    /** A `PANIC` record arrived, or the worker died: complete the slot with the failure. */
    public function completeWithError(int $slotId, \Throwable $error): void
    {
        $slot = $this->slots[$slotId] ?? null;

        if ($slot === null || $slot->complete) {
            return;
        }

        $slot->complete = true;
        $slot->error    = $error;

        $this->wake($slot);
    }

    /**
     * Fail everything a dead worker owed, with one exception describing its death.
     *
     * This is the anti-hang rule of the whole supervision layer: a result that can never arrive must
     * turn into a throw at the waiter, never into a coroutine parked for the rest of the run.
     *
     * With an arena the shared slot is read once more first, because a worker that was killed
     * *after* settling its slot did leave a real answer behind and that answer is still valid. Only
     * what is genuinely unsettled becomes the crash — a slot whose lock this process had to recover
     * from a dead owner is never read as if it were an answer.
     */
    public function failPendingOf(int $workerId, \Throwable $error): void
    {
        $this->refresh();

        foreach ($this->pendingSlotsOf($workerId) as $slotId) {
            $this->completeWithError($slotId, $error);
        }
    }

    /**
     * Turn a settled shared slot into this process's answer.
     *
     * The panic branch reads the shared error-info object by **named property**. Nothing here may
     * `var_dump()` it, cast it to an array or run it through `json_encode()`: those make engine C
     * code write a per-process `properties` pointer into the shared struct, and the next sibling to
     * read that object segfaults. A panic handler is exactly the code most likely to reach for a
     * dump, which is why it is spelled out here rather than assumed.
     *
     * A panic slot whose payload does not attach as a {@see SharedError} still surfaces as a
     * `ParallelTaskException` — the panic itself is certain, only its detail is missing — and the
     * exception says the detail is unavailable rather than presenting whatever object was found as
     * this task's failure. The worker is not declared dead over it: it settled the slot, so it is
     * demonstrably alive.
     */
    private function settle(ResultSlot $slot, SlotResult $result): void
    {
        if (!$result->isPanic()) {
            $slot->complete   = true;
            $slot->hasDecoded = true;
            $slot->decoded    = $result->value;

            $this->wake($slot);

            return;
        }

        $error = $result->value;

        $slot->complete = true;
        $slot->error    = $error instanceof SharedError
            ? new ParallelTaskException($error->className, $error->message, $error->trace, $slot->workerId)
            : new ParallelTaskException(
                'Throwable',
                'its error detail is unavailable — the slot payload did not attach as a shared '
                . 'error-info object, and no other task\'s detail is presented in its place',
                '',
                $slot->workerId,
            );

        $this->wake($slot);
    }

    private function wake(ResultSlot $slot): void
    {
        $waiters       = $slot->waiters;
        $slot->waiters = [];

        foreach ($waiters as $waiter) {
            if ($waiter->unpark()) {
                $this->scheduler->schedule($waiter);
            }
        }

        // A slot nobody holds any more is settled here for the last time; keeping its view would
        // retain a ResultSlot per spawn for the rest of the run.
        $this->forgetIfSettled($slot);
    }

    /**
     * Drop the local view of a slot that has settled and that nothing in this process still holds.
     *
     * Only ever a *local* forget. The shared slot keeps its answer and keeps its id: any process of
     * the family — including this one, through {@see self::adopt()} — can read it again afterwards,
     * because the authority was never this table. A slot that is still pending stays, whatever its
     * claim count: the supervisor owes it either an answer or a crash, and
     * {@see self::failPendingOf()} has to be able to find it.
     */
    private function forgetIfSettled(ResultSlot $slot): void
    {
        if ($slot->claims > 0 || !$slot->complete || $slot->waiters !== []) {
            return;
        }

        unset($this->slots[$slot->id]);
    }
}
