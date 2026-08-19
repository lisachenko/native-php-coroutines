--TEST--
A parked select send case completes when a receiver takes its value
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$outbound  = new Channel($scheduler);
$idle      = new Channel($scheduler);

$scheduler->spawn(function () use ($scheduler, $outbound, $idle): void {
    // Neither case can proceed yet, so the select parks on both — as a sender on one and as a
    // receiver on the other.
    $outcome = Select::on($scheduler)
        ->send($outbound, 'payload', fn (): string => 'the value was taken')
        ->recv($idle, fn (mixed $value): string => "received {$value}")
        ->run();

    echo $outcome, "\n";
    echo 'waiters on the channel that won: ', $outbound->pendingSenders(), "\n";
    echo 'waiters on the channel that lost: ', $idle->pendingReceivers(), "\n";
});

$scheduler->spawn(function () use ($outbound): void {
    // Takes the value straight out of the select's wait node, exactly as it would from an ordinary
    // parked sender: a select case is not a special kind of waiter.
    $value = $outbound->recv();
    echo "receiver took {$value}\n";
});

$scheduler->loop();
?>
--EXPECT--
receiver took payload
the value was taken
waiters on the channel that won: 0
waiters on the channel that lost: 0
