--TEST--
A signal arriving during stream_select is retried, with the remaining timeout rather than a fresh one
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

$interrupted = 0;

// Test-side only: the library never installs a signal handler in Layer 1. `restart_syscalls: false`
// is what makes the kernel report EINTR to the select instead of restarting it silently.
pcntl_async_signals(true);
pcntl_signal(SIGALRM, function () use (&$interrupted): void {
    ++$interrupted;
}, false);

$runtime = new Runtime();

$wallBefore = hrtime(true);

$runtime->run(function () use ($writeEnd, $readEnd): void {
    Coroutine::spawn(function () use ($readEnd): void {
        Io::awaitReadable($readEnd);
        echo 'reader woke with: ', fread($readEnd, 5), PHP_EOL;
    });

    // The alarm lands one second into a two-second select. If the interruption were fatal the run
    // would die here; if the retry restarted the timeout, the writer below would fire at three
    // seconds instead of two.
    pcntl_alarm(1);

    Coroutine::sleep(2.0);
    fwrite($writeEnd, 'after');

    Coroutine::sleep(0.02);
});

$wall = (hrtime(true) - $wallBefore) / 1_000_000_000;

echo 'the select was interrupted: ', $interrupted > 0 ? 'yes' : 'no', PHP_EOL;
echo 'the remaining timeout was kept: ', $wall >= 1.9 && $wall < 2.9 ? 'yes' : 'no', PHP_EOL;

fclose($writeEnd);
fclose($readEnd);
?>
--EXPECT--
reader woke with: after
the select was interrupted: yes
the remaining timeout was kept: yes
