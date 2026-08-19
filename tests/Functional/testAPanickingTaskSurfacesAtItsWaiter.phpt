--TEST--
A task that ends in an uncaught throwable fails its waiter and leaves the worker serving
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException;
use Lisachenko\NativePhpCoroutines\Parallel\PreforkTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\Tests\Support\PanickingTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SumTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

$scheduler = new Scheduler();

$tasks     = new PreforkTaskDirectory();
$panicking = new PanickingTask();
$sum       = new SumTask(3, 4);
$tasks->register($panicking);
$tasks->register($sum);

$supervisor = new WorkerSupervisor($scheduler, $tasks);
$supervisor->start(1);

$caught = null;
$after  = null;

$main = $scheduler->spawn(function () use ($supervisor, $panicking, $sum, &$caught, &$after): void {
    parallelDeadline(10.0, 'the panic reaching the waiter');

    try {
        $supervisor->spawn($panicking)->await();

        echo "await returned, which it must not\n";
    } catch (ParallelTaskException $panic) {
        $caught = $panic;
    }

    // A panicking task is not a dying worker: the same worker takes the next piece of work.
    $after = $supervisor->spawn($sum, 0)->await();
});

$scheduler->runUntil($main);

echo 'class: ', $caught === null ? 'none' : $caught::class, "\n";
echo 'worker: ', $caught?->workerId(), "\n";
echo 'message: ', $caught?->getMessage(), "\n";
echo 'the worker kept serving: ';
var_dump($after);

// A panic is the task's failure, not the worker's, so nothing here counts as a crash.
echo 'crashes recorded: ', count($supervisor->crashes()), "\n";

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
class: Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException
worker: 0
message: task panicked in worker #0: Throwable: the class, message and trace of the original throwable travel in the arena's shared error-info object, which lands with #7 — they are never serialized onto the control socket
the worker kept serving: int(7)
crashes recorded: 0
children left: none
