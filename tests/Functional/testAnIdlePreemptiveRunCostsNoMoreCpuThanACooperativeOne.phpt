--TEST--
Idling with preemption armed costs about as much CPU as idling cooperatively
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

// The same idle program, run twice in this process — once cooperatively, once preemptively — and
// compared against each other rather than against an absolute number, because what is being
// measured is the *price of arming preemption* and nothing else.
//
// Measured on the reference machine over two idle seconds: cooperative 0.6 ms, preemptive 1.3 ms
// with the clock stopped for the poll, against 12.5 ms with it free-running. The budget sits
// between the two — comfortably above the ~0.7 ms that arming and uninstalling the interrupt hook
// costs a run, and comfortably below the ~12 ms that a hundred wakeups a second cost.
const IDLE_SECONDS = 2.0;
const CPU_BUDGET   = 0.004;

function consumedCpuSeconds(): float
{
    $usage = getrusage();

    return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000;
}

/**
 * One idle run: a coroutine parked on a descriptor nothing ever writes to, and a sleeper.
 *
 * @return array{float, float} CPU seconds burned, and wall seconds elapsed.
 */
function idleRun(bool $preemptive): array
{
    [$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    $runtime = new Runtime(preemptive: $preemptive);

    $cpuBefore  = consumedCpuSeconds();
    $wallBefore = hrtime(true);

    $runtime->run(static function () use ($readEnd): void {
        Coroutine::spawn(static function () use ($readEnd): void {
            Io::awaitReadable($readEnd);

            echo 'the reader must never wake', PHP_EOL;
        });

        Coroutine::sleep(IDLE_SECONDS);
    });

    $spent = [consumedCpuSeconds() - $cpuBefore, (hrtime(true) - $wallBefore) / 1_000_000_000];

    fclose($writeEnd);
    fclose($readEnd);

    return $spent;
}

[$cooperativeCpu, $cooperativeWall] = idleRun(false);
[$preemptiveCpu, $preemptiveWall]   = idleRun(true);

echo 'both runs idled for the full time: ',
    $cooperativeWall >= IDLE_SECONDS && $preemptiveWall >= IDLE_SECONDS ? 'yes' : 'no', PHP_EOL;
echo 'the cooperative run burned almost nothing: ',
    $cooperativeCpu < $cooperativeWall / 100 ? 'yes' : 'no', PHP_EOL;
echo 'preemption cost no more than the budget on top of it: ',
    $preemptiveCpu - $cooperativeCpu < CPU_BUDGET
        ? 'yes'
        : 'NO (' . number_format($preemptiveCpu - $cooperativeCpu, 6) . 's)', PHP_EOL;
?>
--EXPECT--
both runs idled for the full time: yes
the cooperative run burned almost nothing: yes
preemption cost no more than the budget on top of it: yes
