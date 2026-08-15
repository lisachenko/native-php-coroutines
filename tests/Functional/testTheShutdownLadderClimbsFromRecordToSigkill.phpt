--TEST--
Shutdown asks first, escalates to SIGTERM, and reaches SIGKILL for a worker that ignores both
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\PreforkTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\Tests\Support\SumTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

$scheduler = new Scheduler();

$tasks = new PreforkTaskDirectory();
$tasks->register(new SumTask(1, 1));

$supervisor = new WorkerSupervisor($scheduler, $tasks);

// The after-fork seam decides how obedient each child is. Worker #0 goes on to serve its inbox
// normally; #1 never gets there, so it never sees the SHUTDOWN record; #2 additionally refuses
// SIGTERM, which leaves exactly one rung that can still end it.
$supervisor->start(3, static function (int $id): void {
    if ($id === 0) {
        return;
    }

    if ($id === 2) {
        pcntl_signal(SIGTERM, SIG_IGN);
    }

    // Bounded, so a failure of the ladder cannot leave this process running for the rest of the
    // suite; the supervisor's own safety net would reach it long before this expires anyway.
    $stopAt = time() + 30;

    while (time() < $stopAt) {
        sleep(1);
    }

    exit(0);
});

echo 'workers forked: ', count($supervisor->workers()), "\n";

$rungs = $supervisor->shutdown(graceSeconds: 0.4, termSeconds: 0.4, killSeconds: 1.0);

foreach ($rungs as $id => $rung) {
    echo 'worker #', $id, ' ended by: ', $rung->value, "\n";
}

echo 'alive afterwards: ', count(array_filter($supervisor->workers(), fn ($w): bool => $w->isAlive())), "\n";

// Every rung is an orderly end of a worker that owed nothing, so none of them is a crash.
echo 'crashes recorded: ', count($supervisor->crashes()), "\n";
echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
workers forked: 3
worker #0 ended by: shutdown
worker #1 ended by: sigterm
worker #2 ended by: sigkill
alive afterwards: 0
crashes recorded: 0
children left: none
