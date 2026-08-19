--TEST--
A settled handle that is collected without ever being awaited gives its result slot back to the family
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SumTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelWaitFor;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

// Fire-and-forget is a real pattern, and before recycling it was the worst one: every spawn nobody
// awaited took a slot out of a supply that cannot grow. A handle therefore gives its claim back at
// the first of two moments — when it is awaited, or when it is COLLECTED — and this covers the
// second. The answer in the slot is discarded, deliberately: spawning without awaiting says the
// result is not wanted, and the alternative is the exhaustion bug itself.
//
// The table holds exactly ONE slot, so the assertion needs no counters to be convincing: if the
// dropped handle's record did not come back, the second spawn could not be allocated at all.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20, slots: 1);

$task = new SumTask(20, 22);
$runtime->publishTask($task);

$runtime->run(static function (TaskRuntime $self) use ($runtime, $task): void {
    parallelDeadline(20.0, 'an unawaited handle giving its slot back');

    $slots  = $runtime->arena()?->slotTable();
    $handle = $self->spawnParallel($task);

    // Settled but never awaited. A slot that is still pending is never recycled - a worker that has
    // not answered yet may still write to it - so the drop has to happen after it has settled for
    // this to be about the handle rather than about timing.
    parallelWaitFor(static fn(): bool => $handle->isComplete(), 10.0);

    echo 'settled, never awaited - slots outstanding: ', $slots?->outstanding(), PHP_EOL;

    unset($handle);

    echo 'after the handle is collected:            ', $slots?->outstanding(), PHP_EOL;

    // Only reachable if the record really went back on the free list
    echo 'a second task gets the recycled record:   ', $self->spawnParallel($task)->await(), PHP_EOL;
    echo 'slot records ever created:                ', $slots?->highWaterMark(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
settled, never awaited - slots outstanding: 1
after the handle is collected:            0
a second task gets the recycled record:   42
slot records ever created:                1
children left: none
