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

use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;

/**
 * A parallel execution context the runtime can hand work to.
 *
 * `ProcessWorker` is the implementation today. The interface is kept free of anything
 * process-specific — no pids, no signals — so that a future thread-backed worker fits behind it
 * without reshaping callers. What a worker owes the runtime is: an identity, a way to receive a
 * task, a readiness descriptor the poller can watch, and an orderly shutdown.
 */
interface WorkerInterface
{
    /** Stable index of this worker within the pool, used for round-robin and for pinning. */
    public function id(): int;

    /**
     * Hand a task to this worker.
     *
     * Writes a `SPAWN` control record naming the result slot and the arena address of the task.
     * Per the Never-Serialize Rule the record is all that crosses the boundary — never the task's
     * bytes.
     *
     * @throws WorkerCrashedException When the worker is no longer alive to accept work.
     */
    public function dispatch(int $slotId, int $taskAddress): void;

    /**
     * The descriptor that becomes readable when this worker has something to say.
     *
     * Registered with the poller, so worker events land in the same `stream_select()` as every
     * other wait.
     *
     * @return resource|null
     */
    public function readinessFd();

    public function isAlive(): bool;

    /**
     * Ask the worker to stop, and wait up to $graceSeconds for it to do so.
     *
     * The polite rung of the shutdown ladder: a `SHUTDOWN` record first, escalation afterwards.
     * Returns true when the worker exited within the grace period.
     */
    public function shutdown(float $graceSeconds): bool;

    /** Stop the worker immediately, without waiting for in-flight work. */
    public function terminate(): void;
}
