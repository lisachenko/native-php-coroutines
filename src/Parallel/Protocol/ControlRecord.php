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
 * The fixed-size event record that travels on a control socket.
 *
 * Layout:
 *
 * ```c
 * struct control_record {
 *     uint8_t              opcode;   // offset 0
 *     uint8_t              pad[7];   // offset 1
 *     uint64_t             slot_id;  // offset 8
 *     struct tagged_record value;    // offset 16 (16 bytes)
 * };                                 // 32 bytes total
 * ```
 *
 * Fixed size is the point. A reader knows in advance exactly how many bytes a message is, so there
 * is no framing, no length prefix, and no place for a value graph to hide. If a future change ever
 * wants to put "just a little" variable-length data on this socket, that is the Never-Serialize
 * Rule being violated — the value belongs in the arena and its address belongs here.
 *
 * Reads must still handle short reads: a socket can deliver a record in pieces, and only a full
 * {@see self::SIZE} bytes may be consumed.
 */
final readonly class ControlRecord
{
    /** Total size of the record, in bytes. */
    public const int SIZE = 32;

    public const int OPCODE_OFFSET = 0;

    public const int SLOT_ID_OFFSET = 8;

    public const int VALUE_OFFSET = 16;

    public function __construct(
        public Opcode $opcode,
        public int $slotId = 0,
        public ?TaggedRecord $value = null,
    ) {}

    /** Serialise to the 32 wire bytes. */
    public function encode(): string
    {
        $value = $this->value ?? TaggedRecord::nil();

        return pack('C', $this->opcode->value)
            . str_repeat("\0", self::SLOT_ID_OFFSET - 1)
            . pack('Q', $this->slotId)
            . $value->encode();
    }

    /**
     * Read a record from exactly {@see self::SIZE} bytes.
     *
     * @throws \LengthException          When the input is not exactly one record long.
     * @throws \DomainException          When the opcode byte is not a known opcode.
     * @throws \UnexpectedValueException When the bytes cannot be unpacked at all.
     */
    public static function decode(string $bytes): self
    {
        if (strlen($bytes) !== self::SIZE) {
            throw new \LengthException(
                sprintf('A control record is exactly %d bytes, got %d', self::SIZE, strlen($bytes)),
            );
        }

        $header     = unpack('Copcode', $bytes);
        $opcodeByte = is_array($header) ? $header['opcode'] ?? null : null;
        if (!is_int($opcodeByte)) {
            throw new \UnexpectedValueException('Unable to read the opcode of a control record');
        }

        $opcode = Opcode::tryFrom($opcodeByte);
        if ($opcode === null) {
            throw new \DomainException(sprintf('Unknown control opcode %d', $opcodeByte));
        }

        $slot   = unpack('QslotId', $bytes, self::SLOT_ID_OFFSET);
        $slotId = is_array($slot) ? $slot['slotId'] ?? null : null;
        if (!is_int($slotId)) {
            throw new \UnexpectedValueException('Unable to read the slot id of a control record');
        }

        return new self(
            $opcode,
            $slotId,
            TaggedRecord::decode(substr($bytes, self::VALUE_OFFSET, TaggedRecord::SIZE)),
        );
    }
}
