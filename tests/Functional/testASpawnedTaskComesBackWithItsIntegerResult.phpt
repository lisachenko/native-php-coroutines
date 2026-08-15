--TEST--
A task dispatched to a worker runs there and its integer result comes back to the waiter
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\PreforkTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\Tests\Support\SumTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

$scheduler = new Scheduler();

// Published before the fork, so the worker inherits it and can resolve it by address. That is the
// seam the shared arena takes over in #7 — the record on the wire is the same either way.
$tasks = new PreforkTaskDirectory();
$task  = new SumTask(20, 22);
$tasks->register($task);

$supervisor = new WorkerSupervisor($scheduler, $tasks);
$supervisor->start(1);

$result = null;

$main = $scheduler->spawn(function () use ($supervisor, $task, &$result): void {
    parallelDeadline(5.0, 'the result of the task');

    $handle = $supervisor->spawn($task);

    echo 'complete before await: ', $handle->isComplete() ? 'yes' : 'no', "\n";

    $result = $handle->await();
});

$scheduler->runUntil($main);

echo 'result: ';
var_dump($result);

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
complete before await: no
result: int(42)
children left: none
