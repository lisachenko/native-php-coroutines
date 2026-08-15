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
 * The 16-byte unit every value takes when it crosses a boundary.
 *
 * Layout, matching the C side byte for byte:
 *
 * ```c
 * struct tagged_record {
 *     uint8_t  tag;      // offset 0
 *     uint8_t  pad[7];   // offset 1  — keeps the payload 8-byte aligned
 *     uint64_t payload;  // offset 8
 * };
 * ```
 *
 * The payload is eight raw bytes reinterpreted according to the tag: a signed integer, a double, or
 * an arena address. It is **not** an encoding of a PHP value — a record never contains user data
 * beyond a scalar, and never a graph. Strings, arrays and objects put their *address* here and
 * leave the value itself in the arena.
 *
 * Everything is written in machine byte order and machine double representation, because the same
 * bytes are read by C code in a sibling process on the same machine. Fork guarantees that sibling
 * is the same binary on the same architecture, so there is no endianness question to answer.
 *
 * # Publication order — only in shared memory
 *
 * {@see self::encode()} and {@see self::decode()} are for the **control socket**, where the 16 bytes
 * are one frame of an ordered byte stream and nothing can tear.
 *
 * A record living in a **shared-memory ring slot or result slot** is a different problem. The
 * substrate spikes measured that a 16-byte record is *not* read atomically: roughly 1.3 % of
 * unlocked reads saw the payload and the tag from different generations. So a record in the arena
 * is only safe under one of two disciplines:
 *
 * 1. the whole access — write and read — happens under the slot's mutex; or
 * 2. **the writer stores the payload first and the tag last, and the reader loads the tag first and
 *    the payload second.** The tag is the publication flag: a reader that sees the new tag is
 *    guaranteed to see the payload that was written before it.
 *
 * Never the mix. Writing the tag before the payload publishes a slot whose payload is still the
 * previous generation's, and no amount of re-reading detects it.
 *
 * The eight-byte payload on its own *is* atomic when aligned, so a single-slot address read (an
 * `OBJ`/`STR`/`ARR` pointer whose tag cannot change) may skip the lock and get old-or-new, never a
 * mix. Do not over-lock that path.
 */
final readonly class TaggedRecord
{
    /** Total size of the record, in bytes. */
    public const int SIZE = 16;

    /** Byte offset of the tag within the record. */
    public const int TAG_OFFSET = 0;

    /** Byte offset of the payload within the record; the seven bytes before it are padding. */
    public const int PAYLOAD_OFFSET = 8;

    /** Size of the payload field, in bytes. */
    public const int PAYLOAD_SIZE = 8;

    private function __construct(
        public Tag $tag,
        public int|float $payload,
    ) {}

    public static function nil(): self
    {
        return new self(Tag::NIL, 0);
    }

    public static function bool(bool $value): self
    {
        return new self($value ? Tag::TRUE : Tag::FALSE, 0);
    }

    public static function int(int $value): self
    {
        return new self(Tag::INT, $value);
    }

    public static function float(float $value): self
    {
        return new self(Tag::FLOAT, $value);
    }

    public static function close(): self
    {
        return new self(Tag::CLOSE, 0);
    }

    /**
     * A record pointing at something in the arena.
     *
     * @param Tag $tag One of STR, OBJ or ARR.
     */
    public static function address(Tag $tag, int $address): self
    {
        if (!$tag->isAddress()) {
            throw new \InvalidArgumentException(
                sprintf('Tag %s does not carry an arena address', $tag->name),
            );
        }

        return new self($tag, $address);
    }

    /** The arena address this record points at. */
    public function arenaAddress(): int
    {
        if (!$this->tag->isAddress() || !is_int($this->payload)) {
            throw new \LogicException(
                sprintf('Record tagged %s has no arena address', $this->tag->name),
            );
        }

        return $this->payload;
    }

    /** Serialise to the 16 wire bytes. */
    public function encode(): string
    {
        return pack('C', $this->tag->value)
            . str_repeat("\0", self::PAYLOAD_OFFSET - 1)
            . $this->encodePayload();
    }

    /**
     * The eight payload bytes on their own, without the tag byte and its padding.
     *
     * {@see ControlRecord} carries the tag in its own header byte and the payload in its trailing
     * eight, so it needs the halves separately rather than the whole self-describing record.
     */
    public function encodePayload(): string
    {
        return match (true) {
            $this->tag->isPayloadless() => pack('Q', 0),
            $this->tag === Tag::INT     => pack('q', (int) $this->payload),
            $this->tag === Tag::FLOAT   => pack('d', (float) $this->payload),
            default                     => pack('Q', (int) $this->payload),
        };
    }

    /**
     * Rebuild a record from a tag byte and its eight payload bytes, held apart.
     *
     * @param string $payload At least {@see self::PAYLOAD_SIZE} bytes; anything beyond is ignored.
     *
     * @throws \DomainException          When the tag byte is not a known tag.
     * @throws \LengthException          When there are fewer than eight payload bytes.
     * @throws \UnexpectedValueException When the payload cannot be unpacked at all.
     */
    public static function decodePayload(int $tagByte, string $payload): self
    {
        $tag = Tag::tryFrom($tagByte);
        if ($tag === null) {
            throw new \DomainException(sprintf('Unknown record tag %d', $tagByte));
        }

        if ($tag->isPayloadless()) {
            return new self($tag, 0);
        }

        if (strlen($payload) < self::PAYLOAD_SIZE) {
            throw new \LengthException(sprintf(
                'A record payload is %d bytes, got %d',
                self::PAYLOAD_SIZE,
                strlen($payload),
            ));
        }

        $format = match ($tag) {
            Tag::INT   => 'qvalue',
            Tag::FLOAT => 'dvalue',
            default    => 'Qvalue',
        };

        $unpacked = unpack($format, $payload);
        $value    = is_array($unpacked) ? $unpacked['value'] ?? null : null;
        if (!is_int($value) && !is_float($value)) {
            throw new \UnexpectedValueException('Unable to read the payload of a tagged record');
        }

        return new self($tag, $value);
    }

    /**
     * Read a record from exactly {@see self::SIZE} bytes.
     *
     * @throws \LengthException     When the input is not exactly one record long.
     * @throws \DomainException     When the tag byte is not a known tag.
     * @throws \UnexpectedValueException When the bytes cannot be unpacked at all.
     */
    public static function decode(string $bytes): self
    {
        if (strlen($bytes) !== self::SIZE) {
            throw new \LengthException(
                sprintf('A tagged record is exactly %d bytes, got %d', self::SIZE, strlen($bytes)),
            );
        }

        $header  = unpack('Ctag', $bytes);
        $tagByte = is_array($header) ? $header['tag'] ?? null : null;
        if (!is_int($tagByte)) {
            throw new \UnexpectedValueException('Unable to read the tag of a tagged record');
        }

        return self::decodePayload($tagByte, substr($bytes, self::PAYLOAD_OFFSET));
    }
}
