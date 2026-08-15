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
 * A worker died without completing the work it had been given.
 *
 * Raised in whichever process was waiting, and deliberately loud: the alternative to an exception
 * here is a coroutine parked forever on a result slot that nobody will ever fill. Any slots the
 * dead worker owned are named, so a caller can tell exactly which results are lost.
 */
final class WorkerCrashedException extends \RuntimeException implements CoroutineException
{
    /**
     * @param list<int> $abandonedSlots Result slots that can now never complete.
     */
    public function __construct(
        private readonly int $workerId,
        string $reason,
        private readonly array $abandonedSlots = [],
    ) {
        $message = sprintf('worker #%d died: %s', $workerId, $reason);
        if ($abandonedSlots !== []) {
            $message .= sprintf(
                '; %d result slot(s) can never complete: %s',
                count($abandonedSlots),
                implode(', ', array_map(static fn(int $slot): string => '#' . $slot, $abandonedSlots)),
            );
        }

        parent::__construct($message);
    }

    public function workerId(): int
    {
        return $this->workerId;
    }

    /** @return list<int> */
    public function abandonedSlots(): array
    {
        return $this->abandonedSlots;
    }

    public static function exitedWithStatus(int $workerId, int $status): self
    {
        return new self($workerId, sprintf('exited with status %d', $status));
    }

    public static function killedBySignal(int $workerId, int $signal): self
    {
        return new self($workerId, sprintf('killed by signal %d', $signal));
    }

    public static function notRunning(int $workerId): self
    {
        return new self($workerId, 'the worker is not running');
    }
}
