--TEST--
Io::awaitWritable parks on a full socket buffer and wakes when the far end drains it
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

[$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
stream_set_blocking($writeEnd, false);
stream_set_blocking($readEnd, false);

// Fill the socket's send buffer, so the descriptor is genuinely not writable. The loop is bounded
// twice over — by the chunk count and by the first short write — so a kernel with a larger buffer
// than expected makes the test slower, never endless.
$chunk = str_repeat('x', 64 * 1024);
for ($i = 0; $i < 256; ++$i) {
    $written = @fwrite($writeEnd, $chunk);
    if ($written === false || $written < strlen($chunk)) {
        break;
    }
}

$stillWritable = [];
$writable      = [$writeEnd];
$except        = [];
stream_select($stillWritable, $writable, $except, 0, 0);
echo 'buffer is full: ', $writable === [] ? 'yes' : 'no', PHP_EOL;

$runtime = new Runtime();

$runtime->run(function () use ($writeEnd, $readEnd): void {
    $writer = Coroutine::spawn(function () use ($writeEnd): void {
        echo 'writer: parking', PHP_EOL;
        Io::awaitWritable($writeEnd);
        echo 'writer: wrote ', (int) fwrite($writeEnd, 'ping'), ' bytes', PHP_EOL;
    });

    Coroutine::sleep(0.02);

    // The writer has been parked on the poller for the whole sleep, and reports it as such. The
    // wait names the descriptor, whose id is allocated by the engine and therefore not printed.
    echo 'writer is ', $writer->status()->name, PHP_EOL;
    echo 'writer waits on: ', preg_replace('/#\d+$/', '#N', (string) $writer->waitDescription()), PHP_EOL;
    echo 'writer is externally wakeable: ', $writer->isExternallyWakeable() ? 'yes' : 'no', PHP_EOL;

    echo 'main: draining', PHP_EOL;
    while (is_string($read = fread($readEnd, 1024 * 1024)) && $read !== '') {
        // Freeing send-buffer space is what makes the descriptor writable again.
    }

    Coroutine::sleep(0.02);
    echo 'main: done', PHP_EOL;
});

fclose($writeEnd);
fclose($readEnd);
?>
--EXPECT--
buffer is full: yes
writer: parking
writer is BLOCKED
writer waits on: IO write on stream #N
writer is externally wakeable: yes
main: draining
writer: wrote 4 bytes
main: done
