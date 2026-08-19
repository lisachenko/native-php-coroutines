--TEST--
A result slot opened by the parent is awaited from inside a different worker
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\AwaitSlotTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SleepingTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

$runtime = new Runtime(workers: 2, arenaSize: 32 << 20);

// Slow enough that the awaiting worker really has to park rather than find the slot settled.
$slow = new SleepingTask(0.3, 4242);
$runtime->publishTask($slow);

$runtime->run(static function (TaskRuntime $self) use ($slow): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the cross-process await never returned');
    });

    $slotOwner = $self->spawnParallel($slow, 0);

    // The slot id is the whole handle. Worker #1 has never heard of worker #0, cannot see its
    // control socket and did not open the slot — it attaches by id and reads shared memory.
    $awaiter = $self->spawnParallel(new AwaitSlotTask($slotOwner->slotId()), 1);

    echo 'worker #1 read the slot worker #0 settled: ', $awaiter->await(), PHP_EOL;
    echo 'and the parent reads the same value: ', $slotOwner->await(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
worker #1 read the slot worker #0 settled: 4242
and the parent reads the same value: 4242
children left: none
