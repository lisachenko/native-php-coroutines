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
 * Layout — **the substrate's notification record, byte for byte**:
 *
 * ```c
 * struct control_record {
 *     uint8_t  opcode;   // offset 0   WAKE | RESULT | PANIC | CLOSE | SPAWN | SHUTDOWN
 *     uint8_t  tag;      // offset 1   Tag of what became available
 *     uint16_t pad;      // offset 2
 *     uint32_t id;       // offset 4   slot id / channel id
 *     uint64_t address;  // offset 8   arena address, 0 unless the tag is address-shaped
 * };                     // 16 bytes total
 * ```
 *
 * # Why 16 and not 32
 *
 * This record used to be 32 bytes (`opcode | 7 pad | uint64 slot_id | 16-byte TaggedRecord`),
 * designed before the substrate's IPC layer existed. The substrate settled on the 16-byte shape
 * above for `Lisachenko\SharedData\Ipc\WakeEvent`, which is what its `SharedChannel`,
 * `ResultSlotTable` and `WakeRegistry` write onto their inherited wake sockets — and the substrate's
 * layout wins on anything crossing into shared memory or its sockets.
 *
 * Keeping two record shapes in one runtime would mean two decoders, two length constants and two
 * chances to read one socket with the other's reader. There is no field the 32-byte version carried
 * that this one does not: a slot id above 2^32 is not reachable (the substrate's result-slot table
 * is pre-sized and bounded), and the tag plus the address *are* the tagged record. So the parent ↔
 * worker control socket converts too, and this package now has exactly one record shape.
 *
 * Fixed size is still the point. A reader knows in advance exactly how many bytes a message is, so
 * there is no framing, no length prefix, and no place for a value graph to hide. If a future change
 * ever wants to put "just a little" variable-length data on this socket, that is the
 * Never-Serialize Rule being violated — the value belongs in the arena and its address belongs here.
 *
 * Reads must still handle short reads: a socket can deliver a record in pieces, and only a full
 * {@see self::SIZE} bytes may be consumed.
 */
final readonly class ControlRecord
{
    /** Total size of the record, in bytes — the substrate's `WakeEvent::SIZE`. */
    public const int SIZE = 16;

    public const int OPCODE_OFFSET = 0;

    public const int TAG_OFFSET = 1;

    public const int ID_OFFSET = 4;

    public const int ADDRESS_OFFSET = 8;

    /** Largest id the `uint32` field can carry. */
    public const int MAX_ID = 0xFFFFFFFF;

    public function __construct(
        public Opcode $opcode,
        public int $slotId = 0,
        public ?TaggedRecord $value = null,
    ) {
        if ($slotId < 0 || $slotId > self::MAX_ID) {
            throw new \OutOfRangeException(sprintf(
                'a control record id is a uint32, so %d does not fit in one',
                $slotId,
            ));
        }
    }

    /** Serialise to the 16 wire bytes. */
    public function encode(): string
    {
        $value = $this->value ?? TaggedRecord::nil();

        return pack('C', $this->opcode->value)
            . pack('C', $value->tag->value)
            . pack('v', 0)
            . pack('V', $this->slotId)
            . $value->encodePayload();
    }

    /**
     * Read a record from exactly {@see self::SIZE} bytes.
     *
     * @throws \LengthException          When the input is not exactly one record long.
     * @throws \DomainException          When the opcode or tag byte is not a known one.
     * @throws \UnexpectedValueException When the bytes cannot be unpacked at all.
     */
    public static function decode(string $bytes): self
    {
        if (strlen($bytes) !== self::SIZE) {
            throw new \LengthException(
                sprintf('A control record is exactly %d bytes, got %d', self::SIZE, strlen($bytes)),
            );
        }

        $header = unpack('Copcode/Ctag/vpad/Vid', $bytes);
        if (!is_array($header)) {
            throw new \UnexpectedValueException('Unable to read the header of a control record');
        }

        $opcodeByte = $header['opcode'] ?? null;
        $tagByte    = $header['tag']    ?? null;
        $id         = $header['id']     ?? null;

        if (!is_int($opcodeByte) || !is_int($tagByte) || !is_int($id)) {
            throw new \UnexpectedValueException('Unable to read the header of a control record');
        }

        $opcode = Opcode::tryFrom($opcodeByte);
        if ($opcode === null) {
            throw new \DomainException(sprintf('Unknown control opcode %d', $opcodeByte));
        }

        return new self(
            $opcode,
            $id,
            TaggedRecord::decodePayload($tagByte, substr($bytes, self::ADDRESS_OFFSET)),
        );
    }
}
