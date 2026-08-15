--TEST--
A select with a default takes the default instead of parking when nothing is ready
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$channel   = new Channel($scheduler, 1);

$poll = static fn (): string => Select::on($scheduler)
    ->recv($channel, fn (mixed $value): string => "took {$value}")
    ->default(fn (): string => 'nothing to do')
    ->run();

$scheduler->spawn(function () use ($channel, $poll): void {
    echo $poll(), "\n";

    $channel->send('work');
    echo $poll(), "\n";
    echo $poll(), "\n";

    // Nothing was parked at any point, so the default cannot have left a waiter behind either.
    echo 'waiters left behind: ', $channel->pendingReceivers(), "\n";
});

// A default that parked would leave this coroutine blocked and the run would end in a deadlock.
$scheduler->loop();

echo "the coroutine ran to completion\n";
?>
--EXPECT--
nothing to do
took work
nothing to do
waiters left behind: 0
the coroutine ran to completion
