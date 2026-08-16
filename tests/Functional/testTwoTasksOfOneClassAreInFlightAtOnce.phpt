--TEST--
Two tasks of one class are in flight at once, and each graph survives the other
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\NapThenEchoTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Issue #15: this exact shape used to be refused, because the substrate's registry keyed graphs
// by class name and a second persist of one class was an upsert — superseding the graph the first
// worker was still reading. Per-instance keying makes a second instance a second entry, so the
// most natural thing a user can try simply works.
$runtime = new Runtime(workers: 2, arenaSize: 32 << 20);

// Persisting before run() also loads the class pre-fork, and makes both graphs' identities
// observable from outside: two live instances of ONE class, at two distinct arena addresses.
$first  = $runtime->persist(new NapThenEchoTask(0.25, 'graph-one'));
$second = $runtime->persist(new NapThenEchoTask(0.25, 'graph-two'));

$arena = $runtime->arena();

echo 'both instances are shared at distinct addresses: ',
    $arena !== null && $arena->addressOf($first) !== $arena->addressOf($second) ? 'yes' : 'no', PHP_EOL;

$runtime->run(static function (TaskRuntime $self) use ($first, $second): void {
    Timer::after(30.0, static function (): void {
        throw new RuntimeException('deadline: the concurrent same-class tasks never finished');
    });

    // Pinned to different workers and both napping, so their lifetimes genuinely overlap.
    $one = $self->spawnParallel($first, 0);
    $two = $self->spawnParallel($second, 1);

    // Asserted, not assumed: while both are running, each graph is read back through shared
    // memory and still carries its own payload — nothing was mutated or freed by the sibling.
    echo 'graph one intact while both run: ', $first->payload === 'graph-one' ? 'yes' : 'no', PHP_EOL;
    echo 'graph two intact while both run: ', $second->payload === 'graph-two' ? 'yes' : 'no', PHP_EOL;

    echo 'first awaits its own result: ', $one->await() === 'graph-one' ? 'yes' : 'no', PHP_EOL;
    echo 'second awaits its own result: ', $two->await() === 'graph-two' ? 'yes' : 'no', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
both instances are shared at distinct addresses: yes
graph one intact while both run: yes
graph two intact while both run: yes
first awaits its own result: yes
second awaits its own result: yes
children left: none
