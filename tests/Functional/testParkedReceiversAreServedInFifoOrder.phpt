--TEST--
Parked receivers are served in the order they arrived
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
        $value = $channel->recv();
        echo "{$name} receiver took {$value}\n";
    });
}

$scheduler->spawn(function () use ($channel): void {
    echo 'parked receivers: ', $channel->pendingReceivers(), "\n";

    // The values are labelled by arrival order, so a LIFO queue — which would starve whoever waited
    // longest — is visible immediately in the pairing.
    $channel->send('value 1');
    $channel->send('value 2');
    $channel->send('value 3');
});

$scheduler->loop();
?>
--EXPECT--
parked receivers: 3
first receiver took value 1
second receiver took value 2
third receiver took value 3
