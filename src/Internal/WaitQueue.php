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
 * A strictly FIFO queue of parked coroutines.
 *
 * FIFO is not a stylistic choice: a LIFO queue starves the coroutine that has been waiting longest,
 * which is exactly the producer or consumer a busy channel can least afford to lose.
 *
 * The queue also owns the tombstone problem. A `select` parks one coroutine on several channels at
 * once, and only the winner's node is unlinked by the channel that woke it; the rest are unlinked
 * by {@see self::cancelToken()} when the select resolves. Between those two moments the losing
 * nodes are still linked but no longer claimable, so every read here skips nodes that cannot be
 * claimed rather than assuming the head is usable.
 *
 * @template T
 */
final class WaitQueue
{
    /**
     * Insertion-ordered, which is what makes this FIFO; keys are never reused.
     *
     * @var array<int, WaitNode<T>>
     */
    private array $nodes = [];

    private int $nextKey = 0;

    /**
     * Park a coroutine at the tail of the queue.
     *
     * @param  Delivery<T>|null $delivery The sender's payload, or null for a receiver that is
     *                                    waiting to be handed one.
     * @return WaitNode<T>               The caller's own node: it reads its outcome from here once
     *                                   it is woken.
     */
    public function enqueue(
        CoroutineInterface $coroutine,
        ?Delivery $delivery,
        ?SelectToken $token = null,
        int $caseIndex = 0,
    ): WaitNode {
        $node = new WaitNode($coroutine, $delivery, $token, $caseIndex);

        $this->nodes[$this->nextKey++] = $node;

        return $node;
    }

    /**
     * Unlink and return the oldest waiter this caller may act on.
     *
     * Nodes that cannot be claimed are dropped on the way: they belong to a select that has already
     * been won elsewhere, so nobody will ever complete them here.
     *
     * @return WaitNode<T>|null
     */
    public function claimNext(): ?WaitNode
    {
        while (($key = array_key_first($this->nodes)) !== null) {
            $node = $this->nodes[$key];
            unset($this->nodes[$key]);

            if ($node->tryClaim()) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Whether a waiter exists that a send or a receive could pair with right now.
     *
     * Does not claim anything — this backs `canSend()`/`canRecv()`, which must stay side effect
     * free so a `select` can poll every case before committing to one.
     */
    public function hasLive(): bool
    {
        foreach ($this->nodes as $node) {
            if ($node->isLive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Empty the queue, handing back every node in FIFO order.
     *
     * Used by `close()`, which has to reach every waiter in one pass.
     *
     * @return list<WaitNode<T>>
     */
    public function drain(): array
    {
        $nodes       = array_values($this->nodes);
        $this->nodes = [];

        return $nodes;
    }

    /**
     * Unlink every node belonging to one select.
     *
     * This is the anti-leak operation: skip it and a `select` in a loop grows the losing channels'
     * queues on every iteration, and a later send hands a value to a coroutine that has moved on.
     */
    public function cancelToken(SelectToken $token): void
    {
        foreach ($this->nodes as $key => $node) {
            if ($node->token === $token) {
                unset($this->nodes[$key]);
            }
        }
    }

    public function count(): int
    {
        return count($this->nodes);
    }
}
