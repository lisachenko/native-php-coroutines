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
 * The single blocking point of a process.
 *
 * Everything that waits — timers, IO, shared channels, result slots, worker control sockets —
 * registers a file descriptor here, so one `stream_select()` covers every possible wakeup. That is
 * what keeps the invariant true: a worker never blocks in a kernel primitive outside its
 * scheduler's idle loop.
 *
 * Implementations must retry on EINTR rather than treating it as an error. A signal arriving
 * during the select call is routine here, not exceptional: the preemption timer (SIGALRM) and
 * child reaping (SIGCHLD) both interrupt it by design.
 */
interface PollerInterface
{
    /**
     * Park the current coroutine until the stream is readable.
     *
     * @param resource $stream
     */
    public function awaitReadable($stream): void;

    /**
     * Park the current coroutine until the stream is writable.
     *
     * @param resource $stream
     */
    public function awaitWritable($stream): void;

    /**
     * Register a callback to run whenever the stream becomes readable, without owning a coroutine.
     *
     * This is the hook for readiness sources that wake *other* coroutines: a shared channel's wake
     * pipe, a worker's control socket. The callback is responsible for draining the descriptor —
     * pokes are level-triggered, so an undrained pipe reports readable forever and spins the
     * poller.
     *
     * @param resource                 $stream
     * @param \Closure(resource): void $onReadable
     */
    public function watchReadable($stream, \Closure $onReadable): void;

    /**
     * Stop watching a stream registered with {@see self::watchReadable()}.
     *
     * @param resource $stream
     */
    public function unwatch($stream): void;

    /**
     * Block until something is ready or the timeout expires.
     *
     * @param float|null $timeout Seconds to wait; null blocks indefinitely, 0.0 polls. The
     *                            scheduler passes the earliest timer deadline here, so a program
     *                            that is only sleeping idles instead of spinning.
     * @return int Number of coroutines made runnable by this call.
     */
    public function poll(?float $timeout): int;

    /** Whether anything is registered — i.e. whether a wakeup is still possible from outside. */
    public function hasWatches(): bool;
}
