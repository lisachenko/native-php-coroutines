--TEST--
A sender that was already parked when the channel closed throws
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Exception\ClosedChannelException;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$channel   = new Channel($scheduler, 1);

$scheduler->spawn(function () use ($channel): void {
    $channel->send('buffered');

    try {
        // The buffer is full, so this parks — and it is still parked when somebody else closes.
        $channel->send('never delivered');
        echo "NOT REACHED\n";
    } catch (ClosedChannelException $failure) {
        echo 'parked sender: ', $failure->getMessage(), "\n";
    }
});

$scheduler->spawn(function () use ($channel): void {
    echo 'parked senders: ', $channel->pendingSenders(), "\n";
    $channel->close();

    // The parked sender's value is gone with it, but whatever had already reached the buffer is
    // still owed to the consumers.
    [$value, $ok] = $channel->recvOk();
    printf("still receivable: %s, ok = %s\n", var_export($value, true), var_export($ok, true));
});

$scheduler->loop();
?>
--EXPECT--
parked senders: 1
still receivable: 'buffered', ok = true
parked sender: send on channel closed while waiting
