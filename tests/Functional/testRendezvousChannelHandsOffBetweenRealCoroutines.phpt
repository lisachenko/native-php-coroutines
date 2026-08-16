--TEST--
A rendezvous channel hands values between coroutines driven by the real scheduler
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

include __DIR__ . '/../../vendor/autoload.php';

// The channel tests elsewhere drive a test double. This one drives the real
// Scheduler, so it is what actually proves the park/unpark protocol is
// implemented the same way on both sides of the seam: if unpark() enqueued
// internally, every wake here would schedule the coroutine twice.
//
// The interleaving below is the rendezvous semantics, not a scheduling quirk,
// and is worth reading carefully. "send 1" completes *without parking* because
// the receiver is already waiting, so the producer runs straight on to "send 2"
// and only then parks, there being no waiting receiver for it. That is why the
// output is not a strict send/recv ping-pong: a rendezvous send blocks until a
// receiver is ready, and here the first one already was.
(new Runtime())->run(function (TaskRuntime $runtime): void {
    $channel = new Channel($runtime->scheduler(), capacity: 0);

    Coroutine::spawn(function () use ($channel): void {
        foreach ([1, 2, 3] as $value) {
            echo 'send ', $value, PHP_EOL;
            $channel->send($value);
        }
        $channel->close();
    });

    foreach ($channel as $value) {
        echo 'recv ', $value, PHP_EOL;
    }

    echo 'drained, closed: ', $channel->isClosed() ? 'yes' : 'no', PHP_EOL;
});
?>
--EXPECT--
send 1
send 2
recv 1
recv 2
send 3
recv 3
drained, closed: yes

