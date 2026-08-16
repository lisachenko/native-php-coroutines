--TEST--
A worker mutates a shared object and the parent observes it on the very same instance
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\MutateSharedTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedCounter;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Declared before run() forks: a root is inherited by address, and one created afterwards would
// exist only in the process that created it.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('counter', SharedCounter::class);

$mine = $runtime->shared('counter');
$task = new MutateSharedTask('counter', 4242, 'written in a worker');
$runtime->publishTask($task);

$runtime->run(static function (TaskRuntime $self) use ($task, $mine): void {
    Timer::after(15.0, static function (): void {
        throw new RuntimeException('deadline: the worker never answered');
    });

    $returned = $self->spawnParallel($task)->await();

    // Not "equal to": the *same* instance. The address is the value, so what came back through the
    // result slot is the object this process already held, not a copy rebuilt from an encoding.
    echo 'the same instance came back: ', $returned === $mine ? 'yes' : 'no', PHP_EOL;
    echo 'value: ', $mine->value, PHP_EOL;
    echo 'label: ', $mine->label, PHP_EOL;
    echo 'written by another process: ', $mine->touchedBy !== posix_getpid() ? 'yes' : 'no', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the same instance came back: yes
value: 4242
label: written in a worker
written by another process: yes
children left: none
