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

namespace Lisachenko\NativePhpCoroutines\Internal;

use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\SelectToken;

/**
 * One parked coroutine's place in a channel's sender or receiver queue.
 *
 * The node — not the scheduler's resume value — is where a handoff lands. A waker writes the
 * outcome into the node *before* it unparks anybody, so the parked coroutine finds the result
 * already in place when it runs again. That keeps the primitives independent of how a scheduler
 * chooses to resume a fiber, and it is what makes a rendezvous a genuine direct handoff instead of
 * a round trip through the run queue.
 *
 * The single {@see Delivery} slot means one thing for each direction: for a parked sender it is the
 * value it brought and is waiting to give away, and for a parked receiver it is the value it has
 * been handed — empty until somebody arrives, and still empty if the channel closes first.
 *
 * A node created for a `select` case carries the shared {@see SelectToken}. Such a node may only be
 * acted on after {@see self::tryClaim()} returns true: the token is one-shot, so the first channel
 * to claim it owns the wakeup and every other channel must leave the coroutine alone.
 *
 * @template T
 */
final class WaitNode
{
    /** @var Delivery<T>|null */
    private ?Delivery $delivery;

    private bool $completed = false;

    private bool $closed = false;

    /**
     * @param Delivery<T>|null $delivery The value a parked sender is handing over; nothing for a
     *                                   receiver, which is waiting to be given one.
     */
    public function __construct(
        public readonly CoroutineInterface $coroutine,
        ?Delivery $delivery,
        public readonly ?SelectToken $token = null,
        public readonly int $caseIndex = 0,
    ) {
        $this->delivery = $delivery;
    }

    /**
     * Whether this node could still be completed, without committing to it.
     *
     * Used by the non-parking polls (`canSend()`/`canRecv()`): a node whose select has already been
     * won elsewhere is a tombstone that happens to still be linked, and must not be counted as a
     * peer that a send or a receive could pair with.
     */
    public function isLive(): bool
    {
        return !$this->completed && !$this->token?->isClaimed();
    }

    /**
     * Take ownership of this waiter, or report that somebody else already has.
     *
     * For a plain node this is just "not completed yet". For a select node it is the token claim —
     * exactly one case of a select may ever complete.
     */
    public function tryClaim(): bool
    {
        if ($this->completed) {
            return false;
        }

        return $this->token?->claim($this->caseIndex) ?? true;
    }

    /**
     * Hand a value to this parked receiver.
     *
     * @param Delivery<T> $delivery
     */
    public function completeWith(Delivery $delivery): void
    {
        $this->delivery  = $delivery;
        $this->completed = true;
        $this->token?->deliver($delivery->value(), true);
    }

    /**
     * Report to this parked sender that its value has been taken.
     *
     * The delivery slot is left alone: it is the sender's own payload, and whoever took it is
     * holding it now.
     */
    public function completeSent(): void
    {
        $this->completed = true;
        $this->token?->deliver(null, true);
    }

    /**
     * Report that the channel closed underneath this waiter.
     *
     * A parked sender woken this way throws — its value is never delivered; a parked receiver finds
     * an empty slot, which is exactly what an exhausted channel means.
     */
    public function completeClosed(): void
    {
        $this->delivery  = null;
        $this->closed    = true;
        $this->completed = true;
        $this->token?->deliver(null, false);
    }

    /**
     * What this node carries: a sender's payload, or what a receiver was handed.
     *
     * @return Delivery<T>|null
     */
    public function delivery(): ?Delivery
    {
        return $this->delivery;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
