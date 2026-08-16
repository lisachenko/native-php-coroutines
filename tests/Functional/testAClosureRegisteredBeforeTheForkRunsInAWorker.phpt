--TEST--
A closure registered before the fork barrier is callable in a worker, and a later one is refused
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedClosureTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Acceptance is provenance and nothing else: this closure is compiled before the fork barrier, so
// it exists at the same address in every worker and the record in the arena proves it. A closure
// compiled afterwards cannot be told apart by inspection from a stale address holding a different,
// perfectly valid Closure — on PHP 8.5 the substrate spikes watched such an address execute the
// wrong function rather than fail — so it is refused outright.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

$runtime->registerSharedClosure('triple', static fn (int $value): int => $value * 3);

$task = new SharedClosureTask('triple', 14);
$runtime->publishTask($task);

$runtime->run(static function (TaskRuntime $self) use ($task): void {
    Timer::after(15.0, static function (): void {
        throw new RuntimeException('deadline: the worker never called the shared closure');
    });

    echo 'the worker called it: ', $self->spawnParallel($task)->await(), PHP_EOL;

    try {
        $self->registerSharedClosure('made-up-later', static fn (): int => 1);

        echo 'a post-fork closure was accepted, which it must not be', PHP_EOL;
    } catch (LogicException) {
        echo 'a post-fork closure is refused: yes', PHP_EOL;
    }
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the worker called it: 42
a post-fork closure is refused: yes
children left: none
