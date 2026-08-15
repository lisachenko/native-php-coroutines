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
use Lisachenko\NativePhpCoroutines\Internal\Delivery;
use Lisachenko\NativePhpCoroutines\Internal\ParkingPrimitive;
use Lisachenko\NativePhpCoroutines\Internal\WaitQueue;

/**
 * A channel between coroutines of one process.
 *
 * # Rendezvous is a handoff, not a one-slot buffer
 *
 * At capacity 0 a value never enters storage. Whoever arrives second finds the first one parked and
 * writes the value straight into that coroutine's wait node before waking it, so the arriving side
 * does not park at all and the value does not take a detour through the run queue. This is
 * observable, and deliberately so: `send` on a channel with a waiting receiver returns in the same
 * tick it was called.
 *
 * # Buffered channels park only at the boundaries
 *
 * With capacity > 0 a send parks only when the buffer is full and a receive only when it is empty,
 * which is why the two situations never overlap: a parked receiver implies an empty buffer, a
 * parked sender implies a full one.
 *
 * # Closing
 *
 * `close()` is a broadcast. Receivers keep draining whatever is buffered — still with `ok = true` —
 * and only see `[null, false]` once nothing is left. Senders are the opposite: sending to a closed
 * channel is a bug in the producer and throws, including for a producer that was already parked
 * when somebody else closed the channel.
 *
 * @template T
 * @implements ChannelInterface<T>
 */
final class Channel extends ParkingPrimitive implements ChannelInterface
{
    private static int $nextId = 1;

    /** Identifies the channel in park descriptions, which is what a deadlock dump prints. */
    private readonly int $id;

    /** @var list<T> */
    private array $buffer = [];

    /** @var WaitQueue<T> */
    private readonly WaitQueue $senders;

    /** @var WaitQueue<T> */
    private readonly WaitQueue $receivers;

    private bool $closed = false;

