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

use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;

/**
 * One outstanding result, as the waiting process sees it.
 *
 * # What moved into shared memory, and what did not
 *
 * With an arena the authoritative slot is `{state, tag, payload, waiter table}` **in shared
 * memory**, owned by the substrate's `ResultSlotTable`: any process in the family can settle it and
 * any process can read it. This class is that slot's per-process half — the identity, the owning
 * worker, the *local* view of completion, and the coroutines parked on it. A parked coroutine is a
 * per-process thing and stays here by construction.
 *
 * {@see self::$decoded} is the value read out of the shared slot, materialized after the slot's
 * lock was released. {@see self::$value} is the record path used when there is no arena, where the
 * whole value is complete inside sixteen bytes. Exactly one of the two is ever set.
 *
 * Nothing here is on the wire: a `RESULT` record names a slot and says "settled", and the value is
 * read from the arena rather than from the socket.
 */
final class ResultSlot
{
    public bool $complete = false;

    /** The outcome as a record, once complete and successful — the path that needs no arena. */
    public ?TaggedRecord $value = null;

    /** The outcome read out of the shared slot; only meaningful with {@see self::$hasDecoded}. */
    public mixed $decoded = null;

    /** Whether {@see self::$decoded} holds the answer, as opposed to {@see self::$value}. */
    public bool $hasDecoded = false;

    /** The outcome, once complete and failed: a task panic or the death of its worker. */
    public ?\Throwable $error = null;

    /**
     * Coroutines parked on this slot, in arrival order.
     *
     * @var list<CoroutineInterface>
     */
    public array $waiters = [];

    public function __construct(
        public readonly int $id,
        public readonly int $workerId,
    ) {}
}
