--TEST--
Closing a channel wakes every parked receiver with no value
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$channel   = new Channel($scheduler);

foreach (['first', 'second', 'third'] as $name) {
    $scheduler->spawn(function () use ($channel, $name): void {
        [$value, $ok] = $channel->recvOk();
        printf("%s receiver: %s, ok = %s\n", $name, var_export($value, true), var_export($ok, true));
    });
}

$scheduler->spawn(function () use ($channel): void {
    echo 'parked receivers: ', $channel->pendingReceivers(), "\n";
    $channel->close();
    echo 'parked receivers after close: ', $channel->pendingReceivers(), "\n";
});

// Nothing is left blocked: a close that missed a waiter would surface here as a deadlock.
$scheduler->loop();

echo "every waiter was woken\n";
?>
--EXPECT--
parked receivers: 3
parked receivers after close: 0
first receiver: NULL, ok = false
second receiver: NULL, ok = false
third receiver: NULL, ok = false
every waiter was woken
