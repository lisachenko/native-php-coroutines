--TEST--
A task pinned to a worker always runs there, and pinning to a worker that is not there is refused
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;
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

$pinnedPid = $supervisor->worker(2)->pid();
$answers   = [];
$refusals  = [];

$main = $scheduler->spawn(function () use ($supervisor, $task, &$answers, &$refusals): void {
    parallelDeadline(10.0, 'four pinned tasks answering');

    // Round-robin would have spread these across all three; pinning overrides the cursor entirely,
    // and does not move it either.
    for ($i = 0; $i < 4; ++$i) {
        $answers[] = $supervisor->spawn($task, 2)->await();
    }

    try {
        $supervisor->spawn($task, 9);
    } catch (InvalidArgumentException $refused) {
        $refusals[] = $refused->getMessage();
    }

    $supervisor->worker(1)->terminate();

    try {
        $supervisor->spawn($task, 1);
    } catch (WorkerCrashedException $refused) {
        $refusals[] = $refused->getMessage();
    }
});

$scheduler->runUntil($main);

echo 'all four ran in the pinned worker: ', count(array_filter(
    $answers,
    fn (mixed $pid): bool => $pid === $pinnedPid,
)), "\n";

foreach ($refusals as $refusal) {
    echo 'refused: ', $refusal, "\n";
}

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
all four ran in the pinned worker: 4
refused: there is no worker #9 in a pool of 3
refused: worker #1 died: the worker is not running
children left: none
