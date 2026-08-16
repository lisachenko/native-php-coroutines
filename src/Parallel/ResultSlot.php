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
 *
 * # The three fields that end its two lifecycles
 *
 * The local view here and the shared record in the substrate both have to end, and they end at
 * different moments for different reasons. {@see self::$claims} decides *when* — it is the count of
 * handles in this process that can still ask for the result — and these two decide *what* is ended
 * when it reaches zero:
 *
 * - {@see self::$owned} — this process allocated the slot. Only an owner gives the shared record
 *   back; a process that attached to somebody else's slot has no idea who else is still reading it.
 * - {@see self::$sharedSettled} — whether the *shared* record really settled, as opposed to this
 *   process having decided the answer locally. A slot failed because its worker died is complete
 *   here and still pending there, and recycling it would hand a live record to the next task while
 *   a zombie worker may yet write to it. Those records stay out of circulation on purpose.
 *
 * Neither field affects the local view: that goes when the last claim does, whatever the role.
 */
final class ResultSlot
{
    public bool $complete = false;

    /** Whether the authoritative shared record settled, rather than this process giving up on it. */
    public bool $sharedSettled = false;

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

    /**
     * How many {@see JoinHandle}s in this process can still ask for this result.
     *
     * The local view is bookkeeping, not the answer: the answer is in shared memory and stays there.
     * So the view is worth keeping only while somebody here can still read it, and the count is what
     * says so — one claim per handle, given back when the handle is awaited or collected, whichever
     * comes first. When the last claim goes and the slot has settled, {@see SlotTable} forgets it,
     * which is what keeps a steady-state spawn/await loop from retaining a `ResultSlot` per spawn
     * for the life of the run.
     *
     * The same moment ends the **shared** record's life, but only when {@see self::$owned} and
     * {@see self::$sharedSettled} both allow it: slots are recycled through the substrate's free
     * list, and giving one back is a claim that nobody will read it through this ticket again.
     */
    public int $claims = 0;

    public function __construct(
        public readonly int $id,
        public readonly int $workerId,
        public readonly bool $owned = true,
    ) {}
}
