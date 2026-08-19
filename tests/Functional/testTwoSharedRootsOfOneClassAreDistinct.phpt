--TEST--
Two shared roots of one class are two graphs, each mutated on its own identity
--INI--
ffi.enable=1
opcache.jit=off
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

// The same class-keying that limited tasks limited roots: declaring a second root of one class
// used to upsert the first root's registry entry. Roots are now filed under the NAME they were
// declared with, so one class serves as many roots as the application wants.
//
// The two mutating tasks are also two unpublished instances of ONE task class, spawned through
// the persist route — the second half of what issue #15 unlocked.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

$runtime->declareShared('left', SharedCounter::class);
$runtime->declareShared('right', SharedCounter::class);

$runtime->run(static function (TaskRuntime $self): void {
    Timer::after(30.0, static function (): void {
        throw new RuntimeException('deadline: the root mutations never came back');
    });

    $self->spawnParallel(new MutateSharedTask('left', 41, 'sinister'))->await();
    $self->spawnParallel(new MutateSharedTask('right', 42, 'dexter'))->await();

    $left  = $self->shared('left');
    $right = $self->shared('right');

    echo 'the roots are distinct objects: ', $left !== $right ? 'yes' : 'no', PHP_EOL;
    echo 'left:  ', $left->value, ' ', $left->label, PHP_EOL;
    echo 'right: ', $right->value, ' ', $right->label, PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the roots are distinct objects: yes
left:  41 sinister
right: 42 dexter
children left: none
