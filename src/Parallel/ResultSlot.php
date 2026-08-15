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
 * # Seam for ticket #7
 *
 * The real slot is `{state, 16-byte tagged record}` **in the shared arena**, so that any process in
 * the tree can complete it and any process can read it. This class is that slot's parent-side half:
 * the identity, the owning worker, the completion state and the coroutines parked on it. Nothing
 * here is on the wire — the `RESULT` record names a slot and carries the tagged value, and this is
 * where the parent puts it down.
 *
 * When the arena lands, {@see self::$value} and {@see self::$complete} move into shared memory under
 * the publication order the tagged-record contract spells out (payload first, tag last), and the
 * waiter list stays here, because a parked coroutine is a per-process thing.
 */
final class ResultSlot
{
    public bool $complete = false;

    /** The outcome, once complete and successful. */
    public ?TaggedRecord $value = null;

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
