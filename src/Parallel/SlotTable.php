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
 * # Shared slots are borrowed, and this class is what gives them back
 *
 * The substrate recycles slots, which is what stops a long-running pool exhausting a supply that is
 * pre-sized in the arena and cannot grow. A slot goes back on its free list only when its owner says
 * so, and that signal is this class's job: {@see self::release()} hands the record back once the
 * last local handle has taken its answer.
 *
 * **When exactly it is safe** is the whole question, and the answer is *after the outcome has been
 * copied into per-process state*. {@see self::settle()} is the only place this process reads the
 * shared record, and it copies everything it needs — the decoded value, or a
 * {@see ParallelTaskException} built from the shared error object's named properties — into the
 * local {@see ResultSlot} before returning. From that point nothing here looks at shared memory for
 * that slot again: {@see self::refresh()} skips slots that are locally complete, and
 * {@see self::failPendingOf()} reaches the shared record only through `refresh()`. Releasing after
 * a handle has taken its answer therefore has no late reader to race with.
 *
 * The values that came *out* of the record stay valid: an `OBJ` or `STR` result is an address into
 * the arena, and the arena frees nothing — releasing a slot returns the slot **record**, not the
 * memory the answer lives in. The same goes for a panic's `SharedError`: the strings the
 * `ParallelTaskException` carries are arena strings, and no reclamation of that graph is attempted
 * here. A child may never free arena memory at all, so error graphs remain leak-until-teardown.
 *
 * Two slots deliberately do **not** come back:
 *
 * - one whose worker died before settling it. The local answer is a crash exception, the shared
 *   record is still pending, and a zombie worker may yet write to it. Recycling it would let that
 *   write land on another task's slot.
 * - one whose handle is never awaited. Nothing has read it, so nothing can say it is safe.
 *
 * Both are the pre-recycling behaviour, scoped down to the cases that actually need it.
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
     * Reserve a slot for work about to be dispatched to $workerId.
     *
     * With an arena the id that comes back is a substrate slot **ticket** — index and generation —
     * and this process owns it: it is the one that will give the record back.
     */
    public function open(int $workerId): ResultSlot
    {
        $id = $this->arena?->slotTable()->allocateSlot() ?? $this->nextId++;

        $slot = new ResultSlot($id, $workerId);

        ++$slot->handles;

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
     * The shared record is read **here**, before anything else, precisely because the id may be
     * stale: slots are recycled, and a ticket the table has moved past is refused by the substrate
     * with the slot and both generations named. That refusal is the point — the alternative to it
     * is attaching to whichever task got the slot next and presenting its result as this one's.
     *
     * An adopter is not an owner. It never releases the record, because it cannot know who else in
     * the family is still reading it; the process that allocated the slot does that, and an adopter
     * that has not finished by then finds its next read refused rather than answered.
     */
    public function adopt(int $slotId, int $workerId = -1): ResultSlot
    {
        $arena = $this->arena ?? throw new \LogicException(
            'a result slot can only be adopted from another process when the slots live in the '
            . 'shared arena; construct the runtime with workers > 0',
        );

        $slot = $this->slots[$slotId] ?? null;

        if ($slot === null) {
            $result = $arena->slotTable()->readSlot($slotId);
            $slot   = $this->slots[$slotId] = new ResultSlot($slotId, $workerId, owned: false);

            if (!$result->isPending()) {
                $this->settle($slot, $result);
            }
        }

        ++$slot->handles;

        return $slot;
    }

    /**
     * Give a slot back once the handle holding it has taken its answer.
     *
     * Counted rather than immediate: two handles in one process may name one slot — `attachResult()`
     * of a slot this process opened is exactly that — and the record only goes back when the last
     * of them is satisfied. The local entry is dropped either way, because a per-process map that
     * only ever grows is a leak of its own on a pool that runs for days.
     */
    public function release(int $slotId): void
    {
        $slot = $this->slots[$slotId] ?? null;

        if ($slot === null) {
            return;
        }

        if ($slot->handles > 0) {
            --$slot->handles;
        }

        if ($slot->handles > 0 || !$slot->complete) {
            return;
        }

        unset($this->slots[$slotId]);

        // Only what this process owns, and only what the family really settled: a slot completed
        // locally because its worker died is still pending in shared memory, and the substrate
        // refuses to recycle a pending slot for the same reason we would not want it to.
        if (!$slot->owned || !$slot->sharedSettled) {
            return;
        }

        $this->arena?->slotTable()->releaseSlot($slotId);
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
        // The one place this process reads the shared record, so the one place that decides when it
        // is safe to hand it back: everything the waiter will ever need is copied into per-process
        // state right here, and nothing after this point looks at shared memory for this slot.
        $slot->sharedSettled = true;

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
    }
}
