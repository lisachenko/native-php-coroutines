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
 * A countdown that coroutines can wait on.
 *
 *     $group = new WaitGroup($scheduler);
 *     foreach ($urls as $url) {
 *         $group->add();
 *         $scheduler->spawn(function () use ($group, $url): void {
 *             try { fetch($url); } finally { $group->done(); }
 *         });
 *     }
 *     $group->wait();
 *
 * `add()` before spawning, never inside the spawned coroutine: a counter that is still zero when
 * `wait()` runs is a counter that lets `wait()` through immediately.
 *
 * A counter that would go negative throws rather than clamping. Going negative means more `done()`
 * calls than `add()`s — a double `done()` in an error path, most often — and clamping would hide it
 * behind a `wait()` that returned slightly too early, which is far harder to find later.
 */
final class WaitGroup extends ParkingPrimitive
{
    private int $counter = 0;

    /** @var list<CoroutineInterface> */
    private array $waiters = [];

    /**
     * @param int $delta How many units of work to account for; defaults to one.
     */
    public function add(int $delta = 1): void
    {
        $next = $this->counter + $delta;
        if ($next < 0) {
            throw new \LogicException(
                sprintf('A WaitGroup counter cannot go negative: %d + %d', $this->counter, $delta),
            );
        }

        $this->counter = $next;

        if ($next === 0) {
            $this->releaseAll();
        }
    }

    /** Account for one finished unit of work. */
    public function done(): void
    {
        $this->add(-1);
    }

    /**
     * Block until the counter reaches zero.
     *
     * Returns immediately on an already-zero counter — waiting for work that is already finished is
     * not an error, and parking there would be an instant deadlock.
     */
    public function wait(): void
    {
        if ($this->counter === 0) {
            return;
        }

        $coroutine       = $this->blockingCoroutine('WaitGroup::wait()');
        $this->waiters[] = $coroutine;
        $this->parkAndSuspend($coroutine, 'wait on WaitGroup');
    }

    public function count(): int
    {
        return $this->counter;
    }

    /**
     * Wake every waiter.
     *
     * The list is emptied first: a woken coroutine may `add()` again and start a fresh round, and
     * it must not inherit the previous round's waiters.
     */
    private function releaseAll(): void
    {
        $waiters       = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $this->wake($waiter);
        }
    }
}
