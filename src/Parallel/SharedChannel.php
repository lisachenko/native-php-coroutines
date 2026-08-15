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

namespace Lisachenko\NativePhpCoroutines\Parallel;

use Lisachenko\NativePhpCoroutines\ChannelInterface;
use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\Exception\ClosedChannelException;
use Lisachenko\NativePhpCoroutines\Internal\Delivery;
use Lisachenko\NativePhpCoroutines\SelectToken;
use Lisachenko\NativePhpCoroutines\SuspendCommand;
use Lisachenko\SharedData\Ipc\SharedChannel as SubstrateChannel;
use Lisachenko\SharedData\Ipc\ValueTag;
use Lisachenko\SharedData\Ipc\WakeOpcode;

/**
 * A channel whose ring lives in the shared arena, behind the same interface as a local one.
 *
 * That is the entire point of the class: it implements {@see ChannelInterface}, so it drops into the
 * existing {@see \Lisachenko\NativePhpCoroutines\Select} unchanged and a single `select` statement
 * can mix a cross-process channel with a local one without knowing which is which.
 *
 * # The values are real values, and only addresses cross
 *
 * A send hands the value to the substrate's codec, which puts scalars inline, interns a string into
 * the arena and takes the *address* of a shared object or `SharedArray`. Nothing is serialized on
 * any path, and a value with no address-shaped form is refused with the remedy named.
 *
 * # Parking, not spinning
 *
 * The substrate's own `send()`/`recv()` are spin loops with a sleep, and its documentation says
 * outright that parking a Fiber belongs to the consumer runtime. This class is that consumer: it
 * only ever calls the non-blocking `trySend()`/`tryRecv()`, and when they cannot proceed it parks
 * the coroutine and lets {@see SharedArena} wake it when the wake pipe says something changed. The
 * park is marked **externally wakeable**, because no amount of local scheduling could produce that
 * wakeup and deadlock detection must not count it.
 *
 * A wakeup is a hint, never a delivery: a woken coroutine retries the operation and parks again if
 * another process got there first. That is what makes a spurious wakeup harmless and keeps the
 * whole design on the safe side of the level-triggered contract.
 *
 * # Capacity 0 is not available here
 *
 * The substrate's rendezvous handshake counts receivers that parked through *its* blocking `recv()`,
 * and this class deliberately never calls it. A capacity-0 shared channel would therefore accept a
 * send only while a sibling happened to be spinning inside the substrate, which is not a semantics
 * anybody can build on — so it is refused at declaration rather than delivered as a channel that
 * usually does not hand anything over. Cross-process rendezvous stays with the substrate's own API.
 *
 * The value type is deliberately `mixed`: what may travel is decided by the tag table, not by
 * a PHP generic, and a shared channel hands back exactly what the substrate's codec materialized
 * from the record — a scalar, an arena string, a shared object or a `SharedArray`.
 *
 * @implements ChannelInterface<mixed>
 */
final class SharedChannel implements ChannelInterface
{
    /**
     * Coroutines parked on this channel in this process.
     *
     * @var list<array{coroutine: CoroutineInterface, send: bool, token: SelectToken|null, case: int, value: mixed}>
     */
    private array $waiters = [];

    private bool $rechecking = false;

    public function __construct(
        private readonly SharedArena $arena,
        private readonly SubstrateChannel $channel,
    ) {
        $arena->registerChannel($this);
    }

    /** The arena address of the ring — this channel's identity in every process of the family. */
    public function address(): int
    {
        return $this->channel->address();
    }

