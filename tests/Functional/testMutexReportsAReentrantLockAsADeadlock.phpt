--TEST--
Locking a Mutex the calling coroutine already holds is reported as a deadlock
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;
use Lisachenko\NativePhpCoroutines\Sync\Mutex;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$mutex     = new Mutex($scheduler);

$scheduler->spawn(function () use ($mutex): void {
    $mutex->lock();

    // A second lock could only ever wait for this coroutine to release, which it cannot do while it
    // is waiting. Parking here would hang the process and then blame whichever coroutine the
    // scheduler happened to notice first; the mistake is reported at the call that made it instead.
    try {
        $mutex->lock();
        echo "NOT REACHED\n";
    } catch (DeadlockException $failure) {
        echo explode("\n", $failure->getMessage())[0], "\n";

        foreach ($failure->blockedCoroutines() as $blocked) {
            echo $blocked['wait'], "\n";
        }
    }

    // tryLock() is the non-throwing question, and the honest answer to a reentrant attempt is that
    // the lock is not available to this caller.
    echo 'tryLock while holding it: ', var_export($mutex->tryLock(), true), "\n";

    $mutex->unlock();
    echo 'released, still locked: ', var_export($mutex->isLocked(), true), "\n";

    echo 'tryLock once free: ', var_export($mutex->tryLock(), true), "\n";
    $mutex->unlock();
});

$scheduler->loop();
?>
--EXPECT--
all coroutines are asleep - deadlock!
lock on Mutex #1, which this coroutine already holds
tryLock while holding it: false
released, still locked: false
tryLock once free: true
