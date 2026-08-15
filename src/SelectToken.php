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
 * One-shot claim ticket shared by every case of a single `select`.
 *
 * A selecting coroutine parks on all of its cases at once. Exactly one of them may complete, so
 * before a channel acts on a waiter it must {@see self::claim()} the token: the first caller gets
 * true and owns the wakeup, everyone else gets false and must leave the coroutine alone.
 *
 * Claiming and unparking are deliberately separate. The token decides *who won*; unparking makes
 * the coroutine runnable. A channel that wins must then unlink the losing waiters, or their wait
 * nodes accumulate — a `select` in a loop would grow the losing channels' queues without bound and
 * eventually resume a coroutine that has long since moved on.
 */
final class SelectToken
{
    private bool $claimed = false;

    /** Index of the winning case, or null while the token is unclaimed. */
    private ?int $winner = null;

    private mixed $value = null;

    private bool $ok = false;

    /**
     * Try to win this select on behalf of case $caseIndex.
     *
     * Returns true exactly once, for the first caller. Every later call returns false, including
     * calls for the case that already won.
     */
    public function claim(int $caseIndex): bool
    {
        if ($this->claimed) {
            return false;
        }

        $this->claimed = true;
        $this->winner  = $caseIndex;

        return true;
    }

    public function isClaimed(): bool
    {
        return $this->claimed;
    }

    /** The index of the case that won, or null if the select has not resolved yet. */
    public function winner(): ?int
    {
        return $this->winner;
    }

    /**
     * Hand the winning case's outcome to the selecting coroutine.
     *
     * Called by the channel that just won the {@see self::claim()}, before it unparks the waiter,
     * so the value is already in place when the coroutine resumes. For a send case the value is
     * irrelevant and only $ok matters; for a receive case $ok is false when the channel was closed
     * and drained, mirroring {@see ChannelInterface::recvOk()}.
     */
    public function deliver(mixed $value, bool $ok): void
    {
        $this->value = $value;
        $this->ok    = $ok;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function ok(): bool
    {
        return $this->ok;
    }
}
