--TEST--
A resolved select leaves no waiter behind on the cases that lost
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$loser     = new Channel($scheduler);
$winner    = new Channel($scheduler);

$scheduler->spawn(function () use ($scheduler, $loser, $winner): void {
    $outcome = Select::on($scheduler)
        ->recv($loser, fn (mixed $value): string => "loser delivered {$value}")
        ->recv($winner, fn (mixed $value): string => "winner delivered {$value}")
        ->run();

    echo $outcome, "\n";

    // The select parked on both channels, so both had a waiter. The winner's was consumed by the
    // handoff; the loser's is only gone because the select unlinked it. Left in place it would grow
    // by one on every iteration of a select loop.
    echo 'waiters on the losing channel: ', $loser->pendingReceivers(), "\n";
    echo 'waiters on the winning channel: ', $winner->pendingReceivers(), "\n";

    // And the leak is not just about memory: a stale waiter would still look like a receiver, so
    // this send would hand its value to a coroutine that has long since moved on and return as if
    // it had been delivered. With the waiter unlinked there is genuinely nobody there, and the send
    // parks — which is what the deadlock below reports.
    $scheduler->spawn(function () use ($loser): void {
        echo "sending on the channel that lost\n";
        $loser->send('nobody is listening');
        echo "NOT REACHED: the value was taken by a coroutine that had moved on\n";
    });
});

$scheduler->spawn(function () use ($winner): void {
    $winner->send('a value');
});

try {
    $scheduler->loop();
} catch (DeadlockException $failure) {
    echo explode("\n", $failure->getMessage())[0], "\n";

    foreach ($failure->blockedCoroutines() as $blocked) {
        echo 'blocked on: ', $blocked['wait'], "\n";
    }
}
?>
--EXPECT--
winner delivered a value
waiters on the losing channel: 0
waiters on the winning channel: 0
sending on the channel that lost
all coroutines are asleep - deadlock!
blocked on: send on channel #1
