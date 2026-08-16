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

use Lisachenko\SharedData\Ipc\SlotTicket;

/**
 * A worker died without completing the work it had been given.
 *
 * Raised in whichever process was waiting, and deliberately loud: the alternative to an exception
 * here is a coroutine parked forever on a result slot that nobody will ever fill. Any slots the
 * dead worker owned are named, so a caller can tell exactly which results are lost.
 *
 * A slot is named by its **ticket** — index and generation — because that is what a slot id is once
 * slots are recycled. `#7/gen3` is a different claim from `#7/gen4`, and a report that printed only
 * the index would point at whichever task holds the record now.
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
                implode(', ', array_map(self::nameSlot(...), $abandonedSlots)),
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

    /**
     * How one lost slot is spelled in the message.
     *
     * A shared slot id is a ticket and always carries a non-zero generation, because generation 0
     * is deliberately no slot at all. A runtime with no arena numbers its slots itself and has no
     * generations to report, so those keep the plain `#7` they always had rather than growing a
     * `/gen0` that would mean nothing.
     */
    private static function nameSlot(int $slot): string
    {
        return SlotTicket::generationOf($slot) === 0 ? '#' . $slot : SlotTicket::describe($slot);
    }
}
