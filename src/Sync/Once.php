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

namespace Lisachenko\NativePhpCoroutines\Sync;

use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\Internal\ParkingPrimitive;

/**
 * Runs an initializer exactly once, and makes everybody else wait for it.
 *
 *     $connection = $once->do(fn(): Connection => Connection::open($dsn));
 *
 * # Later callers block, they do not skip
 *
 * The initializer can suspend — it opens a connection, reads a file, talks to a channel — and while
 * it is suspended other coroutines reach the same `do()`. Returning early there would hand them a
 * half-initialised result, which is the entire bug this primitive exists to prevent. They park
 * instead and are woken with the finished value.
 *
 * # When the initializer throws
 *
 * The failure is recorded and the `Once` stays spent: the exception propagates to the first caller
 * and is re-thrown, as the same instance, to everybody waiting and to every later `do()`. Nothing
 * is retried.
 *
 * That is a deliberate choice between two defensible ones. Retrying on the next call is the
 * alternative, and it is the wrong default here: a failed initializer has usually already performed
 * part of its side effects, so running it again is not a clean second attempt, and a caller that
 * genuinely wants a retry can hold a fresh `Once`. Silently returning null, on the other hand, is
 * never right — it is the half-initialised result under a different name.
 */
final class Once extends ParkingPrimitive
{
    private bool $started = false;

    private bool $finished = false;

    private mixed $result = null;

    private ?\Throwable $failure = null;

    /** @var list<CoroutineInterface> */
    private array $waiters = [];

    /**
     * Run $initializer if nobody has yet, and return its result to every caller.
     *
     * @param \Closure(): mixed $initializer
     */
    public function do(\Closure $initializer): mixed
    {
        if ($this->finished) {
            return $this->replay();
        }

        if ($this->started) {
            $coroutine       = $this->blockingCoroutine('Once::do()');
            $this->waiters[] = $coroutine;
            $this->parkAndSuspend($coroutine, 'wait on Once');

            return $this->replay();
        }

        $this->started = true;

        try {
            $this->result = $initializer();
        } catch (\Throwable $failure) {
            $this->failure = $failure;
        } finally {
            // Set before waking anybody: a woken coroutine calls replay() immediately, and it must
            // never observe the "still running" state it was parked on.
            $this->finished = true;
            $this->releaseAll();
        }

        return $this->replay();
    }

    /** Whether the initializer has run to completion, successfully or not. */
    public function hasRun(): bool
    {
        return $this->finished;
    }

    /** Whether the initializer failed; the failure is re-thrown by every {@see self::do()}. */
    public function hasFailed(): bool
    {
        return $this->failure !== null;
    }

    private function replay(): mixed
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }

    private function releaseAll(): void
    {
        $waiters       = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $this->wake($waiter);
        }
    }
}
