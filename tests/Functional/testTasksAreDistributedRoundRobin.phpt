--TEST--
Unpinned tasks go to the workers in turn, wrapping around the pool
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\PreforkTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\Tests\Support\PidTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

$scheduler = new Scheduler();

$tasks = new PreforkTaskDirectory();
$task  = new PidTask();
$tasks->register($task);

$supervisor = new WorkerSupervisor($scheduler, $tasks);
$supervisor->start(3);

$byPid = [];

foreach ($supervisor->workers() as $id => $worker) {
    $byPid[$worker->pid()] = $id;
}

$placement = [];

$main = $scheduler->spawn(function () use ($supervisor, $task, $byPid, &$placement): void {
    parallelDeadline(10.0, 'six round-robin tasks answering');

    $handles = [];

    // Placed before any of them is awaited, so the order is the placement policy's alone and not an
    // artefact of one worker happening to answer first.
    for ($i = 0; $i < 6; ++$i) {
        $handles[] = $supervisor->spawn($task);
    }

    foreach ($handles as $handle) {
        $placement[] = $byPid[$handle->await()] ?? -1;
    }
});

$scheduler->runUntil($main);

echo 'placement: ', implode(', ', $placement), "\n";

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
placement: 0, 1, 2, 0, 1, 2
children left: none
