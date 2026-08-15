--TEST--
A send on a rendezvous channel parks until a receiver arrives
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\CoroutineStatus;
use Lisachenko\NativePhpCoroutines\SuspendCommand;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$channel   = new Channel($scheduler);

$sender = $scheduler->spawn(function () use ($channel): void {
    echo "sender: about to send\n";
    $channel->send('payload');
    echo "sender: resumed\n";
});

// Runs after the sender has already parked, and does nothing but let the scheduler turn over a few
// times: no amount of scheduling can complete a rendezvous that has no receiver. Only once it
// finally spawns one does the sender move.
$scheduler->spawn(function () use ($scheduler, $channel, $sender): void {
    for ($tick = 0; $tick < 3; $tick++) {
        echo 'tick ', $tick, ': sender is ', $sender->status()->name, "\n";
        $scheduler->suspend(SuspendCommand::YIELD);
    }

    echo "no receiver yet, spawning one\n";

    $scheduler->spawn(function () use ($channel): void {
        $value = $channel->recv();
        echo "receiver: took {$value}\n";
    });
});

$scheduler->loop();

echo 'sender finished: ', var_export($sender->status() === CoroutineStatus::DONE, true), PHP_EOL;
?>
--EXPECT--
sender: about to send
tick 0: sender is BLOCKED
tick 1: sender is BLOCKED
tick 2: sender is BLOCKED
no receiver yet, spawning one
receiver: took payload
sender: resumed
sender finished: true
