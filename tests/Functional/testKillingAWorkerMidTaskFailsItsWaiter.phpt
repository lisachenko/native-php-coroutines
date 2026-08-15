--TEST--
SIGKILLing a worker in the middle of a task fails the parked waiter instead of hanging it
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;
use Lisachenko\NativePhpCoroutines\Parallel\PreforkTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\Tests\Support\SleepingTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

$scheduler = new Scheduler();

$tasks = new PreforkTaskDirectory();
$task  = new SleepingTask(30.0, 1);
$tasks->register($task);

$supervisor = new WorkerSupervisor($scheduler, $tasks);
$supervisor->start(1);

$caught = null;
$slots  = [];

$main = $scheduler->spawn(function () use ($supervisor, $task, &$caught, &$slots): void {
    // Without a deadline this test would be the worst kind of failure: one that never ends. The
    // whole point of the crash path is that it beats this timer to the finish.
    parallelDeadline(10.0, 'the crash reaching the waiter');

    $handle = $supervisor->spawn($task);

    Timer::after(0.05, static function () use ($supervisor): void {
        posix_kill($supervisor->worker(0)->pid(), SIGKILL);
    });

    try {
        $handle->await();

        echo "await returned, which it must not\n";
    } catch (WorkerCrashedException $crash) {
        $caught = $crash;
        $slots  = $crash->abandonedSlots();
    }
});

$scheduler->runUntil($main);

echo 'message: ', $caught?->getMessage(), "\n";
echo 'worker: ', $caught?->workerId(), "\n";
echo 'abandoned slots: ', implode(', ', $slots), "\n";

// The supervisor keeps the crash for anybody who was not parked on a slot at the time.
echo 'crashes recorded: ', count($supervisor->crashes()), "\n";

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
message: worker #0 died: killed by signal 9; 1 result slot(s) can never complete: #1
worker: 0
abandoned slots: 1
crashes recorded: 1
children left: none
