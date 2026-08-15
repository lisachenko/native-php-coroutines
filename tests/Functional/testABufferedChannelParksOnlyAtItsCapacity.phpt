--TEST--
A buffered send parks only when the buffer is full, and a receive only when it is empty
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
$channel   = new Channel($scheduler, 2);

$producer = $scheduler->spawn(function () use ($channel): void {
    foreach ([1, 2, 3] as $value) {
        $channel->send($value);
        echo "sent {$value}, buffered: ", $channel->count(), "\n";
    }
});

$scheduler->spawn(function () use ($channel, $producer): void {
    // The producer filled the buffer and is parked on the third value; the buffer is exactly the
    // capacity, never more.
    echo 'producer after filling the buffer: ', $producer->status()->name, "\n";
    echo 'buffered: ', $channel->count(), "\n";

    foreach ([1, 2, 3] as $ignored) {
        echo 'received ', $channel->recv(), "\n";
    }

    // The fourth receive has nothing left, so this one parks — and nobody will ever send again,
    // which the scheduler reports as the deadlock it is.
    echo "receiving from an empty channel\n";
    $channel->recv();
    echo "NOT REACHED\n";
});

try {
    $scheduler->loop();
} catch (Throwable $failure) {
    echo explode("\n", $failure->getMessage())[0], PHP_EOL;
}
?>
--EXPECT--
sent 1, buffered: 1
sent 2, buffered: 2
producer after filling the buffer: BLOCKED
buffered: 2
received 1
received 2
received 3
receiving from an empty channel
sent 3, buffered: 0
all coroutines are asleep - deadlock!
