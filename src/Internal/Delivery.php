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

/**
 * One value in transit between two coroutines.
 *
 * A channel has to distinguish "a value arrived" from "there is nothing and there never will be
 * again", and it cannot do that with the value alone: null is a perfectly ordinary thing to send.
 * Wrapping the value turns that distinction into the presence or absence of an object, so the two
 * cases stop competing for the same slot — and a channel of `T` keeps handing back a `T`, rather
 * than a `T|null` that every caller has to re-check.
 *
 * Immutable, and carried by reference: a delivery is created by the sending side and read by the
 * receiving side, never modified in between.
 *
 * @template T
 */
final class Delivery
{
    /**
     * @param T $value
     */
    public function __construct(private readonly mixed $value) {}

    /** @return T */
    public function value(): mixed
    {
        return $this->value;
    }
}
