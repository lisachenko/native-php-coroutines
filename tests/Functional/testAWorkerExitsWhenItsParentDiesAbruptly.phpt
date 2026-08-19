--TEST--
A worker whose parent is SIGKILLed exits on control-socket EOF instead of becoming an orphan
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

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelAwaitEof;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

// A liveness probe that outlives the process being observed. The worker inherits $probeFar and
// nothing else keeps it open, so this end reaches EOF exactly when the worker's descriptors are
// closed — which is to say when the worker is gone. Polling a pid could not tell a running process
// from a zombie; this can.
[$probeNear, $probeFar] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$middle = pcntl_fork();

if ($middle === 0) {
    // ------------------------------------------------ the process that will die without warning
    fclose($probeNear);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $scheduler = new Scheduler();
    $tasks     = new PreforkTaskDirectory();
    $tasks->register(new SumTask(1, 1));

    $supervisor = new WorkerSupervisor($scheduler, $tasks);
    $supervisor->start(1);

    // From here only the worker holds this end open.
    fclose($probeFar);

    // SIGKILL rather than exit(): no shutdown functions run, so the supervisor's own safety net is
    // skipped and the only thing left to save the worker is the EOF backstop under test.
    posix_kill(posix_getpid(), SIGKILL);
    usleep(2_000_000);

    exit(1);
}

fclose($probeFar);

$status = 0;
pcntl_waitpid($middle, $status);

echo 'the parent was killed: ', pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL ? 'yes' : 'no', "\n";
echo 'the worker exited on its own: ', parallelAwaitEof($probeNear, 10.0) ? 'yes' : 'NO', "\n";

fclose($probeNear);

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
the parent was killed: yes
the worker exited on its own: yes
children left: none
