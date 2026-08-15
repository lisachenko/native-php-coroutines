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

namespace Lisachenko\NativePhpCoroutines\Tests\Support;

use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\CoroutineStatus;
use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;
use Lisachenko\NativePhpCoroutines\PollerInterface;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

/**
 * A deterministic run queue: the smallest scheduler the channel and sync tests can be honest about.
 *
 * It exists because the real scheduler is being written in parallel, and it is deliberately strict
 * rather than forgiving, so that the tests written against it keep their meaning once the real one
 * replaces it:
 *
 * - **Strict FIFO, no timers, no IO.** Every ordering these tests assert is a consequence of the
 *   primitives, not of a clever scheduling policy.
 * - **`schedule()` rejects anything that is not READY, and rejects a coroutine already queued.** A
 *   primitive that enqueues a coroutine twice — the classic double-wake — fails loudly here instead
 *   of running a coroutine twice and corrupting a later assertion.
 * - **A drained run queue with coroutines still parked is a deadlock**, reported with the same
 *   {@see DeadlockException} the real scheduler owes. Several tests assert exactly that: proving
 *   that nobody could have woken a coroutine is how "the waiter was properly unlinked" is observed.
 */
final class FakeScheduler implements SchedulerInterface
{
    /** @var list<FakeCoroutine> */
    private array $runQueue = [];

    /** @var list<FakeCoroutine> */
    private array $spawned = [];

    private ?FakeCoroutine $current = null;

    private int $nextId = 1;

    private readonly FakePoller $poller;

    public function __construct()
    {
        $this->poller = new FakePoller();
    }

    public function spawn(\Closure $body, mixed ...$arguments): CoroutineInterface
    {
        $coroutine = new FakeCoroutine($this->nextId++, $body, array_values($arguments), self::callerLocation());

        $this->spawned[]  = $coroutine;
        $this->runQueue[] = $coroutine;

        return $coroutine;
    }

    public function current(): ?CoroutineInterface
    {
        return $this->current;
    }

    public function schedule(CoroutineInterface $coroutine): void
    {
        if (!$coroutine instanceof FakeCoroutine) {
            throw new \LogicException('FakeScheduler only schedules its own coroutines');
        }

        if ($coroutine->status() !== CoroutineStatus::READY) {
            throw new \LogicException(sprintf(
                'Coroutine #%d was scheduled while %s; only a READY coroutine may be enqueued',
                $coroutine->id(),
                $coroutine->status()->name,
            ));
        }

        foreach ($this->runQueue as $queued) {
            if ($queued === $coroutine) {
                throw new \LogicException(sprintf(
                    'Coroutine #%d is already on the run queue - it was woken twice',
                    $coroutine->id(),
                ));
            }
        }

        $this->runQueue[] = $coroutine;
    }

    public function suspend(SuspendCommand $command): mixed
    {
        if ($this->current === null) {
            throw new \LogicException('SchedulerInterface::suspend() was called outside a coroutine');
        }

        return \Fiber::suspend($command);
    }

    public function poller(): PollerInterface
    {
        return $this->poller;
    }

    public function loop(): void
    {
        while ($this->runQueue !== []) {
            $coroutine     = array_shift($this->runQueue);
            $this->current = $coroutine;

            try {
                $command = $coroutine->run();
            } finally {
                $this->current = null;
            }

            if ($command !== null && $command->staysRunnable()) {
                $coroutine->markReady();
                $this->schedule($coroutine);
            }
        }

        $blocked = $this->blockedCoroutines();
        if ($blocked !== []) {
            throw new DeadlockException($blocked);
        }
    }

    /**
     * Everything still parked on a wakeup that could only have come from inside this process.
     *
     * @return list<array{id: int, wait: string, origin: string}>
     */
    private function blockedCoroutines(): array
    {
        $blocked = [];
        foreach ($this->spawned as $coroutine) {
            if ($coroutine->status() !== CoroutineStatus::BLOCKED || $coroutine->isExternallyWakeable()) {
                continue;
            }

            $blocked[] = [
                'id'     => $coroutine->id(),
                'wait'   => $coroutine->waitDescription() ?? 'unknown',
                'origin' => $coroutine->spawnLocation(),
            ];
        }

        return $blocked;
    }

    /** Where spawn() was called from, as the deadlock dump's "spawned at" anchor. */
    private static function callerLocation(): string
    {
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2] ?? [];

        return sprintf('%s:%d', $frame['file'] ?? 'unknown', $frame['line'] ?? 0);
    }
}
