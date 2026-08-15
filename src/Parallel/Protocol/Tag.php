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
 * | CLOSURE            | arena provenance record| zero-copy; pre-fork closures only                 |
 *
 * # The numbers are the wire contract
 *
 * These cases are numerically identical to the substrate's `Lisachenko\SharedData\Ipc\ValueTag`,
 * which states that tag numbers are this runtime's wire contract and are never renumbered. A record
 * written by the substrate is read by this enum and the other way round, so the two enums are one
 * table with two spellings — `NIL = 0 … CLOSE = 8`, and `CLOSURE = 9` appended by the substrate's
 * pre-fork closure support. Renumbering either side silently reinterprets every record in flight.
 *
 * A plain `zend_array` is deliberately absent: the engine grows a HashTable's storage through
 * `pemalloc` into process-local heap, with no hook to redirect it, so a shared plain array would
 * silently acquire memory that its siblings cannot see. `SharedArray` exists for exactly that
 * reason. The substrate spikes sharpened *why* this is non-negotiable: a growing table writes the
 * private-heap `arData` pointer into the shared struct **before** it aborts, so siblings go on
 * reading plausible garbage with no signal at all. Silent corruption, not a crash.
 *
 * # What "zero-copy" does not mean
 *
 * `OBJ` being zero-copy means the address *is* the value — not that touching it is free of rules:
 *
 * - **Never `var_dump()`, `json_encode()`, `get_object_vars()` or `(array)` a shared object**
 *   unless the extension's `get_properties_for` interception is active. Those read-shaped
 *   operations make engine C code *write* a per-process `properties` pointer into the shared
 *   struct, which segfaults every sibling that reads it afterwards. This applies to the runtime's
 *   own diagnostics — a panic trace or a debug dump is exactly the kind of code that reaches for
 *   `var_dump()` on the value it is reporting.
 * - **Never key a shared object by `spl_object_id()` or put one in an `SplObjectStorage`.** Forked
 *   children inherit the same object-store free list and hand out *identical* handle numbers, so
 *   handles collide by construction. Arena address is the only identity that means anything across
 *   processes.
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

    /**
     * A closure the arena-owning process registered **before** the fork barrier.
     *
     * The payload is the address of its provenance record, never of the closure object: acceptance
     * is a table lookup on a pre-fork registration and never an inspection of the object. A
     * post-fork closure at a stale address is indistinguishable by shape from a legitimate one —
     * the substrate spikes found such an address holding a different, perfectly valid `Closure`
     * that on PHP 8.5 executed the *wrong function*.
     */
    case CLOSURE = 9;

    /** Whether the payload is an arena address rather than an inline value. */
    public function isAddress(): bool
    {
        return match ($this) {
            self::STR, self::OBJ, self::ARR, self::CLOSURE => true,
            default                                        => false,
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
