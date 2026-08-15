--TEST--
With a descriptor registered, the idle turn blocks in stream_select for the timer deadline
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

function consumedCpuSeconds(): float
{
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000;
}

[$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$runtime = new Runtime();

$cpuBefore  = consumedCpuSeconds();
$wallBefore = hrtime(true);

$runtime->run(function () use ($readEnd): void {
    // Nothing is ever written to this descriptor. It exists so the poller has a real select set:
    // the timeout still has to come from the timer heap, and the whole idle turn happens inside
    // one stream_select() call rather than a loop around it.
    Coroutine::spawn(static function () use ($readEnd): void {
        Io::awaitReadable($readEnd);

        echo 'the reader must never wake', PHP_EOL;
    });

    Coroutine::sleep(0.4);
});

$wall = (hrtime(true) - $wallBefore) / 1_000_000_000;
$cpu  = consumedCpuSeconds() - $cpuBefore;

echo 'the select timed out on the deadline: ', $wall >= 0.39 && $wall < 1.0 ? 'yes' : 'no', PHP_EOL;
echo 'cpu stayed under a tenth of the wall clock: ', $cpu < $wall / 10 ? 'yes' : 'no', PHP_EOL;

fclose($writeEnd);
fclose($readEnd);
?>
--EXPECT--
the select timed out on the deadline: yes
cpu stayed under a tenth of the wall clock: yes
