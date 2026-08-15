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

/**
 * The one `stream_select()` of the process.
 *
 * Two kinds of registration live here, and the difference is who gets woken:
 *
 * - {@see self::awaitReadable()} / {@see self::awaitWritable()} park **the calling coroutine** and
 *   are one-shot: readiness resumes it and the registration is gone.
 * - {@see self::watchReadable()} owns no coroutine at all. It is the hook for readiness sources
 *   that wake *somebody else* — a shared channel's wake pipe, a worker's control socket — and it
 *   stays registered until {@see self::unwatch()}.
 *
 * Two behaviours here are load-bearing rather than defensive:
 *
 * 1. **EINTR is routine, not an error.** A signal arriving mid-select (SIGALRM from preemption,
 *    SIGCHLD from a dying worker) makes `stream_select()` return false with a warning. This class
 *    suppresses the warning, recognises the interruption, and retries with what is *left* of the
 *    timeout — restarting the full timeout on every signal would make a 10ms sleep last as long as
 *    signals keep arriving.
 * 2. **Three empty arrays are never passed to `stream_select()`.** With nothing registered, PHP
 *    warns and refuses, so a poll with no descriptors sleeps on the timer deadline instead. Being
 *    asked to block with neither a descriptor nor a deadline is the deadlock condition, and it is
 *    reported as a bug here rather than slept on forever.
 */
final class StreamPoller implements PollerInterface
{
    /** @var array<int, array{stream: resource, waiters: list<CoroutineInterface>}> */
    private array $readWaiters = [];

    /** @var array<int, array{stream: resource, waiters: list<CoroutineInterface>}> */
    private array $writeWaiters = [];

    /** @var array<int, array{stream: resource, onReadable: \Closure(resource): void}> */
    private array $watches = [];

    public function __construct(private readonly SchedulerInterface $scheduler) {}

    public function awaitReadable($stream): void
    {
        $coroutine = $this->registerWaiter($stream, true);

        try {
            $this->scheduler->suspend(SuspendCommand::IO);
        } finally {
            // Reached on a normal wakeup (where poll() has already dropped the registration, so
            // this is a no-op) and on an exception thrown into the parked coroutine, where it is
            // the only thing standing between a cancelled wait and a stale waiter in the set.
            $this->dropWaiter($stream, true, $coroutine);
        }
    }

    public function awaitWritable($stream): void
    {
        $coroutine = $this->registerWaiter($stream, false);

        try {
            $this->scheduler->suspend(SuspendCommand::IO);
        } finally {
            $this->dropWaiter($stream, false, $coroutine);
        }
    }

