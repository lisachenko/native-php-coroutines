--TEST--
Closing an already closed channel throws
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
$channel   = new Channel($scheduler);

$scheduler->spawn(function () use ($channel): void {
    $channel->close();
    echo 'closed: ', var_export($channel->isClosed(), true), "\n";

    // Two closes mean two producers each believing they own the end of the channel, which is worth
    // a bug report rather than a shrug.
    try {
        $channel->close();
    } catch (ClosedChannelException $failure) {
        echo $failure->getMessage(), "\n";
    }

    echo 'still closed: ', var_export($channel->isClosed(), true), "\n";
});

$scheduler->loop();
?>
--EXPECT--
closed: true
close of closed channel
still closed: true
