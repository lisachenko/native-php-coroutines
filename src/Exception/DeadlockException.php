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
 * Every coroutine is blocked and nothing can ever wake them.
 *
 * Go's runtime prints this and aborts the process; here it is an ordinary catchable exception,
 * because a PHP process usually has a life beyond the coroutine runtime.
 *
 * A coroutine only counts towards a deadlock when its wakeup could have come from inside this
 * process. Anything waiting on a shared primitive, a result slot or stream readiness is
 * *externally wakeable* and is excluded — reporting those would turn every idle server into a
 * false deadlock.
 */
final class DeadlockException extends \RuntimeException implements CoroutineException
{
    public const string HEADLINE = 'all coroutines are asleep - deadlock!';

    /**
     * @param list<array{id: int, wait: string, origin: string}> $blocked
     */
    public function __construct(private readonly array $blocked)
    {
        parent::__construct(self::HEADLINE . "\n" . self::renderDump($blocked));
    }

    /**
     * What each blocked coroutine was waiting on, in the order the scheduler holds them.
     *
     * @return list<array{id: int, wait: string, origin: string}>
     */
    public function blockedCoroutines(): array
    {
        return $this->blocked;
    }

    /**
     * @param list<array{id: int, wait: string, origin: string}> $blocked
     */
    private static function renderDump(array $blocked): string
    {
        $lines = [];
        foreach ($blocked as $entry) {
            $lines[] = sprintf(
                'coroutine #%d [%s], spawned at %s',
                $entry['id'],
                $entry['wait'],
                $entry['origin'],
            );
        }

        return implode("\n", $lines);
    }
}
