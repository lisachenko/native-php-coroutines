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

    public function slotId(): int
    {
        return $this->slotId;
    }

    public function isComplete(): bool
    {
        return $this->slots->slot($this->slotId)->complete;
    }

    public function await(): mixed
    {
        $slot = $this->slots->slot($this->slotId);

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
        }

        if ($slot->error !== null) {
            throw $slot->error;
        }

        return ValueCodec::fromRecord($slot->value ?? TaggedRecord::nil());
    }
}
