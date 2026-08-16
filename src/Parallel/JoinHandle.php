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
 * Shared slots are recycled, so holding one open for the rest of a run is a real cost: a pool that
 * never gives slots back exhausts a supply that is pre-sized in the arena and cannot grow. This
 * handle therefore takes its answer out of the slot the first time it is awaited, keeps it, and
 * tells {@see SlotTable::release()} the slot is finished with. Awaiting again replays what was
 * kept — the same value, or the same throwable — and never touches shared memory a second time.
 *
 * The order matters and is not an implementation detail: **capture, then release.** After the
 * release the shared record may already belong to another task, and a read of it would be refused
 * by generation rather than answered — which is the safety net, not the plan.
 */
final class JoinHandle implements JoinHandleInterface
{
    /** Whether the answer has been taken out of the slot and the slot handed back. */
    private bool $taken = false;

    private mixed $outcome = null;

    private ?\Throwable $failure = null;

    public function __construct(
        private readonly SlotTable $slots,
        private readonly SchedulerInterface $scheduler,
        private readonly int $slotId,
        private readonly int $workerId,
    ) {}

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

        $this->slots->release($this->slotId);

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->outcome;
    }
}
