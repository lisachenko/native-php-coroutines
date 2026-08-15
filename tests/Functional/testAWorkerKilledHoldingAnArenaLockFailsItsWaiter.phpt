--TEST--
A worker killed while it holds an arena lock fails its waiter instead of hanging it
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\Tests\Support\HoldArenaLockTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedCounter;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The lock is a robust process-shared mutex, so a SIGKILLed owner hands it on as EOWNERDEAD rather
// than as an eternal EBUSY. *Recovering* it is the substrate's job; *surfacing* it is this
// package's — a result that can never arrive has to become a throw at the waiter, never a coroutine
// parked for the rest of the run.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('counter', SharedCounter::class);

$hold = new HoldArenaLockTask('counter');
$runtime->publishTask($hold);

$runtime->run(static function (RuntimeInterface $self) use ($hold): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the waiter hung on a slot that can never complete');
    });

    $handle = $self->spawnParallel($hold, 0);

    Timer::after(0.5, static function () use ($self): void {
        $self->supervisor()?->worker(0)->signal(SIGKILL);
    });

    try {
        $handle->await();

        echo 'the await returned, which it must not', PHP_EOL;
    } catch (WorkerCrashedException $crash) {
        echo 'the waiter was failed: yes', PHP_EOL;
        echo 'slots named as lost: ', count($crash->abandonedSlots()), PHP_EOL;
    }

    // Nothing was consumed as if it were an answer, and the stripe the dead worker held is usable
    // again: the write below takes exactly that lock, inheriting it as EOWNERDEAD and declaring it
    // consistent. A poisoned mutex would fail here instead.
    $counter = $self->shared('counter');
    $handleW = $self->arena()?->store()->mutableHandle($counter);
    $handleW?->writeScalar('value', 99);

    echo 'the stripe is usable after the crash: ', $counter->value === 99 ? 'yes' : 'no', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the waiter was failed: yes
slots named as lost: 1
the stripe is usable after the crash: yes
children left: none
