--TEST--
A select takes the case that is already ready instead of parking
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
$jobs      = new Channel($scheduler, 1);

// A rendezvous with nobody receiving: sending on it would park, so this case can never be the one
// that is taken here, whatever order the cases are polled in.
$results = new Channel($scheduler);

$scheduler->spawn(function () use ($scheduler, $jobs, $results): void {
    $jobs->send('work');

    $outcome = Select::on($scheduler)
        ->send($results, 'answer', fn (): string => 'sent an answer')
        ->recv($jobs, fn (mixed $value, bool $ok): string => "received {$value}")
        ->run();

    echo $outcome, "\n";

    // The select never parked, so it never registered a waiter anywhere — including on the case it
    // could not take.
    echo 'jobs waiters: ', $jobs->pendingReceivers() + $jobs->pendingSenders(), "\n";
    echo 'results waiters: ', $results->pendingReceivers() + $results->pendingSenders(), "\n";
});

$scheduler->loop();
?>
--EXPECT--
received work
jobs waiters: 0
results waiters: 0
