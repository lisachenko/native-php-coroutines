--TEST--
Coroutines parked on different descriptors wake independently, in readiness order
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

[$writeOne, $readOne] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
[$writeTwo, $readTwo] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$runtime = new Runtime();

$runtime->run(function () use ($writeOne, $readOne, $writeTwo, $readTwo): void {
    Coroutine::spawn(function () use ($readOne): void {
        Io::awaitReadable($readOne);
        echo 'one: ', fread($readOne, 5), PHP_EOL;
    });

    Coroutine::spawn(function () use ($readTwo): void {
        Io::awaitReadable($readTwo);
        echo 'two: ', fread($readTwo, 5), PHP_EOL;
    });

    // The second descriptor is fed first: readiness, not registration order, decides who runs.
    Coroutine::sleep(0.02);
    fwrite($writeTwo, 'bravo');

    Coroutine::sleep(0.02);
    echo 'the first reader is still parked', PHP_EOL;
    fwrite($writeOne, 'alpha');

    Coroutine::sleep(0.02);
    echo 'both readers are done', PHP_EOL;
});

fclose($writeOne);
fclose($readOne);
fclose($writeTwo);
fclose($readTwo);
?>
--EXPECT--
two: bravo
the first reader is still parked
one: alpha
both readers are done
