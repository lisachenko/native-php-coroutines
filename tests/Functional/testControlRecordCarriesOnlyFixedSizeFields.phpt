--TEST--
A control record is exactly 16 bytes and carries opcode, tag, id and address
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;

include __DIR__ . '/../../vendor/autoload.php';

$spawn = new ControlRecord(Opcode::SPAWN, 3, TaggedRecord::address(Tag::OBJ, 0x7F00DEADB000));
$bytes = $spawn->encode();

echo 'size: ', strlen($bytes), PHP_EOL;

$decoded = ControlRecord::decode($bytes);
echo 'opcode: ', $decoded->opcode->name, PHP_EOL;
echo 'slot: ', $decoded->slotId, PHP_EOL;
echo 'tag: ', $decoded->value?->tag->name, PHP_EOL;
echo 'address: 0x', strtoupper(dechex($decoded->value?->arenaAddress() ?? 0)), PHP_EOL;

// A record is a fixed-size frame: a reader always knows how much to consume, which is what
// makes it impossible to smuggle a variable-length value graph onto the control socket.
echo 'framing: ', strlen($bytes) === ControlRecord::SIZE ? 'fixed' : 'VARIABLE', PHP_EOL;

try {
    ControlRecord::decode(substr($bytes, 0, 15));
} catch (LengthException $e) {
    echo 'short read rejected: ', $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
size: 16
opcode: SPAWN
slot: 3
tag: OBJ
address: 0x7F00DEADB000
framing: fixed
short read rejected: A control record is exactly 16 bytes, got 15
