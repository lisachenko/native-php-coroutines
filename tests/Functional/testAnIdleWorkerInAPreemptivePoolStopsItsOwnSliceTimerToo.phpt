--TEST--
A worker waiting for its next record wakes as rarely as an idle single-process runtime
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\ReportsIdlePollerWakeupsTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Every child of a preemptive pool arms its own interval timer after the fork — it has to, because
// fork() clears it — and a worker waiting on its inbox is the longest-lived idle in this design.
// So the child owes the same restraint as the parent, and only the child can report it: the count
// is taken inside the worker, against the worker's own poller.
const IDLE_SECONDS = 1.0;
const BOUND        = 5;

$runtime = new Runtime(workers: 1, preemptive: true, arenaSize: 32 << 20);

$probe = new ReportsIdlePollerWakeupsTask(IDLE_SECONDS);
$runtime->publishTask($probe);

$runtime->run(static function (TaskRuntime $self) use ($probe): void {
    Timer::after(45.0, static function (): void {
        throw new RuntimeException('deadline: the worker never reported its wakeups');
    });

    $wakeups = $self->spawnParallel($probe)->await();

    echo 'the worker reported a count: ', is_int($wakeups) && $wakeups >= 0 ? 'yes' : 'no', PHP_EOL;
    echo 'its wakeups over an idle second: ',
        is_int($wakeups) && $wakeups <= BOUND ? 'far below 100' : 'NO (' . get_debug_type($wakeups) . ' ' . $wakeups . ')',
        PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the worker reported a count: yes
its wakeups over an idle second: far below 100
children left: none
