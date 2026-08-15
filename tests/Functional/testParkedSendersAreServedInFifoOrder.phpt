--TEST--
Parked senders are served in the order they arrived
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
        $channel->send("value from the {$name} sender");
        echo "{$name} sender resumed\n";
    });
}

$scheduler->spawn(function () use ($channel): void {
    echo 'parked senders: ', $channel->pendingSenders(), "\n";

    foreach ([1, 2, 3] as $ignored) {
        echo 'received ', $channel->recv(), "\n";
    }
});

$scheduler->loop();
?>
--EXPECT--
parked senders: 3
received value from the first sender
received value from the second sender
received value from the third sender
first sender resumed
second sender resumed
third sender resumed
