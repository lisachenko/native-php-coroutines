--TEST--
A control record delivered in two pieces is held back until all 16 bytes are there
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\ControlSocket;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelWaitFor;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

[$writer, $reader] = ControlSocket::pair();

$frame = (new ControlRecord(Opcode::RESULT, 5, TaggedRecord::int(99)))->encode();

// A stream socket is a byte stream, so this is not a contrived case: any record may arrive in
// pieces, and a reader that consumed what it had would decode the head of one record as a whole.
fwrite($writer->stream(), substr($frame, 0, 9));

$firstBatch = [];
parallelWaitFor(static function () use ($reader, &$firstBatch): bool {
    $firstBatch = array_merge($firstBatch, $reader->drain());

    return $reader->pendingBytes() === 9;
}, 2.0);

echo 'records after 9 bytes: ', count($firstBatch), "\n";
echo 'bytes held back: ', $reader->pendingBytes(), "\n";

fwrite($writer->stream(), substr($frame, 9));

$records = [];
parallelWaitFor(static function () use ($reader, &$records): bool {
    $records = array_merge($records, $reader->drain());

    return $records !== [];
}, 2.0);

echo 'records after the rest: ', count($records), "\n";
echo 'opcode: ', $records[0]->opcode->name, "\n";
echo 'slot: ', $records[0]->slotId, "\n";
echo 'payload: ', var_export($records[0]->value?->payload, true), "\n";
echo 'nothing left over: ', $reader->pendingBytes() === 0 ? 'yes' : 'NO', "\n";

// Two records written as one 32-byte blob come back as two, not as one oversized frame.
$writer->send(new ControlRecord(Opcode::RESULT, 6, TaggedRecord::int(1)));
$writer->send(new ControlRecord(Opcode::RESULT, 7, TaggedRecord::int(2)));

$batch = [];
parallelWaitFor(static function () use ($reader, &$batch): bool {
    $batch = array_merge($batch, $reader->drain());

    return count($batch) === 2;
}, 2.0);

echo 'coalesced records split apart: ', count($batch), "\n";
echo 'slots: ', implode(', ', array_map(fn (ControlRecord $r): int => $r->slotId, $batch)), "\n";

$writer->close();
$reader->close();
?>
--EXPECT--
records after 9 bytes: 0
bytes held back: 9
records after the rest: 1
opcode: RESULT
slot: 5
payload: 99
nothing left over: yes
coalesced records split apart: 2
slots: 6, 7
