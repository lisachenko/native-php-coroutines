--TEST--
A capacity-0 shared channel cannot be a select send case, and the refusal names the remedies
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// A select case has to resolve without parking. The earliest point a rendezvous send could claim is
// the deposit — and the partner it was deposited against may still walk away before taking the
// value, so a case that reported "sent" there would quietly give this one channel the guarantees of
// a buffered one while send() on the very same channel keeps the rendezvous ones. Refusing is the
// honest answer, and it names both ways out.
//
// A *receive* case is unaffected, and a buffered shared channel still sends from a select.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);
$runtime->declareShared('buffered', SharedChannel::class, 1);

$runtime->run(static function (TaskRuntime $self): void {
    $rendezvous = $self->shared('handoff');
    $buffered   = $self->shared('buffered');
    $local      = new Channel($self->scheduler(), 1);

    try {
        Select::on($self->scheduler())
            ->send($rendezvous, 'nowhere to commit', static fn(): string => 'sent')
            ->recv($local, static fn(mixed $value): string => 'local')
            ->run();
    } catch (LogicException $refusal) {
        echo preg_replace('/@0x[0-9A-F]+/', '@ADDRESS', $refusal->getMessage()), PHP_EOL;
    }

    // The same statement over a buffered shared channel is business as usual.
    echo Select::on($self->scheduler())
        ->send($buffered, 'room for this one', static fn(): string => 'the buffered case sent')
        ->recv($local, static fn(mixed $value): string => 'local')
        ->run(), PHP_EOL;

    echo 'buffered now holds: ', $buffered->count(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
a capacity-0 shared channel cannot be a select send case: a rendezvous send completes when the value is TAKEN, which a case cannot wait for without parking. Declare the channel with a capacity of at least 1, or drive the handoff from a coroutine of its own that calls send() on shared channel @ADDRESS
the buffered case sent
buffered now holds: 1
children left: none
