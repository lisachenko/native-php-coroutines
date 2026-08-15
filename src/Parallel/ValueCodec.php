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

use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;

/**
 * The PHP value <-> {@see TaggedRecord} mapping, for the values that need no arena.
 *
 * `null`, `true`, `false`, `int` and `float` are complete inside the sixteen bytes: the tag is the
 * type and the payload is the value. Those cross a worker boundary today, with nothing allocated
 * anywhere and nothing serialized.
 *
 * `string`, `array` and `object` are deliberately *not* handled. Their records carry an arena
 * address, and the arena lands with ticket #7 — so this class refuses them with a `LogicException`
 * naming that ticket. That refusal is the correct behaviour, not a gap to be papered over: adding a
 * `serialize()`/`json_encode()` fallback here would put value bytes on the control socket and break
 * the Never-Serialize Rule for every user of the runtime at once.
 */
final class ValueCodec
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * The arena tag a value would need, or null when the value fits inline.
     *
     * Callers use this to decide *before* encoding whether a value can travel at all, which lets a
     * worker report "this needs the arena" as a structured outcome instead of a thrown string.
     */
    public static function arenaTagFor(mixed $value): ?Tag
    {
        return match (true) {
            is_string($value) => Tag::STR,
            is_array($value)  => Tag::ARR,
            is_object($value) => Tag::OBJ,
            default           => null,
        };
    }

    /**
     * Encode a value that fits inline.
     *
     * @throws \LogicException When the value needs an arena address (see #7).
     */
    public static function toRecord(mixed $value): TaggedRecord
    {
        $arenaTag = self::arenaTagFor($value);

        if ($arenaTag !== null) {
            throw self::needsArena($arenaTag);
        }

        return match (true) {
            $value === null  => TaggedRecord::nil(),
            is_bool($value)  => TaggedRecord::bool($value),
            is_int($value)   => TaggedRecord::int($value),
            is_float($value) => TaggedRecord::float($value),
            default          => throw new \LogicException(sprintf(
                'a value of type %s cannot cross a worker boundary at all',
                get_debug_type($value),
            )),
        };
    }

    /**
     * Decode a record back into the PHP value it stands for.
     *
     * @throws \LogicException When the record points into the arena (see #7).
     */
    public static function fromRecord(TaggedRecord $record): mixed
    {
        if ($record->tag->isAddress()) {
            throw self::needsArena($record->tag);
        }

        $payload = $record->payload;

        return match ($record->tag) {
            Tag::NIL   => null,
            Tag::TRUE  => true,
            Tag::FALSE => false,
            Tag::INT   => is_int($payload)
                ? $payload
                : throw new \UnexpectedValueException('an INT record carried a non-integer payload'),
            Tag::FLOAT => (float) $payload,
            Tag::CLOSE => throw new \LogicException('a CLOSE record is protocol control, not a value'),
            default    => throw new \LogicException(sprintf('tag %s is not a value', $record->tag->name)),
        };
    }

    /** The refusal every arena-backed tag gets until #7 lands. */
    public static function needsArena(Tag $tag): \LogicException
    {
        return new \LogicException(sprintf(
            'a value tagged %s travels by arena address, and the shared arena is not implemented '
            . 'yet (see #7); results tagged NIL, TRUE, FALSE, INT or FLOAT cross a worker boundary '
            . 'today',
            $tag->name,
        ));
    }
}
