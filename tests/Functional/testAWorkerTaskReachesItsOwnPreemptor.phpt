--TEST--
A task in a preemptive pool reaches the worker's own armed preemptor through the task surface
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\ReportsPreemptorTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The worker's preemptor does not exist until after the fork — it is built against the child's own
// scheduler by the second seam. An accessor that reported the runtime's constructor state would
// answer null inside every worker of a preemptive pool, and a task could never mark a critical
// section. The report has to come from inside a worker, through the type a task is handed.
$runtime = new Runtime(workers: 1, preemptive: true, arenaSize: 32 << 20);

$probe = new ReportsPreemptorTask();
$runtime->publishTask($probe);

$runtime->run(static function (TaskRuntime $self) use ($probe): void {
    Timer::after(30.0, static function (): void {
        throw new RuntimeException('deadline: the worker never reported on its preemptor');
    });

    echo 'the task saw an armed preemptor in its worker: ',
        $self->spawnParallel($probe)->await() === true ? 'yes' : 'no', PHP_EOL;
});

echo 'the parent reports one too: ', $runtime->preemptor() !== null ? 'yes' : 'no', PHP_EOL;
echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the task saw an armed preemptor in its worker: yes
the parent reports one too: yes
children left: none
