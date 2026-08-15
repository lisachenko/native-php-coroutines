--TEST--
A parked select send case throws when its channel is closed underneath it
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Exception\ClosedChannelException;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$outbound  = new Channel($scheduler);
$inbound   = new Channel($scheduler);

$scheduler->spawn(function () use ($scheduler, $outbound, $inbound): void {
    try {
        Select::on($scheduler)
            ->send($outbound, 'payload', fn (): string => 'sent')
            ->recv($inbound, fn (mixed $value): string => "received {$value}")
            ->run();

        echo "NOT REACHED\n";
    } catch (ClosedChannelException $failure) {
        // A closed channel wins the send case rather than being skipped, and losing that race is
        // the same error a plain parked send would report.
        echo $failure->getMessage(), "\n";
    }

    echo 'waiters on the closed channel: ', $outbound->pendingSenders(), "\n";
    echo 'waiters on the other channel: ', $inbound->pendingReceivers(), "\n";
});

$scheduler->spawn(function () use ($outbound): void {
    $outbound->close();
});

$scheduler->loop();
?>
--EXPECT--
send on channel closed while waiting
waiters on the closed channel: 0
waiters on the other channel: 0
