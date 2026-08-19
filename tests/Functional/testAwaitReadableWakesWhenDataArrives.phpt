--TEST--
Io::awaitReadable parks the coroutine and wakes it exactly when data arrives
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

[$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
stream_set_blocking($readEnd, false);

$runtime = new Runtime();

$runtime->run(function () use ($writeEnd, $readEnd): void {
    $reader = Coroutine::spawn(function () use ($readEnd): void {
        echo 'reader: parking', PHP_EOL;
        Io::awaitReadable($readEnd);
        echo 'reader: ', fread($readEnd, 4), PHP_EOL;
    });

    Coroutine::sleep(0.02);

    // The reader has been parked on the poller for the whole sleep, and reports it as such. The
    // wait names the descriptor, whose id is allocated by the engine and therefore not printed.
    echo 'reader is ', $reader->status()->name, PHP_EOL;
    echo 'reader waits on: ', preg_replace('/#\d+$/', '#N', (string) $reader->waitDescription()), PHP_EOL;
    echo 'reader is externally wakeable: ', $reader->isExternallyWakeable() ? 'yes' : 'no', PHP_EOL;

    echo 'main: writing', PHP_EOL;
    fwrite($writeEnd, 'ping');

    Coroutine::sleep(0.02);
    echo 'main: done', PHP_EOL;
});

fclose($writeEnd);
fclose($readEnd);
?>
--EXPECT--
reader: parking
reader is BLOCKED
reader waits on: IO read on stream #N
reader is externally wakeable: yes
main: writing
reader: ping
main: done
