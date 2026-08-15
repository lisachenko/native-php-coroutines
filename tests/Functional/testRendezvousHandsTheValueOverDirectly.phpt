--TEST--
A rendezvous hands the value straight to a waiting receiver, without the sender parking
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

$scheduler->spawn(function () use ($channel): void {
    echo "receiver: parking\n";
    $value = $channel->recv();
    echo "receiver: took {$value}\n";
});

$scheduler->spawn(function () use ($channel): void {
    echo "sender: sending\n";
    $channel->send('payload');

    // The ordering is the assertion. A sender that queued the value and waited for the scheduler to
    // hand it over would print this line *after* the receiver ran; a direct handoff writes into the
    // receiver's wait node and returns in the same tick, before the receiver has run at all.
    echo "sender: returned in the same tick\n";
});

$scheduler->loop();

echo 'buffered values: ', $channel->count(), PHP_EOL;
echo 'capacity: ', $channel->capacity(), PHP_EOL;
?>
--EXPECT--
receiver: parking
sender: sending
sender: returned in the same tick
receiver: took payload
buffered values: 0
capacity: 0
