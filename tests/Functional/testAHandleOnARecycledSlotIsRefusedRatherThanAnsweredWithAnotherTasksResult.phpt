--TEST--
A handle on a recycled slot id is refused rather than answered with the result of the task that got the slot next
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\ConstantTask;
use Lisachenko\SharedData\Ipc\IpcException;
use Lisachenko\SharedData\Ipc\SlotTicket;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

// Recycling is only safe if a slot id stops meaning anything once its owner has given the slot
// back. This is that, deliberately: await the first task (which releases its slot), spawn a second
// one that lands on the very same record, and then attach to the FIRST task's id. The wrong answer
// here is 'second' — one task's result presented as another's, silently and plausibly. The right
// answer is a refusal naming the slot and both generations.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20, slots: 4);

$first  = new ConstantTask('first');
$second = new ConstantTask('second');

$runtime->publishTask($first);
$runtime->publishTask($second);

$runtime->run(static function (TaskRuntime $self) use ($first, $second): void {
    parallelDeadline(20.0, 'the stale handle being refused');

    $handle = $self->spawnParallel($first);
    $stale  = $handle->slotId();

    echo 'first task: ', $handle->await(), PHP_EOL;

    $reused = $self->spawnParallel($second);

    echo 'the same slot record went out again: ',
        SlotTicket::indexOf($reused->slotId()) === SlotTicket::indexOf($stale) ? 'yes' : 'no', PHP_EOL;
    echo 'under a new generation: ',
        SlotTicket::generationOf($reused->slotId()) > SlotTicket::generationOf($stale) ? 'yes' : 'no', PHP_EOL;

    try {
        $value = $self->attachResult($stale)->await();

        echo 'the stale handle was answered with ', var_export($value, true), ', which it must not', PHP_EOL;
    } catch (IpcException $refused) {
        echo 'the stale handle is refused: yes', PHP_EOL;
        echo 'it names the slot: ',
            str_contains($refused->getMessage(), 'slot ' . SlotTicket::indexOf($stale)) ? 'yes' : 'no', PHP_EOL;
        echo 'it names both generations: ',
            str_contains($refused->getMessage(), 'generation ' . SlotTicket::generationOf($stale))
            && str_contains($refused->getMessage(), 'generation ' . SlotTicket::generationOf($reused->slotId()))
                ? 'yes' : 'no', PHP_EOL;
    }

    echo 'and the task that owns the slot now is unharmed: ', $reused->await(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
first task: first
the same slot record went out again: yes
under a new generation: yes
the stale handle is refused: yes
it names the slot: yes
it names both generations: yes
and the task that owns the slot now is unharmed: second
children left: none
