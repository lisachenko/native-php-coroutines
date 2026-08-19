--TEST--
A pool spawning far more tasks than the slot supply runs to completion, reusing the same few slots
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\ConstantTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

// Result slots are pre-sized in the arena and the arena never grows, so before recycling a pool
// simply ran out: ten spawns a second exhausted the default 4096 in under seven minutes however few
// were ever in flight. A slot now goes back on the substrate's free list as soon as its handle has
// taken the answer, which makes the supply a limit on CONCURRENCY rather than on throughput.
//
// Eight slots, a hundred tasks, awaited one at a time: the run has to finish, and it has to finish
// using one slot record over and over rather than a hundred.
$runtime = new Runtime(workers: 2, arenaSize: 32 << 20, slots: 8);

$task = new ConstantTask(7);
$runtime->publishTask($task);

$runtime->run(static function (TaskRuntime $self) use ($runtime, $task): void {
    parallelDeadline(30.0, 'a hundred tasks over eight slots');

    $sum = 0;
    for ($index = 0; $index < 100; ++$index) {
        $sum += $self->spawnParallel($task)->await();
    }

    $slots = $runtime->arena()?->slotTable();

    echo 'tasks completed: ', intdiv($sum, 7), PHP_EOL;
    echo 'slot records ever created: ', $slots?->highWaterMark(), PHP_EOL;
    echo 'slots still outstanding: ', $slots?->outstanding(), PHP_EOL;
    echo 'slots recycled: ', $slots?->recycled(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
tasks completed: 100
slot records ever created: 1
slots still outstanding: 0
slots recycled: 99
children left: none
