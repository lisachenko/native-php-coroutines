--TEST--
The tag table and the layout version are checked against the substrate by number, not by name
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
use Lisachenko\NativePhpCoroutines\Parallel\SharedArena;
use Lisachenko\SharedData\Ipc\ValueRecord;
use Lisachenko\SharedData\Ipc\ValueTag;
use Lisachenko\SharedData\Ipc\WakeEvent;
use Lisachenko\SharedData\Ipc\WakeOpcode;

include __DIR__ . '/../../vendor/autoload.php';

// A record written by one side is read by the other, so the two enums are one table with two
// spellings. Comparing them by *name* would pass while the numbers drifted — and drifted numbers do
// not fail, they reinterpret every record in flight. So the comparison is numeric.
$mismatched = [];

foreach (Tag::cases() as $tag) {
    $theirs = ValueTag::tryFrom($tag->value);

    if ($theirs === null || strtoupper($theirs->name) !== $tag->name) {
        $mismatched[] = sprintf('%s = %d', $tag->name, $tag->value);
    }
}

echo 'tags that disagree with the substrate: ', $mismatched === [] ? 'none' : implode(', ', $mismatched), PHP_EOL;
echo 'tags on both sides: ', count(Tag::cases()), ' / ', count(ValueTag::cases()), PHP_EOL;

// The four opcodes both sides know carry the same byte, because a ControlRecord and a WakeEvent are
// now the same sixteen bytes. SPAWN and SHUTDOWN are ours alone and sit clear of that range.
$shared = [
    WakeOpcode::Wake->value   => Opcode::WAKE,
    WakeOpcode::Result->value => Opcode::RESULT,
    WakeOpcode::Panic->value  => Opcode::PANIC,
    WakeOpcode::Close->value  => Opcode::CLOSE,
];

$agree = true;

foreach ($shared as $number => $ours) {
    $agree = $agree && $ours->value === $number;
}

echo 'the shared opcodes carry the same byte: ', $agree ? 'yes' : 'no', PHP_EOL;
echo 'our own opcodes are clear of theirs: ',
    Opcode::SPAWN->value > 4 && Opcode::SHUTDOWN->value > 4 ? 'yes' : 'no',
    PHP_EOL;

// One record shape everywhere.
echo 'control record size: ', ControlRecord::SIZE, PHP_EOL;
echo 'their wake event size: ', WakeEvent::SIZE, PHP_EOL;
echo 'their value record size: ', ValueRecord::SIZE, PHP_EOL;

// And the layout gate. A mismatch is read at the right addresses with the wrong meanings, so the
// runtime refuses to boot rather than shipping a compatibility shim.
echo 'layout version required: ', SharedArena::REQUIRED_LAYOUT_VERSION, PHP_EOL;
echo 'layout version installed: ', SharedArena::substrateLayoutVersion(), PHP_EOL;
?>
--EXPECT--
tags that disagree with the substrate: none
tags on both sides: 10 / 10
the shared opcodes carry the same byte: yes
our own opcodes are clear of theirs: yes
control record size: 16
their wake event size: 16
their value record size: 16
layout version required: 5
layout version installed: 5
