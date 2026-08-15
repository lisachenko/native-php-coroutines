--TEST--
foreach over a channel yields until it is closed and drained
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
    foreach ($channel as $key => $value) {
        echo "consumed [{$key}] {$value}\n";
    }

    // Reached only because close() ended the iteration; a foreach that needed a sentinel value
    // would have consumed one here.
    echo "the loop ended on its own\n";
});

$scheduler->spawn(function () use ($channel): void {
    $channel->send('alpha');
    $channel->send('beta');
    $channel->close();
});

$scheduler->loop();
?>
--EXPECT--
consumed [0] alpha
consumed [1] beta
the loop ended on its own
