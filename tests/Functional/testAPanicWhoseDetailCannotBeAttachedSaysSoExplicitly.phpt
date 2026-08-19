--TEST--
A panic slot whose detail does not attach as a SharedError says so, instead of presenting another object as the failure
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedCounter;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The panic itself is certain — the slot settled as PANIC — but the detail travels separately, as
// the address of a shared error-info object in the task's own slot. If whatever that address
// attaches as is NOT a SharedError, the one wrong answer is to read fields off it anyway and
// present some other object's content as this task's failure. The exception must say the detail
// is unavailable instead. This rig settles a panic slot by hand with the address of a shared
// object that is not a SharedError, which is exactly what a waiter would see if a slot's error
// address ever stopped meaning "this slot's own captured panic".
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

$runtime->run(static function (TaskRuntime $self) use ($runtime): void {
    parallelDeadline(15.0, 'the detail-less panic reaching its waiter');

    // A deliberate rig: slots are settled by hand here, which is machinery the task surface
    // intentionally does not carry, so the arena is read off the concrete runtime.
    $arena = $runtime->arena();
    if ($arena === null) {
        throw new LogicException('this runtime has no arena');
    }

    $decoy = $self->persist(new SharedCounter());
    $slotId = $arena->slotTable()->allocateSlot();
    $arena->slotTable()->completePanic($slotId, $arena->addressOf($decoy));

    try {
        $self->attachResult($slotId)->await();

        echo 'the await returned, which it must not', PHP_EOL;
    } catch (ParallelTaskException $panic) {
        echo 'the panic still surfaces as a task panic: yes', PHP_EOL;
        echo 'it says the detail is unavailable: ',
            str_contains($panic->getMessage(), 'error detail is unavailable') ? 'yes' : 'no', PHP_EOL;
        echo 'no fabricated class: ', $panic->originalClass() === 'Throwable' ? 'yes' : 'no', PHP_EOL;
        echo 'no borrowed trace: ', $panic->originalTrace() === '' ? 'yes' : 'no', PHP_EOL;
    }
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the panic still surfaces as a task panic: yes
it says the detail is unavailable: yes
no fabricated class: yes
no borrowed trace: yes
children left: none
