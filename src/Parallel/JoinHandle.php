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

use Lisachenko\NativePhpCoroutines\JoinHandleInterface;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

/**
 * A claim on one result slot.
 *
 * {@see self::await()} parks the calling coroutine on the slot and nothing else: the wakeup comes
 * from the worker's control socket, which is registered with the process's single `stream_select()`,
 * so a program waiting on a parallel task is idle in the kernel rather than spinning.
 *
 * The park is marked **externally wakeable**, which keeps deadlock detection honest — no amount of
 * local scheduling could produce this wakeup, so a process with nothing but parallel waits
 * outstanding is idle, not deadlocked.
 *
 * # The handle owns the answer, not the slot
 *
 * A slot is expensive to hold: the local view costs a few hundred bytes for the life of the run
 * (issue #24) and the shared record costs one out of a supply that is pre-sized in the arena and
 * cannot grow (issue #16). This handle holds exactly **one claim** on both, and gives it back at
 * the first of two moments:
 *
 * - **when it is awaited.** The answer is taken out of the slot, kept here, and the claim released
 *   immediately — so a batch that spawns N tasks and awaits them in a loop is down to one live slot
 *   by the end of the loop rather than N until the array of handles goes out of scope. Awaiting
 *   again replays what was kept, the same value or the same throwable, and never reads shared
 *   memory a second time.
 * - **when it is collected**, for a handle nobody ever awaited. That is what keeps a fire-and-forget
 *   `spawnParallel()` loop from retaining anything at all.
 *
 * Whichever comes first, it happens **once**: two releases for one claim would drop somebody else's.
 *
 * The order within the first is not an implementation detail: **capture, then release.** After the
 * release the shared record may already belong to another task, and a read of it would be refused
 * by generation rather than answered — which is the safety net, not the plan.
 */
final class JoinHandle implements JoinHandleInterface
{
    /** Whether the answer has been taken out of the slot and kept here. */
    private bool $taken = false;

    /** Whether this handle's one claim has already gone back, from await() or from the destructor. */
    private bool $released = false;

    private mixed $outcome = null;

    private ?\Throwable $failure = null;

    public function __construct(
        private readonly SlotTable $slots,
        private readonly SchedulerInterface $scheduler,
        private readonly int $slotId,
        private readonly int $workerId,
    ) {}

    /**
     * A handle that was never awaited still gives its claim back.
     *
     * A handle is the only thing in this process that can still ask for the result, so its death is
     * the moment the slot stops being worth keeping. Without this a steady-state
     * `spawnParallel()->await()` loop retains one `ResultSlot` per spawn for the whole run — a few
     * hundred bytes a spawn that never show up in `memory_get_usage(true)` (2 MiB chunk granularity)
     * and never touch the arena watermark, which is exactly the climb
     * `tools/soak-arena-watermark.php` reports as RSS with both arena counters flat (issue #24).
     *
     * For a handle that **was** awaited this is a no-op: the claim went back then, which is the
     * point of the guard in {@see self::relinquish()}.
     *
     * A settled slot whose handle is collected without ever being awaited has its shared record
     * recycled too, and the answer in it is discarded — deliberately. Spawning without awaiting is
     * a statement that the result is not wanted, and the alternative is worse: a fire-and-forget
     * loop would consume the slot supply exactly as it did before recycling existed, which is the
     * bug this all exists to fix. Nothing is silently misled by it, because a sibling that attached
     * to the id gets a generation refusal rather than the next task's answer.
     */
    public function __destruct()
    {
        $this->relinquish();
    }

    public function slotId(): int
    {
        return $this->slotId;
    }

    public function isComplete(): bool
    {
        if ($this->taken) {
            return true;
        }

        $slot = $this->slots->slot($this->slotId);

        if (!$slot->complete) {
            // The authoritative state is in shared memory, and the process that settled it may not
            // have told anybody yet. Reading before answering is what makes this honest — and what
            // makes await() on an already-settled slot free of a park.
            $this->slots->refresh();
        }

        return $slot->complete;
    }

    public function await(): mixed
    {
        if ($this->taken) {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->outcome;
        }

        $slot = $this->slots->slot($this->slotId);

        // Read shared memory before every park, never after: a slot settled between the spawn and
        // this call is complete already, and parking on it would wait for a wakeup that has been
        // and gone.
        $this->slots->refresh();

        while (!$slot->complete) {
            $coroutine = $this->scheduler->current() ?? throw new \LogicException(
                'awaiting a parallel result is only possible inside a coroutine',
            );

            $slot->waiters[] = $coroutine;
            $coroutine->park(
                sprintf('result slot #%d on worker #%d', $this->slotId, $this->workerId),
                true,
            );

            $this->scheduler->suspend(SuspendCommand::BLOCKED);

            $this->slots->refresh();
        }

        // Taken out of the slot BEFORE it is handed back. An OBJ result is the very object the
        // worker mutated, at the address it lives at, and it stays valid afterwards: releasing a
        // slot returns the slot record, never the arena memory the answer lives in — the arena
        // frees nothing at all.
        $this->failure = $slot->error;
        $this->outcome = $slot->hasDecoded
            ? $slot->decoded
            : ValueCodec::fromRecord($slot->value ?? TaggedRecord::nil());
        $this->taken = true;

        $this->relinquish();

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->outcome;
    }

    /**
     * Hand back this handle's one claim, at most once.
     *
     * Both callers are legitimate and both can happen to the same handle - await() gives the claim
     * back as soon as the answer is safely copied out, and the destructor covers a handle that was
     * never awaited - so the guard is what keeps the pair from counting as two. Releasing twice
     * would take a claim belonging to another handle on the same slot, and `attachResult()` of a
     * slot this process opened is exactly that situation.
     */
    private function relinquish(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        $this->slots->release($this->slotId);
    }
}
