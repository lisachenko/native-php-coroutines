--TEST--
A WaitGroup releases its waiters only when the counter reaches zero
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$group     = new WaitGroup($scheduler);
$gate      = new Channel($scheduler);

foreach (['alpha', 'beta', 'gamma'] as $name) {
    // add() before spawning, never inside the worker: a counter that is still zero when wait() runs
    // is a counter that lets wait() straight through.
    $group->add();

    $scheduler->spawn(function () use ($group, $gate, $name): void {
        // Blocks until the coordinator opens the gate, so the workers cannot all have finished
        // before wait() is even reached.
        $gate->recv();
        echo "{$name} finished, counter before done(): ", $group->count(), "\n";
        $group->done();
    });
}

$scheduler->spawn(function () use ($group, $gate): void {
    echo 'counter: ', $group->count(), "\n";

    foreach (['alpha', 'beta', 'gamma'] as $ignored) {
        $gate->send(null);
    }

    $group->wait();
    echo 'wait returned with counter ', $group->count(), "\n";

    // Waiting for work that is already finished is not an error, and must not park.
    $group->wait();
    echo "a second wait returned immediately\n";
});

$scheduler->loop();
?>
--EXPECT--
counter: 3
alpha finished, counter before done(): 3
beta finished, counter before done(): 2
gamma finished, counter before done(): 1
wait returned with counter 0
a second wait returned immediately
