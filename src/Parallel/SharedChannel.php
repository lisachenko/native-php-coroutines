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
 * # Capacity 0 is a real cross-process rendezvous
 *
 * The substrate's handshake gates a capacity-0 handoff on "is a receiver waiting", and it used to
 * count only receivers parked inside *its* blocking `recv()` — which this runtime never calls. The
 * substrate now names the other half: `registerReceiver()` announces a receiver that is parked in
 * the consumer's own event loop, and `cancelReceiver()` withdraws it. This class registers exactly
 * once per process while any local coroutine is waiting to receive, and withdraws the moment the
 * last one is gone, so a sibling's `trySend()` has a partner precisely while this process has one.
 *
 * A rendezvous `send()` is over when the value has been **taken**, not when it was deposited: the
 * deposit hands back a ticket, and the sender parks a second time until `isTicketTaken()`. That is
 * what makes the semantics honest under cancellation — a registration that is withdrawn between the
 * deposit and the take leaves the record in the ring for the next receiver, and the sender simply
 * goes on waiting, exactly as it would have if it had never found a partner. Nothing is lost, and
 * nothing is delivered twice.
 *
 * The one thing a rendezvous cannot do here is **lose a `select` race as a send case**. A select
 * case has to resolve without parking, and the only point at which a rendezvous send could be
 * declared complete without parking is the deposit — one step too early, since the partner it was
 * deposited against may still walk away. Rather than quietly downgrading such a case to buffered
 * semantics, {@see self::awaitSendable()} refuses it and names the two remedies. Receive cases are
 * unaffected and compose with local channels as usual.
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
     * `ticket` belongs to a sender that already deposited a rendezvous record and is waiting for it
     * to be taken — a different readiness question from "is there room", and the only one that can
     * still be answered once the ring is full of this very sender's handoff.
     *
     * @var list<array{
     *     coroutine: CoroutineInterface,
     *     send: bool,
     *     token: SelectToken|null,
     *     case: int,
     *     value: mixed,
     *     ticket: int|null,
     * }>
     */
    private array $waiters = [];

    private bool $rechecking = false;

    /**
     * This process's rendezvous registration in the substrate, while it has a waiting receiver.
     *
     * One per process rather than one per coroutine: the registration says "somebody here is ready
     * to take a value", and the wake slot it is filed under is a property of the process anyway.
     */
    private ?int $receiverToken = null;

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

            // Encoding happens inside trySendTicket(), outside every lock: interning a string
            // allocates arena memory and a value that cannot be shared must throw before a lock is
            // taken.
            $ticket = $this->channel->trySendTicket($value);

            if ($ticket !== null) {
                $this->announce(WakeOpcode::Wake);

                if ($this->isRendezvous()) {
                    $this->awaitHandoff($ticket);
                }

                return;
            }

            $this->parkOn(true, null, sprintf('send on shared channel @0x%X', $this->address()));
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
        if ($this->channel->isClosed()) {
            return true;
        }

        // A rendezvous send completes when the value has been TAKEN, and nothing observable right
        // now can make that already true — even with a partner registered, the take is a second
        // event that only a park can wait for. Answering anything else here would put `select`'s
        // non-parking fast path into a send that parks.
        if ($this->isRendezvous()) {
            return false;
        }

        return $this->channel->count() < $this->channel->capacity();
    }

    public function canRecv(): bool
    {
        return $this->channel->count() > 0 || $this->channel->isClosed();
    }

    /** Whether this channel hands values over directly rather than buffering them. */
    public function isRendezvous(): bool
    {
        return $this->channel->capacity() === 0;
    }

    /**
     * Whether any process of the family has a receiver waiting for a handoff right now.
     *
     * The gate a capacity-0 send passes, readable from anywhere: it is the shared count, not this
     * process's waiter list, so a registration left behind by a select loser in *another* process
     * shows up here too. That is what makes "no stale registration survives" testable across
     * processes rather than only locally.
     */
    public function hasWaitingReceiver(): bool
    {
        return $this->channel->parkedReceivers() > 0;
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
            'ticket'    => null,
        ];

        $this->syncRegistration();
    }

    public function awaitSendable(SelectToken $token, int $caseIndex, CoroutineInterface $coroutine, mixed $value): void
    {
        // A select case must resolve without parking, and a rendezvous send has no such moment: the
        // deposit is the earliest point it could claim, and the partner it was deposited against
        // can still walk away before taking the value. Reporting "sent" there would silently give
        // this one case buffered semantics while send() on the same channel keeps rendezvous ones.
        if ($this->isRendezvous() && !$this->channel->isClosed()) {
            throw new \LogicException(sprintf(
                'a capacity-0 shared channel cannot be a select send case: a rendezvous send '
                . 'completes when the value is TAKEN, which a case cannot wait for without parking. '
                . 'Declare the channel with a capacity of at least 1, or drive the handoff from a '
                . 'coroutine of its own that calls send() on shared channel @0x%X',
                $this->address(),
            ));
        }

        $this->waiters[] = [
            'coroutine' => $coroutine,
            'send'      => true,
            'token'     => $token,
            'case'      => $caseIndex,
            'value'     => $value,
            'ticket'    => null,
        ];
    }

    public function cancelWait(SelectToken $token): void
    {
        $this->waiters = array_values(array_filter(
            $this->waiters,
            static fn(array $waiter): bool => $waiter['token'] !== $token,
        ));

        // The losing case of a select is exactly the stale registration this has to avoid: a
        // withdrawn waiter that still tells a sibling process a partner is present would make the
        // next send deposit a value nobody in this process is coming for.
        $this->syncRegistration();
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

            $this->parkOn(false, null, sprintf('recv on shared channel @0x%X', $this->address()));
        }
    }

    /**
     * The second half of a rendezvous send: wait until somebody has actually taken the record.
     *
     * The ticket is the ring position the value was deposited at, and the substrate reports it
     * taken once its monotonic head has passed it — which is true no matter *who* took it. That is
     * the whole reason this runtime does not need the deposit to bind one particular receiver: if
     * the registration it was deposited against is withdrawn a moment later, the record stays in
     * the ring, the next receiver completes the handshake, and this park simply lasts longer.
     */
    private function awaitHandoff(int $ticket): void
    {
        while (!$this->channel->isTicketTaken($ticket)) {
            if ($this->channel->isClosed()) {
                // Closed with the handoff still in the ring: no receiver is coming for it, and a
                // sender that waits for a take that cannot happen is a hang, not a rendezvous.
                throw ClosedChannelException::whileParked();
            }

            $this->parkOn(true, $ticket, sprintf('handoff on shared channel @0x%X', $this->address()));
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

        // The coroutine running right now is on its way INTO a park and has not suspended yet:
        // unpark() would report nothing to do and a claimed select token would strand it with no
        // scheduler entry. Skipping it is safe rather than merely cautious — the only thing that
        // can have made it eligible in this window is another process, and that process's wake
        // event is already sitting in this process's socket, so the poller re-runs this pass the
        // moment the coroutine suspends. A local change cannot happen in the window at all: there
        // is no suspension point between the caller's own poll and its park.
        $current = $scheduler->current();

        foreach ($this->waiters as $waiter) {
            if ($waiter['coroutine'] === $current) {
                $remaining[] = $waiter;

                continue;
            }

            $token = $waiter['token'];

            if ($token === null) {
                if (!$this->canProceed($waiter['send'], $waiter['ticket'])) {
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

        $this->syncRegistration();
    }

    /**
     * Whether a plain parked waiter can retry its operation now.
     *
     * Deliberately not {@see self::canSend()}: that answers `select`'s question ("would a send
     * complete without parking?"), which is always no on a rendezvous channel. A parked sender asks
     * the narrower one — can the value be *deposited* — and a sender that already deposited asks
     * the narrower one still, whether its own record has been taken.
     */
    private function canProceed(bool $forSend, ?int $ticket): bool
    {
        if (!$forSend) {
            return $this->canRecv();
        }

        if ($ticket !== null) {
            return $this->channel->isTicketTaken($ticket) || $this->channel->isClosed();
        }

        if ($this->channel->isClosed()) {
            return true;
        }

        if ($this->isRendezvous()) {
            // The gate the substrate applies to the deposit itself, asked before parking again:
            // an empty handoff slot and a receiver waiting somewhere in the family.
            return $this->channel->count() === 0 && $this->channel->parkedReceivers() > 0;
        }

        return $this->channel->count() < $this->channel->capacity();
    }

    /**
     * Keep the substrate registration in step with whether this process has a waiting receiver.
     *
     * Derived from the waiter list rather than counted up and down, because a count that drifts is
     * exactly the stale registration this exists to prevent: one extra decrement closes a gate that
     * should be open, one missing one leaves a sibling depositing values for a coroutine that has
     * long since moved on. Only a rendezvous channel needs it — a buffered send is gated by room in
     * the ring, and registering there would buy nothing but a lock per park.
     */
    private function syncRegistration(): void
    {
        if (!$this->isRendezvous()) {
            return;
        }

        $wanted = false;
        foreach ($this->waiters as $waiter) {
            if (!$waiter['send']) {
                $wanted = true;

                break;
            }
        }

        if ($wanted === ($this->receiverToken !== null)) {
            return;
        }

        if (!$wanted) {
            $token               = $this->receiverToken;
            $this->receiverToken = null;
            $this->channel->cancelReceiver((int) $token);

            return;
        }

        // Registering re-checks readiness inside the substrate's own critical section, so a record
        // that arrived in the meantime is reported instead of registered for — and then nobody has
        // to be woken for it, because whoever is parked here can be served on the spot.
        $token = $this->channel->registerReceiver();

        if ($token === null) {
            $this->recheck();

            return;
        }

        $this->receiverToken = $token;

        // On a rendezvous channel the registration IS the state change a sender waits for: no
        // record was published and no room was freed, so nothing else would ever tell it.
        $this->announce(WakeOpcode::Wake);
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
    private function parkOn(bool $forSend, ?int $ticket, string $what): void
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
            'ticket'    => $ticket,
        ];

        $coroutine->park($what, true);

        // Registered before the suspend, and the registration re-checks readiness inside the
        // channel's own critical section: a sibling that deposits after this point necessarily
        // sees the entry, so the wakeup cannot be lost.
        $this->syncRegistration();

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
