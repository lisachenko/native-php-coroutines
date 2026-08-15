--TEST--
A coroutine parked on stream readiness never triggers a deadlock, however long it waits
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

[$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$runtime = new Runtime();

try {
    $runtime->run(function () use ($writeEnd, $readEnd): void {
        Coroutine::spawn(function () use ($readEnd): void {
            Io::awaitReadable($readEnd);
            echo 'reader: ', fread($readEnd, 7), PHP_EOL;
        });

        // The reader is the only outstanding wait for the whole of this sleep, and it is blocked
        // on a descriptor: an idle server looks exactly like this, and reporting it as a deadlock
        // would make the runtime useless for the thing it exists to do.
        Coroutine::sleep(0.05);
        echo 'still alive with only an IO wait outstanding', PHP_EOL;

        // Now the timer is gone too: the poller blocks on the descriptor alone, with no deadline
        // to fall back on, which is the case a naive detector calls a deadlock.
        Coroutine::spawn(function () use ($writeEnd): void {
            Coroutine::sleep(0.05);
            fwrite($writeEnd, 'arrived');
        });

        Io::awaitReadable($readEnd);
        echo 'main woke on the same descriptor', PHP_EOL;
    });
} catch (DeadlockException $deadlock) {
    echo 'FAILED with a false deadlock: ', $deadlock->getMessage(), PHP_EOL;
}

fclose($writeEnd);
fclose($readEnd);
?>
--EXPECT--
still alive with only an IO wait outstanding
reader: arrived
main woke on the same descriptor