    public function send(mixed $value): void
    {
        while (true) {
            if ($this->channel->isClosed()) {
                throw ClosedChannelException::onSend();
            }

            // Encoding happens inside trySend(), outside every lock: interning a string allocates
            // arena memory and a value that cannot be shared must throw before a lock is taken.
            if ($this->channel->trySend($value)) {
                $this->announce(WakeOpcode::Wake);

                return;
            }

            $this->parkOn(true, sprintf('send on shared channel @0x%X', $this->address()));
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
        if ($this->channel->isClosed()) {
            throw ClosedChannelException::onClose();
        }

        // Crosses processes: the closed flag lives in the arena, so every sibling sees it.
        $this->channel->close();

        $this->announce(WakeOpcode::Close, ValueTag::Close);
    }

    public function isClosed(): bool
    {
        return $this->channel->isClosed();
    }

    public function capacity(): int
    {
        return $this->channel->capacity();
    }

    public function count(): int
    {
        return $this->channel->count();
    }

    public function canSend(): bool
    {
        // A closed channel is "ready" to send: the send returns immediately, by throwing. Reporting
        // false would park a select on a channel that can never make progress.
        return $this->channel->isClosed() || $this->channel->count() < $this->channel->capacity();
    }

    public function canRecv(): bool
    {
        return $this->channel->count() > 0 || $this->channel->isClosed();
    }

    /**
     * The wake registry's socket — the descriptor readiness of this channel is signalled through.
     *
     * One descriptor serves every shared primitive of the process, because the socket carries
     * "something changed somewhere" and nothing more. The poller drains it and re-checks; a
     * per-channel descriptor would buy nothing and cost a file descriptor per channel.
     *
     * @return resource
     */
    public function readinessFd()
    {
        return $this->arena->readinessFd();
    }

    public function awaitReceivable(SelectToken $token, int $caseIndex, CoroutineInterface $coroutine): void
    {
        $this->waiters[] = [
            'coroutine' => $coroutine,
            'send'      => false,
            'token'     => $token,
            'case'      => $caseIndex,
            'value'     => null,
        ];
    }

    public function awaitSendable(SelectToken $token, int $caseIndex, CoroutineInterface $coroutine, mixed $value): void
    {
        $this->waiters[] = [
            'coroutine' => $coroutine,
            'send'      => true,
            'token'     => $token,
            'case'      => $caseIndex,
            'value'     => $value,
        ];
    }

    public function cancelWait(SelectToken $token): void
    {
        $this->waiters = array_values(array_filter(
            $this->waiters,
            static fn(array $waiter): bool => $waiter['token'] !== $token,
        ));
    }

    /**
     * Yield values until the channel is closed and drained.
     *
     * @return \Generator<int, mixed, mixed, void>
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
     * Take the next value, parking if there is none yet.
     *
     * Null means the channel is closed and drained — the one thing a bare value cannot express,
     * since null is a perfectly ordinary thing to send.
     *
     * @return Delivery<mixed>|null
     */
    private function receive(): ?Delivery
    {
        while (true) {
            $received = $this->channel->tryRecv();

            if ($received !== null) {
                // A slot just freed up, so a sender parked on a full ring — here or in a sibling —
                // can move in. Announced after the value is out of the ring, never before.
                $this->announce(WakeOpcode::Wake);

                return $received[1] ? new Delivery($received[0]) : null;
            }

            $this->parkOn(false, sprintf('recv on shared channel @0x%X', $this->address()));
        }
    }

    /** How many coroutines of this process are parked on this channel. */
    public function pendingWaiters(): int
    {
        return count($this->waiters);
    }

    /**
     * Serve whoever can now make progress. Called by {@see SharedArena} on every wakeup.
     *
     * Select cases are completed here, because a select must be resolved by exactly one case and the
     * token is what decides which. Plain parked senders and receivers are merely made runnable and
     * retry the operation themselves — a wakeup is a hint, and by the time the coroutine runs
     * another process may have taken the slot it was woken for.
     */
    public function recheck(): void
    {
        if ($this->rechecking) {
            return;
        }

        $this->rechecking = true;

        try {
            $this->serve();
        } finally {
            $this->rechecking = false;
        }
    }

    private function serve(): void
    {
        $scheduler = $this->arena->scheduler();
        $remaining = [];

        foreach ($this->waiters as $waiter) {
            $token = $waiter['token'];

            if ($token === null) {
                if (!($waiter['send'] ? $this->canSend() : $this->canRecv())) {
                    $remaining[] = $waiter;

                    continue;
                }

                if ($waiter['coroutine']->unpark()) {
                    $scheduler->schedule($waiter['coroutine']);
                }

                continue;
            }

            if ($token->isClaimed()) {
                continue;
            }

            $completed = $waiter['send']
                ? $this->completeSelectSend($token, $waiter['case'], $waiter['value'])
                : $this->completeSelectRecv($token, $waiter['case']);

            if (!$completed) {
                $remaining[] = $waiter;

                continue;
            }

            if ($waiter['coroutine']->unpark()) {
                $scheduler->schedule($waiter['coroutine']);
            }
        }

        $this->waiters = $remaining;
    }

    /**
     * The operation happens **before** the claim, deliberately.
     *
     * Claiming first would resolve the select on a send that a sibling process can still make
     * impossible between the claim and the write, and a select that reports a value sent when none
     * was is worse than one that parks a little longer. Nothing suspends between the two lines, so
     * no other case can win in the gap.
     */
    private function completeSelectSend(SelectToken $token, int $caseIndex, mixed $value): bool
    {
        if ($this->channel->isClosed()) {
            // Sending to a closed channel completes immediately, by throwing at the waiter.
            if (!$token->claim($caseIndex)) {
                return false;
            }

            $token->deliver(null, false);

            return true;
        }

        if (!$this->channel->trySend($value)) {
            return false;
        }

        $token->claim($caseIndex);
        $token->deliver(null, true);

        $this->announce(WakeOpcode::Wake);

        return true;
    }

    private function completeSelectRecv(SelectToken $token, int $caseIndex): bool
    {
        $received = $this->channel->tryRecv();

        if ($received === null) {
            return false;
        }

        $token->claim($caseIndex);
        $token->deliver($received[0], $received[1]);

        $this->announce(WakeOpcode::Wake);

        return true;
    }

    /**
     * Park the running coroutine until somebody says this channel changed.
     *
     * Externally wakeable, and truthfully so: the value this coroutine is waiting for may be about
     * to be written by a process that is not this one, so no amount of local scheduling could
     * produce the wakeup and a deadlock report must not count this coroutine as stuck.
     */
    private function parkOn(bool $forSend, string $what): void
    {
        $scheduler = $this->arena->scheduler();
        $coroutine = $scheduler->current() ?? throw new \LogicException(
            sprintf('%s would block, but it was called outside a coroutine', $what),
        );

        $this->waiters[] = [
            'coroutine' => $coroutine,
            'send'      => $forSend,
            'token'     => null,
            'case'      => -1,
            'value'     => null,
        ];

        $coroutine->park($what, true);
        $scheduler->suspend(SuspendCommand::BLOCKED);
    }

    /**
     * Tell the family, then this process, that the ring changed.
     *
     * The local re-check is synchronous rather than a poke to our own wake socket: a process that
     * notified itself would have to come back through the poller for a state change it already
     * knows about, which is exactly the traffic that makes a wakeup count grow with the send count.
     */
    private function announce(WakeOpcode $opcode, ValueTag $tag = ValueTag::Nil): void
    {
        $this->arena->notifyFamily($opcode, $this->channelId(), $tag, $this->address());
        $this->recheck();
    }

    /**
     * The channel id a wake event carries.
     *
     * An arena address does not fit the record's `uint32` id field, so the low 32 bits identify the
     * channel in an event. That is all the field is for: the event says "something changed", and
     * the authoritative state is read from the arena afterwards, never from the id.
     */
    private function channelId(): int
    {
        return $this->address() & 0xFFFFFFFF;
    }
}
