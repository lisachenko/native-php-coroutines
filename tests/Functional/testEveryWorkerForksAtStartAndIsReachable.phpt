--TEST--
Every worker is forked eagerly by start() and every one of them can be reached with work
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

// Prefork: all three exist before any work does, and before this process owns a single fiber.
$supervisor->start(3);

$parentPid = posix_getpid();
$pids      = [];

foreach ($supervisor->workers() as $id => $worker) {
    $pids[$id] = $worker->pid();
}

echo 'workers: ', count($pids), "\n";
echo 'all alive: ', count(array_filter($supervisor->workers(), fn ($w): bool => $w->isAlive())), "\n";
echo 'all distinct from the parent: ', count(array_filter($pids, fn (int $pid): bool => $pid !== $parentPid)), "\n";

$reported = [];

$main = $scheduler->spawn(function () use ($supervisor, $task, &$reported): void {
    parallelDeadline(5.0, 'every worker answering a pinned task');

    // Pinning proves reachability one worker at a time: a round-robin run could have been answered
    // three times by the same process.
    foreach (array_keys($supervisor->workers()) as $id) {
        $reported[$id] = $supervisor->spawn($task, $id)->await();
    }
});

$scheduler->runUntil($main);

echo 'answered by the pinned worker: ', count(array_filter(
    $reported,
    fn (mixed $pid, int $id): bool => $pid === $pids[$id],
    ARRAY_FILTER_USE_BOTH,
)), "\n";

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
workers: 3
all alive: 3
all distinct from the parent: 3
answered by the pinned worker: 3
children left: none
