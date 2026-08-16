--TEST--
A task panic reaches the waiter as a ParallelTaskException carrying class, message and trace
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedPanicTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The Throwable itself never crosses: it belongs to a process that may already be gone, and
// rebuilding it would mean encoding an object graph. The worker moves its class, message and trace
// into the arena as three arena strings on one shared object, and the waiter reads those fields
// straight out of shared memory — by name, never by dumping the object.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

$task = new SharedPanicTask();
$runtime->publishTask($task);

$runtime->run(static function (TaskRuntime $self) use ($task): void {
    Timer::after(15.0, static function (): void {
        throw new RuntimeException('deadline: the panic never reached the waiter');
    });

    try {
        $self->spawnParallel($task)->await();

        echo 'the await returned, which it must not', PHP_EOL;
    } catch (ParallelTaskException $panic) {
        echo 'class: ', $panic->originalClass(), PHP_EOL;
        echo 'message: ', $panic->getMessage(), PHP_EOL;
        echo 'trace survived: ', $panic->originalTrace() !== '' ? 'yes' : 'no', PHP_EOL;
        echo 'worker: ', $panic->workerId(), PHP_EOL;
    }

    // The pool is unharmed: a panicking task is an ordinary outcome, not a lost worker.
    echo 'the worker is still alive: ',
        $self->supervisor()?->worker(0)->isAlive() === true ? 'yes' : 'no',
        PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
class: DomainException
message: task panicked in worker #0: DomainException: the parallel task exploded
trace survived: yes
worker: 0
the worker is still alive: yes
children left: none
