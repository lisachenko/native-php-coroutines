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
 */
final class JoinHandle implements JoinHandleInterface
{
    public function __construct(
        private readonly SlotTable $slots,
        private readonly SchedulerInterface $scheduler,
        private readonly int $slotId,
        private readonly int $workerId,
    ) {}

    /**
     * The claim this handle was given by `open()`/`adopt()` goes back when the handle does.
     *
     * A handle is the only thing in this process that can still ask for the result, so its death is
     * the moment the slot's **local view** stops being worth keeping. Without this a steady-state
     * `spawnParallel()->await()` loop retains one `ResultSlot` per spawn for the whole run — a few
     * hundred bytes a spawn that never show up in `memory_get_usage(true)` (2 MiB chunk granularity)
     * and never touch the arena watermark, which is exactly the climb
     * `tools/soak-arena-watermark.php` reports as RSS with both arena counters flat.
     *
     * The shared slot is untouched: its answer, and its id, stay in the arena.
     */
    public function __destruct()
    {
        $this->slots->release($this->slotId);
    }

    public function slotId(): int
    {
        return $this->slotId;
    }

    public function isComplete(): bool
    {
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

        if ($slot->error !== null) {
            throw $slot->error;
        }

        // Read straight out of the arena: an OBJ result is the very object the worker mutated, at
        // the address it lives at, and never a copy rebuilt from an encoding.
        if ($slot->hasDecoded) {
            return $slot->decoded;
        }

        return ValueCodec::fromRecord($slot->value ?? TaggedRecord::nil());
    }
}
