--TEST--
A local channel exposes no readiness descriptor, because nothing outside the process can change it
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$channel   = new Channel($scheduler, 1);

// The scheduler already knows when a local channel becomes ready: only its own coroutines can make
// it so. A descriptor here would mean a trip through the poller for a wakeup the process caused
// itself. (The test poller throws on any registration, so a channel that reached for one would fail
// the whole run rather than quietly work.)
var_dump($channel->readinessFd());

$scheduler->spawn(function () use ($channel): void {
    $channel->send('value');
    echo 'still no descriptor: ', var_export($channel->readinessFd(), true), "\n";
});

$scheduler->spawn(function () use ($channel): void {
    $channel->recv();
});

$scheduler->loop();

echo 'poller watches anything: ', var_export($scheduler->poller()->hasWatches(), true), PHP_EOL;
?>
--EXPECT--
NULL
still no descriptor: NULL
poller watches anything: false
