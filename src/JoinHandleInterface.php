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

use Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException;
use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;

/**
 * A claim on the result of a task running in another process.
 *
 * The handle refers to a result slot in the shared arena — `{state, 16-byte tagged record}` — not
 * to a buffer of bytes in transit. When the task finishes, the worker writes the outcome into that
 * slot and sends a `RESULT` record; {@see self::await()} then reads the value straight out of
 * shared memory.
 */
interface JoinHandleInterface
{
    /** Identifier of the arena result slot this handle refers to. */
    public function slotId(): int;

    /**
     * Whether the slot has been completed, successfully or by panic.
     *
     * A true answer guarantees {@see self::await()} will not park.
     */
    public function isComplete(): bool;

    /**
     * Wait for the task and return its value.
     *
     * Parks the calling coroutine on the poller until the `RESULT` record arrives; returns
     * immediately when the slot is already complete. May be awaited from any process in the tree,
     * not only the one that spawned the task.
     *
     * @throws ParallelTaskException When the task ended in an uncaught Throwable. The original
     *                               class, message and trace are read from the shared error-info
     *                               object — they are not serialized across the boundary.
     * @throws WorkerCrashedException When the worker died before completing the slot, so the
     *                                result can never arrive. Failing loudly here is deliberate:
     *                                the alternative is a hang.
     */
    public function await(): mixed;
}