    /**
     * @param int $capacity 0 for a rendezvous channel, a positive buffer size otherwise.
     */
    public function __construct(SchedulerInterface $scheduler, private readonly int $capacity = 0)
    {
        if ($capacity < 0) {
            throw new \InvalidArgumentException(
                sprintf('A channel capacity cannot be negative, got %d', $capacity),
            );
        }

        parent::__construct($scheduler);

        $this->id        = self::$nextId++;
        $this->senders   = new WaitQueue();
        $this->receivers = new WaitQueue();
    }

    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw ClosedChannelException::onSend();
        }

        // A waiting receiver takes the value directly, whatever the capacity: with an empty buffer
        // there is nothing for the value to queue behind, and storing it first would only make the
        // receiver take it out again.
        $receiver = $this->receivers->claimNext();
        if ($receiver !== null) {
            $receiver->completeWith(new Delivery($value));
            $this->wake($receiver->coroutine);

            return;
        }

        if (count($this->buffer) < $this->capacity) {
            $this->buffer[] = $value;

            return;
        }

        $what      = sprintf('send on channel #%d', $this->id);
        $coroutine = $this->blockingCoroutine($what);
        $node      = $this->senders->enqueue($coroutine, new Delivery($value));
        $this->parkAndSuspend($coroutine, $what);

        if ($node->isClosed()) {
            throw ClosedChannelException::whileParked();
        }
    }

    public function recv(): mixed
    {
        return $this->receive()?->value();
    }

    public function recvOk(): array
    {
        $delivery = $this->receive();

        return $delivery === null ? [null, false] : [$delivery->value(), true];
    }

    public function close(): void
    {
        if ($this->closed) {
            throw ClosedChannelException::onClose();
        }

        $this->closed = true;

        // Receivers can only be parked on an empty buffer, so there is nothing left for them to
        // drain and they all learn the channel is finished.
        foreach ($this->receivers->drain() as $node) {
            if (!$node->tryClaim()) {
                continue;
            }

            $node->completeClosed();
            $this->wake($node->coroutine);
        }

        // Senders parked on a full buffer are a different matter: their values are lost and their
        // send is an error, but whatever is already buffered stays receivable.
        foreach ($this->senders->drain() as $node) {
            if (!$node->tryClaim()) {
                continue;
            }

            $node->completeClosed();
            $this->wake($node->coroutine);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    public function canSend(): bool
    {
        // A closed channel is "ready" to send: the send returns immediately, by throwing. Reporting
        // false would park a `select` on a channel that can never make progress.
        if ($this->closed) {
            return true;
        }

        if (count($this->buffer) < $this->capacity) {
            return true;
        }

        return $this->receivers->hasLive();
    }

    public function canRecv(): bool
    {
        return $this->buffer !== [] || $this->senders->hasLive() || $this->closed;
    }

    public function readinessFd()
    {
        // Nothing outside this process can change a local channel's state, so the scheduler already
        // knows when it becomes ready and the poller has nothing to watch.
        return null;
    }

    public function awaitReceivable(SelectToken $token, int $caseIndex, CoroutineInterface $coroutine): void
    {
        $this->receivers->enqueue($coroutine, null, $token, $caseIndex);
    }

    public function awaitSendable(SelectToken $token, int $caseIndex, CoroutineInterface $coroutine, mixed $value): void
    {
        $this->senders->enqueue($coroutine, new Delivery($value), $token, $caseIndex);
    }

    public function cancelWait(SelectToken $token): void
    {
        $this->senders->cancelToken($token);
        $this->receivers->cancelToken($token);
    }

    /**
     * Yield values until the channel is closed and drained.
     *
     * The whole of the `foreach` sugar: an absent delivery already means exhaustion, so iteration is
     * that and nothing else — no separate bookkeeping that could disagree with a direct receive.
     *
     * @return \Generator<int, T, mixed, void>
     */
    public function getIterator(): \Generator
    {
        while (true) {
            $delivery = $this->receive();
            if ($delivery === null) {
                return;
            }

            yield $delivery->value();
        }
    }

    /**
     * How many coroutines are parked trying to send.
     *
     * Part of the class surface rather than the interface: it exists so a test can prove that a
     * resolved `select` left no waiters behind, which is invisible from the outside otherwise.
     */
    public function pendingSenders(): int
    {
        return $this->senders->count();
    }

    /** How many coroutines are parked trying to receive; see {@see self::pendingSenders()}. */
    public function pendingReceivers(): int
    {
        return $this->receivers->count();
    }

    /**
     * Take the next value, parking if there is none yet.
     *
     * Null means the channel is closed and drained — the one thing a bare value cannot express,
     * since null is a perfectly ordinary thing to send.
     *
     * @return Delivery<T>|null
     */
    private function receive(): ?Delivery
    {
        if ($this->buffer !== []) {
            $value = array_shift($this->buffer);

            // The buffer just freed a slot, so the oldest parked sender can move in. Refilling here
            // rather than waking the sender to do it itself keeps the buffer's FIFO order intact.
            $handed = $this->takeParkedSenderValue();
            if ($handed !== null) {
                $this->buffer[] = $handed->value();
            }

            return new Delivery($value);
        }

        // An empty buffer with a parked sender is a rendezvous: take the value straight out of the
        // sender's node.
        $delivery = $this->takeParkedSenderValue();
        if ($delivery !== null) {
            return $delivery;
        }

        if ($this->closed) {
            return null;
        }

        $what      = sprintf('recv on channel #%d', $this->id);
        $coroutine = $this->blockingCoroutine($what);
        $node      = $this->receivers->enqueue($coroutine, null);
        $this->parkAndSuspend($coroutine, $what);

        return $node->delivery();
    }

    /**
     * Take the oldest parked sender's value and wake it, or report that nobody is parked.
     *
     * @return Delivery<T>|null
     */
    private function takeParkedSenderValue(): ?Delivery
    {
        $sender = $this->senders->claimNext();
        if ($sender === null) {
            return null;
        }

        $delivery = $sender->delivery();
        if ($delivery === null) {
            // Unreachable: a sender is only ever queued together with the value it is handing over.
            // Said out loud rather than skipped, because skipping would leave this sender parked
            // for good on a wakeup that has already been claimed and can never come again.
            throw new \LogicException('A parked sender is queued without the value it is sending');
        }

        $sender->completeSent();
        $this->wake($sender->coroutine);

        return $delivery;
    }
}
