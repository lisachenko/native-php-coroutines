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

namespace Lisachenko\NativePhpCoroutines\Exception;

/**
 * A task panicked in another process; rethrown here when its result is awaited.
 *
 * The original throwable cannot be the `previous` of this one — it belongs to a process that may
 * already be gone, and reconstructing it would mean serializing an object graph, which the
 * Never-Serialize Rule forbids even for the runtime's own machinery. Instead the worker writes a
 * shared error-info object into the arena (class name, message and formatted trace as arena
 * strings) and the waiter reads those fields straight out of shared memory.
 */
final class ParallelTaskException extends \RuntimeException implements CoroutineException
{
    public function __construct(
        private readonly string $originalClass,
        string $originalMessage,
        private readonly string $originalTrace,
        private readonly int $workerId,
    ) {
        parent::__construct(
            sprintf(
                'task panicked in worker #%d: %s: %s',
                $workerId,
                $originalClass,
                $originalMessage,
            ),
        );
    }

    /** Class name of the throwable that ended the task. */
    public function originalClass(): string
    {
        return $this->originalClass;
    }

    /** The task's stack trace, as formatted in the worker that ran it. */
    public function originalTrace(): string
    {
        return $this->originalTrace;
    }

    public function workerId(): int
    {
        return $this->workerId;
    }
}
