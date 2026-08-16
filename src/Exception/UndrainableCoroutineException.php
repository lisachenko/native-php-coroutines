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
 * A preempted coroutine spent its whole drain budget without reaching a safe point.
 *
 * The scheduler resumes a preempt-suspended coroutine until it terminates or parks somewhere its
 * own code chose, because a fiber suspended inside the interrupt callback cannot be released. A
 * coroutine with no cooperative point at all — `while (true) { $x++; }` — never leaves the
 * callback, and before the drain was bounded that was an unexplained hang at shutdown.
 *
 * This is that hang, converted into a sentence. It names each coroutine, how much of the budget it
 * was given, and the line that spawned it, because the spawn site is the only thing that leads back
 * to the loop that has to change.
 *
 * # Reading this exception means the process will not survive the run
 *
 * The straggler is still suspended in the callback and the scheduler still owns it: it is never
 * dropped, because dropping it — or letting request shutdown destroy it — is
 * `PHP Fatal error: Throwing from FFI callbacks is not allowed`, which no `catch` sees. Catching
 * this exception buys the time to log it and nothing more; the runtime terminates the process from
 * its shutdown handler once every other shutdown function has run.
 */
final class UndrainableCoroutineException extends \RuntimeException implements CoroutineException
{
    public const string HEADLINE = 'a preempted coroutine never reached a safe point - drain gave up!';

    public const string REMEDY = 'give it a cooperative point - Coroutine::yield(), a channel, a '
        . 'sleep or a Context check - so the scheduler can resume it out of the preemption callback; '
        . 'a fiber left suspended in there can never be released, so the process is terminated '
        . 'rather than handed to the engine to destroy';

    /**
     * @param list<array{id: int, origin: string, resumes: int, seconds: float}> $stragglers
     */
    public function __construct(private readonly array $stragglers)
    {
        parent::__construct(self::HEADLINE . "\n" . self::renderDump($stragglers) . "\n" . self::REMEDY);
    }

    /**
     * The coroutines that would not cooperate, with the effort each one was given.
     *
     * @return list<array{id: int, origin: string, resumes: int, seconds: float}>
     */
    public function stragglers(): array
    {
        return $this->stragglers;
    }

    /**
     * @param list<array{id: int, origin: string, resumes: int, seconds: float}> $stragglers
     */
    private static function renderDump(array $stragglers): string
    {
        $lines = [];
        foreach ($stragglers as $entry) {
            $lines[] = sprintf(
                'coroutine #%d [resumed %d time(s) over %.3fs, still inside the preemption callback], '
                . 'spawned at %s',
                $entry['id'],
                $entry['resumes'],
                $entry['seconds'],
                $entry['origin'],
            );
        }

        return implode("\n", $lines);
    }
}
