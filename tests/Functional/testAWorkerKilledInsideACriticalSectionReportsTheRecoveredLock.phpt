--TEST--
A worker killed inside an arena critical section is reported as a recovered lock, not just a signal
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\HoldResultSlotLockTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelWaitFor;
use function Lisachenko\NativePhpCoroutines\Tests\Support\resultSlotTableMutex;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Recovery is the substrate's job; *reporting* it is this package's, and the report is only truthful
// if the parent has actually taken the lock the dead worker held. Killing a worker that holds a
// stripe nothing in the bury path touches proves the waiter fails — it does not exercise the
// EOWNERDEAD branch, because the supervisor never recovers anything and says "killed by signal 9".
// So the worker here dies inside the one critical section the supervisor itself enters on the way to
// the grave: the result-slot table's. Its `refresh()` inherits the mutex as EOWNERDEAD, and the
// crash the waiter receives says so.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

$hold = new HoldResultSlotLockTask();
$runtime->publishTask($hold);

$runtime->run(static function (TaskRuntime $self) use ($hold, $runtime): void {
    parallelDeadline(20.0, 'the waiter on a slot that can never complete');

    // Diagnostics are read off the concrete runtime, not the task surface — arena() and
    // supervisor() are deliberately absent from TaskRuntime (see the surface guard test).
    $arena = $runtime->arena();

    if ($arena === null) {
        throw new RuntimeException('this runtime mapped no arena');
    }

    $handle = $self->spawnParallel($hold, 0);

    $raw   = $arena->arena();
    $mutex = resultSlotTableMutex($arena);

    // Wait for the worker to be genuinely inside the critical section before killing it. `tryLock`
    // never blocks, so this poll cannot wedge the parent on a mutex a live worker holds — and a lock
    // it does take is handed straight back. Once the poll reports "busy", the parent must not touch
    // the mutex again: the next taker has to be the supervisor's own refresh(), whose recovery is
    // the thing under test.
    $held = parallelWaitFor(static function () use ($raw, $mutex): bool {
        if ($raw->tryLockMutexAt($mutex)) {
            $raw->unlockMutexAt($mutex);

            return false;
        }

        return true;
    }, 10.0);

    echo 'the worker holds the result-slot lock: ', $held ? 'yes' : 'no', PHP_EOL;

    $worker = $runtime->supervisor()?->worker(0);
    $worker?->signal(SIGKILL);

    // Bounded, and outside the scheduler on purpose: awaiting the handle before the process is
    // actually gone would park the parent on a lock its owner is still alive to hold.
    $gone = parallelWaitFor(static fn(): bool => $worker?->isAlive() !== true, 10.0);

    echo 'the worker is gone before the parent reads a slot: ', $gone ? 'yes' : 'no', PHP_EOL;

    try {
        $handle->await();

        echo 'the await returned, which it must not', PHP_EOL;
    } catch (WorkerCrashedException $crash) {
        echo $crash->getMessage(), PHP_EOL;
    }
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the worker holds the result-slot lock: yes
the worker is gone before the parent reads a slot: yes
worker #0 died: it died holding an arena lock (EOWNERDEAD); the lock was recovered, but whatever it was writing is not an answer; 1 result slot(s) can never complete: #0/gen1
children left: none
