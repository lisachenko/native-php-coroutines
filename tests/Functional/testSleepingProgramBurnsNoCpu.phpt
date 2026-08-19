--TEST--
A program that is only sleeping idles on its timer deadline instead of spinning
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

function consumedCpuSeconds(): float
{
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000;
}

$runtime = new Runtime();

$cpuBefore  = consumedCpuSeconds();
$wallBefore = hrtime(true);

$runtime->run(function (): void {
    // Three sleepers and nothing else: no descriptor is registered, so the idle turn has only the
    // earliest deadline to go on.
    foreach ([0.1, 0.2, 0.3] as $delay) {
        Coroutine::spawn(static function () use ($delay): void {
            Coroutine::sleep($delay);
        });
    }

    Coroutine::sleep(0.4);
});

$wall = (hrtime(true) - $wallBefore) / 1_000_000_000;
$cpu  = consumedCpuSeconds() - $cpuBefore;

echo 'slept the whole 0.4s: ', $wall >= 0.39 ? 'yes' : 'no', PHP_EOL;

// A scheduler that polled with a zero timeout would burn a full core for those 0.4 seconds; the
// margin here is deliberately loose, because the failure it guards against is 100%, not 10%.
echo 'cpu stayed under a tenth of the wall clock: ', $cpu < $wall / 10 ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
slept the whole 0.4s: yes
cpu stayed under a tenth of the wall clock: yes
