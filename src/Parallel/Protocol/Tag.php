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

namespace Lisachenko\NativePhpCoroutines\Parallel\Protocol;

/**
 * What the payload of a 16-byte record means.
 *
 * This enum *is* the value contract of the Never-Serialize Rule. Everything that may cross a worker
 * boundary is one of these tags; anything that is not throws
 * {@see \Lisachenko\NativePhpCoroutines\Exception\NotShareableValueException}.
 *
 * | Tag                | Payload                | Cost                                              |
 * |--------------------|------------------------|---------------------------------------------------|
 * | NIL / TRUE / FALSE | none                   | zero — the tag is the value                       |
 * | INT / FLOAT        | the value, inline      | zero                                              |
 * | STR                | arena `zend_string*`   | one structural memcpy into the arena at send      |
 * | OBJ                | arena `zend_object*`   | zero-copy: the address is the value               |
 * | ARR                | arena `SharedArray*`   | zero-copy                                         |
 * | CLOSE              | none                   | ring / protocol control                           |
 *
 * A plain `zend_array` is deliberately absent: the engine grows a HashTable's storage through
 * `pemalloc` into process-local heap, with no hook to redirect it, so a shared plain array would
 * silently acquire memory that its siblings cannot see. `SharedArray` exists for exactly that
 * reason.
 */
enum Tag: int
{
    case NIL   = 0;
    case TRUE  = 1;
    case FALSE = 2;
    case INT   = 3;
    case FLOAT = 4;
    case STR   = 5;
    case OBJ   = 6;
    case ARR   = 7;
    case CLOSE = 8;

    /** Whether the payload is an arena address rather than an inline value. */
    public function isAddress(): bool
    {
        return match ($this) {
            self::STR, self::OBJ, self::ARR => true,
            default                         => false,
        };
    }

    /** Whether the tag alone carries the whole value, leaving the payload unused. */
    public function isPayloadless(): bool
    {
        return match ($this) {
            self::NIL, self::TRUE, self::FALSE, self::CLOSE => true,
            default                                         => false,
        };
    }
}
