--TEST--
A closed channel keeps delivering buffered values with ok = true, and only then reports exhaustion
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
$channel   = new Channel($scheduler, 3);

$scheduler->spawn(function () use ($channel): void {
    $channel->send('a');
    $channel->send('b');
    $channel->close();

    echo 'closed with ', $channel->count(), " values still buffered\n";

    foreach (range(1, 3) as $attempt) {
        [$value, $ok] = $channel->recvOk();
        printf("attempt %d: %s, ok = %s\n", $attempt, var_export($value, true), var_export($ok, true));
    }

    // A legitimately sent null is indistinguishable from exhaustion through recv() alone, which is
    // the whole reason recvOk() exists.
    echo 'recv() alone: ', var_export($channel->recv(), true), "\n";
});

$scheduler->loop();
?>
--EXPECT--
closed with 2 values still buffered
attempt 1: 'a', ok = true
attempt 2: 'b', ok = true
attempt 3: NULL, ok = false
recv() alone: NULL
