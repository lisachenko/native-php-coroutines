--TEST--
Declaring a shared root or a shared closure after the pool has forked is an error that says why
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedCounter;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

// Before the fork: fine. Every worker will inherit this at the same address.
$runtime->declareShared('counter', SharedCounter::class);

echo 'declared before the fork: ', $runtime->arena()?->hasRoot('counter') === true ? 'yes' : 'no', PHP_EOL;

// A capacity-0 shared channel is refused at declaration rather than delivered as one that usually
// fails to hand anything over — the cross-process rendezvous handshake counts receivers parked
// inside the substrate's own blocking recv(), which this runtime deliberately never calls.
try {
    $runtime->declareShared('rendezvous', SharedChannel::class, 0);
} catch (InvalidArgumentException $refusal) {
    echo $refusal->getMessage(), PHP_EOL;
}

$runtime->run(static function (TaskRuntime $self): void {
    // After the fork the workers already exist, so a root created now lives in this process alone.
    // That is not a late binding, and it is refused rather than silently made useless.
    try {
        $self->declareShared('late', SharedCounter::class);
    } catch (LogicException $refusal) {
        echo $refusal->getMessage(), PHP_EOL;
    }

    try {
        $self->registerSharedClosure('late', static fn (): int => 1);
    } catch (LogicException $refusal) {
        echo $refusal->getMessage(), PHP_EOL;
    }
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
declared before the fork: yes
shared channel "rendezvous" needs a capacity of at least 1: a cross-process rendezvous accepts a send only while a sibling is parked inside the substrate's own blocking recv(), and this runtime parks Fibers on its poller instead
shared root "late" cannot be declared after the workers have forked: a root is inherited by address, so one created now exists only in this process. Declare every root before run() forks the pool
closure "late" cannot be shared: the fork barrier has already been passed, and only a closure registered before it exists at the same address in every worker
children left: none
