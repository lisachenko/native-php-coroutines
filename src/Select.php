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
use Lisachenko\NativePhpCoroutines\Internal\SelectCase;

/**
 * Wait on several channel operations at once, and take the first one that can proceed.
 *
 *     $winner = Select::on($scheduler)
 *         ->recv($jobs, fn(mixed $job, bool $ok): string => $ok ? "job {$job}" : 'jobs closed')
 *         ->recv($ctx->done(), fn(): string => 'cancelled')
 *         ->send($results, $value, fn(): string => 'result queued')
 *         ->run();
 *
 * Everything here is expressed against {@see ChannelInterface}, which is the point: a local channel
 * and a shared, cross-process one can appear in the same statement.
 *
 * # Why the cases are shuffled
 *
 * Ready cases are polled in a random order. Without that, a `select` in a loop with two permanently
 * ready channels would always take the one written first and the other would never run — the
 * starvation is total, not statistical. Registration order is shuffled too, so a case is not
 * privileged when several channels become ready later either.
 *
 * # Why the losers are unlinked
 *
 * Parking registers a waiter on *every* case. Once one of them wins, the rest are stale nodes
 * pointing at a coroutine that has moved on. {@see ChannelInterface::cancelWait()} is called on all
 * of them before this returns, so a `select` inside a loop leaves nothing behind.
 */
final class Select
{
    /** @var list<SelectCase> */
    private array $cases = [];

    /** @var (\Closure(): mixed)|null */
    private ?\Closure $default = null;

    public function __construct(private readonly SchedulerInterface $scheduler) {}

    public static function on(SchedulerInterface $scheduler): self
    {
        return new self($scheduler);
    }

    /**
     * Take a value from $channel if one can be taken.
     *
     * @param ChannelInterface<mixed>        $channel
     * @param \Closure(mixed, bool): mixed   $handler Receives the value and the liveness flag, the
     *                                                same pair {@see ChannelInterface::recvOk()}
     *                                                reports — a closed, drained channel wins the
     *                                                select with `ok = false`.
     */
    public function recv(ChannelInterface $channel, \Closure $handler): self
    {
        $this->cases[] = new SelectCase($channel, false, null, $handler);

        return $this;
    }

    /**
     * Hand $value to $channel if it can be handed over.
     *
     * @param ChannelInterface<mixed> $channel
     * @param \Closure(): mixed       $handler
     */
    public function send(ChannelInterface $channel, mixed $value, \Closure $handler): self
    {
        $this->cases[] = new SelectCase($channel, true, $value, $handler);

        return $this;
    }

    /**
     * What to do when no case is ready, instead of parking.
     *
     * @param \Closure(): mixed $handler
     */
    public function default(\Closure $handler): self
    {
        $this->default = $handler;

        return $this;
    }

    /**
     * Resolve the select and return whatever the winning handler returned.
     *
     * @throws ClosedChannelException When a parked send case was woken by the channel closing.
     */
    public function run(): mixed
    {
        if ($this->cases === []) {
            if ($this->default !== null) {
                return ($this->default)();
            }

            throw new \LogicException('A select without cases and without a default would block forever');
        }

        $order = array_keys($this->cases);
        shuffle($order);

        foreach ($order as $index) {
            if ($this->cases[$index]->isReady()) {
                return $this->take($this->cases[$index]);
            }
        }

        if ($this->default !== null) {
            return ($this->default)();
        }

        return $this->park($order);
    }

    /** Whether any case's readiness is decided outside this process. */
    private function hasExternalCase(): bool
    {
        foreach ($this->cases as $case) {
            if ($case->channel->readinessFd() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Complete a case that the poll already proved ready, so nothing here can park.
     */
    private function take(SelectCase $case): mixed
    {
        if ($case->isSend) {
            // May still throw, and must: `canSend()` reports true for a closed channel precisely
            // because the send completes immediately by throwing.
            $case->channel->send($case->value);

            return ($case->handler)();
        }

        [$value, $ok] = $case->channel->recvOk();

        return ($case->handler)($value, $ok);
    }

    /**
     * Register on every case with one shared token, block, and let the first waker win.
     *
     * @param list<int> $order Registration order, already shuffled.
     */
    private function park(array $order): mixed
    {
        $coroutine = $this->scheduler->current();
        if ($coroutine === null) {
            throw new \LogicException('A select would block, but it was called outside a coroutine');
        }

        $token = new SelectToken();
        foreach ($order as $index) {
            $case = $this->cases[$index];
            if ($case->isSend) {
                $case->channel->awaitSendable($token, $index, $coroutine, $case->value);
            } else {
                $case->channel->awaitReceivable($token, $index, $coroutine);
            }
        }

        // Externally wakeable as soon as one case is a cross-process channel: the value this select
        // is waiting for may be written by a process that is not this one, so no amount of local
        // scheduling could produce the wakeup and a deadlock report must not count this coroutine.
        // A select over purely local channels stays locally wakeable, which is what keeps the
        // Layer 1 deadlock report honest.
        $coroutine->park(
            sprintf('select on %d channels', count($this->cases)),
            $this->hasExternalCase(),
        );
        $this->scheduler->suspend(SuspendCommand::BLOCKED);

        // Unlink before touching the result: every case is asked, including the winner's channel,
        // because cancelling a waiter that is already gone is a no-op and forgetting one is a leak.
        foreach ($this->cases as $case) {
            $case->channel->cancelWait($token);
        }

        $winner = $token->winner();
        if ($winner === null) {
            throw new \LogicException('A select was resumed without any case having claimed it');
        }

        $case = $this->cases[$winner];
        if (!$case->isSend) {
            return ($case->handler)($token->value(), $token->ok());
        }

        // A send case only reports ok = false when the channel closed underneath it, which is the
        // parked-sender error, not a value.
        if (!$token->ok()) {
            throw ClosedChannelException::whileParked();
        }

        return ($case->handler)();
    }
}
