--TEST--
The local view of a result slot is dropped once the handle that could still read it is gone
--INI--
ffi.enable=1
opcache.jit=off
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

// The answer lives in the slot, not in the table's entry for it, so the entry is worth keeping only
// while something in this process can still ask. Keeping settled entries instead costs a ResultSlot
// per spawn for the whole run — invisible to memory_get_usage(true) at its 2 MiB chunk granularity
// and free of arena memory, so it surfaces only as the parent's RSS climbing (issue #24).
//
// A handle gives its claim back at the FIRST of two moments: when it is awaited, or when it is
// collected. So the view is already gone on the line after await() and dropping the handle changes
// nothing — that path is covered by testAnUnawaitedHandleGivesItsSlotBackWhenItIsCollected.phpt,
// which is the case the destructor still exists for.

$scheduler = new Scheduler();

$tasks = new PreforkTaskDirectory();
$task  = new SumTask(20, 22);
$tasks->register($task);

$supervisor = new WorkerSupervisor($scheduler, $tasks);
$supervisor->start(1);

$main = $scheduler->spawn(function () use ($supervisor, $task): void {
    parallelDeadline(5.0, 'the result slot bookkeeping');

    $slots = $supervisor->slots();

    $handle = $supervisor->spawn($task);
    echo 'views while the result is outstanding: ', $slots->liveViews(), "\n";

    echo 'result: ', $handle->await(), "\n";
    echo 'views after await, handle still held: ', $slots->liveViews(), "\n";

    unset($handle);
    echo 'views after the handle is dropped:    ', $slots->liveViews(), "\n";


    // The steady state the soak drives: nothing is retained, however many round trips happen.
    for ($round = 0; $round < 8; ++$round) {
        $supervisor->spawn($task)->await();
    }

    echo 'views after 8 more round trips:       ', $slots->liveViews(), "\n";
});

$scheduler->runUntil($main);

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
views while the result is outstanding: 1
result: 42
views after await, handle still held: 0
views after the handle is dropped:    0
views after 8 more round trips:       0
children left: none
