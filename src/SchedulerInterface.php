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

namespace Lisachenko\NativePhpCoroutines;

use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;

/**
 * Drives the coroutines of one process.
 *
 * There is exactly one scheduler per process — the parent and every forked worker each run their
 * own. A scheduler never blocks in a kernel primitive except inside its poller: every wait in the
 * runtime, local or cross-process, funnels into that single `stream_select()`.
 */
interface SchedulerInterface
{
    /**
     * Create a coroutine and put it on the run queue.
     *
     * The coroutine does not start executing here; it starts when the scheduler next reaches it.
     *
     * @param \Closure(mixed...): mixed $body
     */
    public function spawn(\Closure $body, mixed ...$arguments): CoroutineInterface;

    /**
     * The coroutine currently executing, or null when control is in the scheduler itself.
     *
     * A null return is the signal that it is not safe to suspend — preemption in particular must
     * check this before forcing a yield.
     */
    public function current(): ?CoroutineInterface;

    /**
     * Put a runnable coroutine on the tail of the run queue.
     *
     * Called by primitives that have just unparked somebody. Enqueueing a coroutine that is not
     * READY is a programming error.
     */
    public function schedule(CoroutineInterface $coroutine): void;

    /**
     * Suspend the current coroutine, handing control back to the scheduler.
     *
     * Returns the value the coroutine is later resumed with. Must be called from inside a
     * coroutine; calling it when {@see self::current()} is null is a programming error.
     */
    public function suspend(SuspendCommand $command): mixed;

    /** The process-wide poller — the single blocking point every wait funnels into. */
    public function poller(): PollerInterface;

    /**
     * Run until the run queue drains and nothing is pending.
     *
     * @throws DeadlockException When coroutines remain blocked but nothing can ever wake them.
     */
    public function loop(): void;
}
