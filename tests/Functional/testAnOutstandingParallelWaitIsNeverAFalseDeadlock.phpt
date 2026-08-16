--TEST--
A coroutine waiting on a JoinHandle or a shared channel is not reported as a deadlock
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedSendTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SleepingTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Deadlock detection fires when nothing runnable is left and nothing *local* could ever make one
// runnable again. A wait on another process is exactly the case that must be excluded: the value is
// coming, it is simply not this scheduler's to produce. Both parks below are therefore marked
// externally wakeable, and neither has a timer keeping the scheduler busy on its behalf — so a
// missing exclusion would end this run with a DeadlockException instead of the lines below.
$runtime = new Runtime(workers: 2, arenaSize: 32 << 20);
$runtime->declareShared('jobs', SharedChannel::class, 4);

$slow     = new SleepingTask(0.3, 7);
$producer = new SharedSendTask('jobs', 1, 'late-');
$runtime->publishTask($slow);
$runtime->publishTask($producer);

$runtime->run(static function (TaskRuntime $self) use ($slow, $producer): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the parallel waits never completed');
    });

    $shared = $self->shared('jobs');
    $handle = $self->spawnParallel($slow, 0);

    $self->spawnParallel($producer, 1);

    $group   = new WaitGroup($self->scheduler());
    $results = [];

    $group->add(2);

    Coroutine::spawn(static function () use ($handle, $group, &$results): void {
        $results[] = 'the join handle answered: ' . $handle->await();
        $group->done();
    });

    Coroutine::spawn(static function () use ($shared, $group, &$results): void {
        $results[] = 'the shared channel answered: ' . $shared->recv();
        $group->done();
    });

    $group->wait();

    sort($results);

    echo implode(PHP_EOL, $results), PHP_EOL;
    echo 'no deadlock was reported: yes', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the join handle answered: 7
the shared channel answered: late-0
no deadlock was reported: yes
children left: none
