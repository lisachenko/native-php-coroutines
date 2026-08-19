--TEST--
Sending to a closed channel throws
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
    $channel->close();

    try {
        $channel->send('too late');
    } catch (ClosedChannelException $failure) {
        echo $failure->getMessage(), "\n";
    }

    // Receiving is not an error even though sending is: a consumer is entitled to find out that
    // production has finished, while a producer that still believes it owns the channel is a bug.
    [$value, $ok] = $channel->recvOk();
    echo 'receiving is still fine: ', var_export($ok, true), "\n";
});

$scheduler->loop();
?>
--EXPECT--
send on closed channel
receiving is still fine: false
