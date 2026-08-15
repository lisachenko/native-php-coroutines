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

use Lisachenko\NativePhpCoroutines\Exception\ClosedChannelException;

/**
 * A typed conduit between coroutines, local or cross-process.
 *
 * This is the surface `select` accepts, which is the whole point of having it: a local `Channel`
 * and a `SharedChannel` living in the arena are interchangeable here, so one `select` statement can
 * mix both without knowing which is which.
 *
 * @template T
 * @extends \IteratorAggregate<int, T>
 */
interface ChannelInterface extends \IteratorAggregate
{
    /**
     * Send a value, parking until a receiver takes it (capacity 0) or until there is buffer space.
     *
     * @param T $value
     * @throws ClosedChannelException When the channel is closed, here or while parked.
     */
    public function send(mixed $value): void;

    /**
     * Receive a value, parking until one is available.
     *
     * Returns null on a closed, drained channel — indistinguishable from a legitimately sent null,
     * which is why {@see self::recvOk()} exists.
     *
     * @return T|null
     */
    public function recv(): mixed;

    /**
     * Receive a value together with a liveness flag.
     *
     * The flag is false only when the channel is closed *and* drained; buffered values are still
     * delivered with true after a close.
     *
     * @return array{0: T|null, 1: bool}
     */
    public function recvOk(): array;

    /**
     * Close the channel, waking every waiter.
     *
     * Receivers drain the remaining buffered values first and only then start getting
     * `[null, false]`.
     *
     * @throws ClosedChannelException When the channel is already closed.
     */
    public function close(): void;

    public function isClosed(): bool;

    /** Buffer size; 0 means rendezvous, where a send and a receive hand the value over directly. */
    public function capacity(): int;

    /** Number of values currently buffered. */
    public function count(): int;

    /**
     * Whether a send would complete right now without parking.
     *
     * Used by `select` to poll before parking. A closed channel reports true, because the send
     * completes immediately — by throwing.
     */
    public function canSend(): bool;

    /** Whether a receive would complete right now without parking; true for a closed channel. */
    public function canRecv(): bool;

    /**
     * The descriptor whose readiness signals this channel, or null when readiness is known
     * in-process.
     *
     * A local channel returns null: nothing outside this process can change its state, so the
     * scheduler already knows when it becomes ready. A shared channel returns a real descriptor —
     * its state is changed by other processes, so the poller has to learn about it from the
     * kernel.
     *
     * @return resource|null
     */
    public function readinessFd();

    /**
     * Register a coroutine's interest in receiving, on behalf of one `select` case.
     *
     * The channel must {@see SelectToken::claim()} before acting on this waiter, {@see
     * SelectToken::deliver()} the outcome, and only then unpark the coroutine.
     */
    public function awaitReceivable(SelectToken $token, int $caseIndex, CoroutineInterface $coroutine): void;

    /**
     * Register a coroutine's interest in sending, on behalf of one `select` case.
     *
     * @param T $value
     */
    public function awaitSendable(SelectToken $token, int $caseIndex, CoroutineInterface $coroutine, mixed $value): void;

    /**
     * Unlink every waiter belonging to this token.
     *
     * Called for the losing cases once a `select` resolves. Skipping this is the classic select
     * leak: the losers stay queued, the queues grow on every loop iteration, and a later send
     * tries to hand a value to a coroutine that has moved on.
     */
    public function cancelWait(SelectToken $token): void;
}
