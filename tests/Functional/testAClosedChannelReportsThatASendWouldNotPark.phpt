--TEST--
A closed channel reports that both a send and a receive would complete without parking
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

$report = static function (string $label) use ($channel): void {
    printf(
        "%s: canSend = %s, canRecv = %s\n",
        $label,
        var_export($channel->canSend(), true),
        var_export($channel->canRecv(), true),
    );
};

$report('open rendezvous, nobody waiting');

$scheduler->spawn(function () use ($channel): void {
    $channel->recv();
});

$scheduler->spawn(function () use ($channel, $report): void {
    $report('a receiver is parked');
    $channel->close();

    // The subtlety `select` depends on: a send on a closed channel *does* complete immediately —
    // by throwing. Reporting false here would park a select on a channel that can never progress.
    $report('closed');
});

$scheduler->loop();
?>
--EXPECT--
open rendezvous, nobody waiting: canSend = false, canRecv = false
a receiver is parked: canSend = true, canRecv = false
closed: canSend = true, canRecv = true
