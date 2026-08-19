--TEST--
A shared channel can be declared with capacity 0, and reports itself as a rendezvous
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Capacity 0 used to be refused at declaration, because the substrate's handoff gate counted only
// receivers parked inside its own blocking recv() and this runtime never calls it. The substrate
// now lets a receiver parked on this poller register as the partner, so the refusal is gone and a
// shared channel spans the same capacity range a local one does.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

// A negative capacity is still nonsense, and still says so.
try {
    $runtime->declareShared('broken', SharedChannel::class, -1);
} catch (InvalidArgumentException $refusal) {
    echo $refusal->getMessage(), PHP_EOL;
}

$runtime->run(static function (TaskRuntime $self): void {
    $channel = $self->shared('handoff');

    echo 'capacity: ', $channel->capacity(), PHP_EOL;
    echo 'rendezvous: ', $channel->isRendezvous() ? 'yes' : 'no', PHP_EOL;
    echo 'buffered right now: ', $channel->count(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
shared channel "broken" cannot have a negative capacity, got -1; 0 is a cross-process rendezvous and a positive number buffers that many records
capacity: 0
rendezvous: yes
buffered right now: 0
children left: none
