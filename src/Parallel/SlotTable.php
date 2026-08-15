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

use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;

/**
 * Every result this process is still waiting for, and who is parked on it.
 *
 * The table is what turns a 32-byte record arriving on a socket into a coroutine becoming runnable
 * again. It is also what makes a dead worker *loud*: {@see self::failPendingOf()} completes every
 * slot the worker owned with the crash, so the coroutines parked on them resume and throw instead
 * of waiting for a record that can never arrive.
 *
 * # Seam for ticket #7
 *
 * Slot ids are allocated here and are process-local today. In the arena world they name a slot in
 * shared memory, so the allocator becomes a shared bump allocator and completion becomes a write
 * into that slot followed by the `RESULT` record. The interface this class presents to
 * {@see JoinHandle} and {@see WorkerSupervisor} is meant to survive that change unchanged.
 */
final class SlotTable
{
    /** @var array<int, ResultSlot> */
    private array $slots = [];

    private int $nextId = 1;

    public function __construct(private readonly SchedulerInterface $scheduler) {}

    /** Reserve a slot for work about to be dispatched to $workerId. */
    public function open(int $workerId): ResultSlot
    {
        $slot = new ResultSlot($this->nextId++, $workerId);

        $this->slots[$slot->id] = $slot;

        return $slot;
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
     */
    public function failPendingOf(int $workerId, \Throwable $error): void
    {
        foreach ($this->pendingSlotsOf($workerId) as $slotId) {
            $this->completeWithError($slotId, $error);
        }
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
