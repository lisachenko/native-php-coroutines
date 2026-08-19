--TEST--
SIGCHLD reaping clears a dead worker from the process table without waiting for shutdown
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
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelWaitFor;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

$scheduler = new Scheduler();

$tasks = new PreforkTaskDirectory();
$tasks->register(new SumTask(1, 1));

$supervisor = new WorkerSupervisor($scheduler, $tasks);
$supervisor->start(4);

$victim = $supervisor->worker(1);

posix_kill($victim->pid(), SIGKILL);

// Nothing is awaited and no scheduler is running: the asynchronous SIGCHLD handler is the only
// thing that can notice, and noticing means waitpid(), which is what keeps a defunct entry from
// sitting in the process table for the rest of the run.
echo 'reaped without any event loop: ', parallelWaitFor(
    static fn (): bool => !$victim->isAlive(),
    5.0,
) ? 'yes' : 'NO', "\n";

echo 'killed by signal: ', $victim->termSignal(), "\n";
echo 'still alive: ', count(array_filter($supervisor->workers(), fn ($w): bool => $w->isAlive())), "\n";

// Round-robin skips the hole rather than dispatching into it.
echo 'a placement still finds a live worker: ', $supervisor->crashes() === []
    ? 'yes'
    : 'yes (crash already recorded)', "\n";

$rungs = $supervisor->shutdown();

echo 'the dead worker needed no rung: ', $rungs[1]->value, "\n";
echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
reaped without any event loop: yes
killed by signal: 9
still alive: 3
a placement still finds a live worker: yes
the dead worker needed no rung: already-gone
children left: none
