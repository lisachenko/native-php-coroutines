--TEST--
Every tag round-trips through the 16-byte record encoding
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;

include __DIR__ . '/../../vendor/autoload.php';

$records = [
    TaggedRecord::nil(),
    TaggedRecord::bool(true),
    TaggedRecord::bool(false),
    TaggedRecord::int(PHP_INT_MIN),
    TaggedRecord::float(1.5),
    TaggedRecord::address(Tag::STR, 0x7F0000001000),
    TaggedRecord::address(Tag::OBJ, 0x7F0000002000),
    TaggedRecord::address(Tag::ARR, 0x7F0000003000),
    TaggedRecord::close(),
];

foreach ($records as $record) {
    $bytes = $record->encode();
    if (strlen($bytes) !== TaggedRecord::SIZE) {
        echo 'wrong size for ', $record->tag->name, ': ', strlen($bytes), PHP_EOL;
        continue;
    }

    $decoded = TaggedRecord::decode($bytes);
    $same    = $decoded->tag === $record->tag && $decoded->payload === $record->payload;

    echo $record->tag->name, ': ', $same ? 'ok' : 'MISMATCH', PHP_EOL;
}
?>
--EXPECT--
NIL: ok
TRUE: ok
FALSE: ok
INT: ok
FLOAT: ok
STR: ok
OBJ: ok
ARR: ok
CLOSE: ok