    public function watchReadable($stream, \Closure $onReadable): void
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('watchReadable() needs an open stream resource');
        }

        $this->watches[get_resource_id($stream)] = ['stream' => $stream, 'onReadable' => $onReadable];
    }

    public function unwatch($stream): void
    {
        // Matched by identity rather than by resource id: a caller unwatching a stream it has
        // already closed is ordinary teardown, and `get_resource_id()` would refuse it.
        foreach ($this->watches as $id => $watch) {
            if ($watch['stream'] === $stream) {
                unset($this->watches[$id]);
            }
        }
    }

    public function poll(?float $timeout): int
    {
        $this->pruneClosedStreams();

        $read  = [];
        $write = [];

        foreach ($this->readWaiters as $id => $entry) {
            $read[$id] = $entry['stream'];
        }

        foreach ($this->watches as $id => $watch) {
            $read[$id] = $watch['stream'];
        }

        foreach ($this->writeWaiters as $id => $entry) {
            $write[$id] = $entry['stream'];
        }

        if ($read === [] && $write === []) {
            if ($timeout === null) {
                throw new \LogicException(
                    'poll(null) with nothing registered would block forever; '
                    . 'a scheduler with no timer and no descriptor must report a deadlock instead',
                );
            }

            $this->idle($timeout);

            return 0;
        }

        $ready = $this->select($read, $write, $timeout);

        $woken = 0;

        foreach ($ready['read'] as $id) {
            $watch = $this->watches[$id] ?? null;
            if ($watch !== null) {
                // Level-triggered: the callback owns draining the descriptor, or it reports
                // readable forever and the poller spins.
                ($watch['onReadable'])($watch['stream']);
            }

            $woken += $this->wakeWaiters($this->readWaiters, $id);
        }

        foreach ($ready['write'] as $id) {
            $woken += $this->wakeWaiters($this->writeWaiters, $id);
        }

        return $woken;
    }

    public function hasWatches(): bool
    {
        return $this->readWaiters !== [] || $this->writeWaiters !== [] || $this->watches !== [];
    }

    /**
     * Drop every registration without waking anybody.
     *
     * The scheduler calls this when a run ends: the coroutines parked here are being discarded, so
     * unparking them would only put dead weight back on a queue nobody is going to drain.
     */
    public function forgetAll(): void
    {
        $this->readWaiters  = [];
        $this->writeWaiters = [];
        $this->watches      = [];
    }

    /**
     * @param resource $stream
     */
    private function registerWaiter($stream, bool $forRead): CoroutineInterface
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('await() needs an open stream resource');
        }

        $coroutine = $this->scheduler->current()
            ?? throw new \LogicException('awaiting stream readiness is only possible inside a coroutine');

        $id        = get_resource_id($stream);
        $direction = $forRead ? 'read' : 'write';

        if ($forRead) {
            $this->readWaiters[$id] ??= ['stream' => $stream, 'waiters' => []];
            $this->readWaiters[$id]['waiters'][] = $coroutine;
        } else {
            $this->writeWaiters[$id] ??= ['stream' => $stream, 'waiters' => []];
            $this->writeWaiters[$id]['waiters'][] = $coroutine;
        }

        // Externally wakeable: only the kernel can end this wait, so no amount of local scheduling
        // could have woken it and deadlock detection must not count it.
        $coroutine->park(sprintf('IO %s on stream #%d', $direction, $id), true);

        return $coroutine;
    }

    /**
     * @param resource $stream
     */
    private function dropWaiter($stream, bool $forRead, CoroutineInterface $coroutine): void
    {
        $bucket = $forRead ? $this->readWaiters : $this->writeWaiters;

        foreach ($bucket as $id => $entry) {
            if ($entry['stream'] !== $stream) {
                continue;
            }

            $remaining = array_values(
                array_filter($entry['waiters'], static fn(CoroutineInterface $waiter): bool => $waiter !== $coroutine),
            );

            if ($remaining === []) {
                unset($bucket[$id]);
            } else {
                $bucket[$id]['waiters'] = $remaining;
            }
        }

        if ($forRead) {
            $this->readWaiters = $bucket;
        } else {
            $this->writeWaiters = $bucket;
        }
    }

    /**
     * @param array<int, array{stream: resource, waiters: list<CoroutineInterface>}> $bucket
     */
    private function wakeWaiters(array &$bucket, int $id): int
    {
        $entry = $bucket[$id] ?? null;
        if ($entry === null) {
            return 0;
        }

        // Unregister before unparking: readiness is one-shot for a waiting coroutine, and a
        // coroutine that goes straight back to awaiting must register anew.
        unset($bucket[$id]);

        $woken = 0;
        foreach ($entry['waiters'] as $waiter) {
            if ($waiter->unpark()) {
                $this->scheduler->schedule($waiter);
                ++$woken;
            }
        }

        return $woken;
    }

    /**
     * One `stream_select()`, retried across interruptions, never past the original deadline.
     *
     * @param array<int, resource> $read
     * @param array<int, resource> $write
     * @return array{read: list<int>, write: list<int>} Resource ids that came back ready.
     */
    private function select(array $read, array $write, ?float $timeout): array
    {
        $deadline  = $timeout === null ? null : TimerQueue::now() + (int) round(max(0.0, $timeout) * 1_000_000_000);
        $remaining = $timeout === null ? null : max(0.0, $timeout);

        while (true) {
            $readSet   = $read;
            $writeSet  = $write;
            $exceptSet = [];

            $seconds      = null;
            $microseconds = null;

            if ($remaining !== null) {
                $seconds      = (int) $remaining;
                $microseconds = (int) round(($remaining - $seconds) * 1_000_000);

                if ($microseconds >= 1_000_000) {
                    ++$seconds;
                    $microseconds -= 1_000_000;
                }
            }

            error_clear_last();
            $count = @stream_select($readSet, $writeSet, $exceptSet, $seconds, $microseconds);

            if ($count !== false) {
                return ['read' => self::readyIds($readSet), 'write' => self::readyIds($writeSet)];
            }

            if (!self::wasInterrupted()) {
                throw new \RuntimeException(
                    'stream_select() failed: ' . (error_get_last()['message'] ?? 'no diagnostic available'),
                );
            }

            if ($deadline === null) {
                continue;
            }

            $left = ($deadline - TimerQueue::now()) / 1_000_000_000;
            if ($left <= 0.0) {
                // The signal ate the rest of the timeout; report a timeout, not a readiness.
                return ['read' => [], 'write' => []];
            }

            $remaining = $left;
        }
    }

    /**
     * Whether the last `stream_select()` failure was a signal rather than a broken descriptor.
     *
     * PHP reports it as `stream_select(): Unable to select [4]: Interrupted system call`; the
     * `[4]` is EINTR. A failure with no diagnostic at all is not assumed to be a signal — retrying
     * that under a null timeout would spin forever.
     */
    private static function wasInterrupted(): bool
    {
        $message = error_get_last()['message'] ?? null;

        return $message !== null
            && (str_contains($message, 'Interrupted system call') || str_contains($message, '[4]'));
    }

    /**
     * `stream_select()` rewrites the arrays in place, keeping the keys it was handed.
     *
     * @param array<mixed> $set
     * @return list<int>
     */
    private static function readyIds(array $set): array
    {
        $ids = [];
        foreach (array_keys($set) as $key) {
            if (is_int($key)) {
                $ids[] = $key;
            }
        }

        return $ids;
    }

    /**
     * Sleep out a timer deadline when there is no descriptor to select on.
     *
     * A signal may cut this short; that costs nothing, because the scheduler re-checks its timer
     * heap and polls again for whatever is left.
     */
    private function idle(float $seconds): void
    {
        $microseconds = (int) round($seconds * 1_000_000);

        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    /**
     * Drop registrations whose stream has been closed underneath us.
     *
     * A closed resource in the select set turns every poll into a hard failure, and the coroutine
     * parked on it would never be woken by anything else — so it is unparked and left to discover
     * the closed stream itself.
     */
    private function pruneClosedStreams(): void
    {
        foreach ([true, false] as $forRead) {
            $bucket = $forRead ? $this->readWaiters : $this->writeWaiters;

            foreach ($bucket as $id => $entry) {
                if (is_resource($entry['stream'])) {
                    continue;
                }

                unset($bucket[$id]);

                foreach ($entry['waiters'] as $waiter) {
                    if ($waiter->unpark()) {
                        $this->scheduler->schedule($waiter);
                    }
                }
            }

            if ($forRead) {
                $this->readWaiters = $bucket;
            } else {
                $this->writeWaiters = $bucket;
            }
        }

        foreach ($this->watches as $id => $watch) {
            if (!is_resource($watch['stream'])) {
                unset($this->watches[$id]);
            }
        }
    }
}
