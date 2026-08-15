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
 * A unit of cooperative execution, backed by a native Fiber.
 *
 * # The park/unpark protocol
 *
 * When a coroutine blocks, it is *handed over*: it calls {@see self::park()} to record why it is
 * waiting, suspends with {@see SuspendCommand::BLOCKED} (or SLEEP/IO), and from that moment the
 * primitive it parked on owns it. The scheduler will not touch it again until somebody calls
 * {@see self::unpark()}.
 *
 * **Unparking is idempotent, and the first caller wins.** This is not a convenience — it is what
 * makes `select` correct. A coroutine in a `select` is parked on several channels at once, and
 * whichever one becomes ready first unparks it; the losing channels may still try to unpark it
 * afterwards (they have not been unlinked yet, or a value arrived in the same tick). Those late
 * calls must be harmless no-ops that return false, never a second scheduling of an already-running
 * coroutine.
 */
interface CoroutineInterface
{
    /** Process-unique identifier, stable for the coroutine's whole life. */
    public function id(): int;

    public function status(): CoroutineStatus;

    /**
     * Record that this coroutine is about to block, and on what.
     *
     * The description is used verbatim in the deadlock dump, so it should name the primitive and
     * the operation ("recv on channel #3", "wait on WaitGroup #1"), not just its type.
     *
     * @param bool $externallyWakeable True when the wakeup can only come from outside this
     *                                 process — a shared channel, a result slot, an IO readiness
     *                                 event. Such a coroutine is never counted as "asleep" by
     *                                 deadlock detection, because no amount of local scheduling
     *                                 could have woken it.
     */
    public function park(string $waitDescription, bool $externallyWakeable = false): void;

    /**
     * Make a parked coroutine runnable again.
     *
     * Idempotent: returns true only for the call that actually performed the transition, false for
     * every later call. Safe to call from any primitive, and safe to call on a coroutine that has
     * already been unparked, has already finished, or was never parked.
     */
    public function unpark(): bool;

    /**
     * Whether this coroutine's wakeup can only come from outside the process.
     *
     * Deadlock detection excludes these: see {@see self::park()}.
     */
    public function isExternallyWakeable(): bool;

    /** What this coroutine is waiting on, or null when it is not parked. */
    public function waitDescription(): ?string;

    /** Where the coroutine was spawned, as "file:line" — the anchor for a deadlock report. */
    public function spawnLocation(): string;
}
