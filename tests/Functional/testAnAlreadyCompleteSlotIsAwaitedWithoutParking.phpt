--TEST--
Awaiting a slot that is already settled returns without parking the coroutine
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\EchoTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

$task = new EchoTask(1234);
$runtime->publishTask($task);

$runtime->run(static function (TaskRuntime $self) use ($task): void {
    Timer::after(15.0, static function (): void {
        throw new RuntimeException('deadline: the first await never returned');
    });

    $handle = $self->spawnParallel($task);

    echo 'complete before the first await: ', $handle->isComplete() ? 'yes' : 'no', PHP_EOL;
    echo 'first await: ', $handle->await(), PHP_EOL;
    echo 'complete after it: ', $handle->isComplete() ? 'yes' : 'no', PHP_EOL;

    // The state is in shared memory and it is settled, so a second await reads it and returns in
    // the same tick. Parking here would wait for a wakeup that has already been and gone — which is
    // exactly the hang this ordering exists to prevent, so it is asserted rather than assumed.
    $wakeupsBefore = $self->arena()?->wakeups();
    $again         = $handle->await();
    $wakeupsAfter  = $self->arena()?->wakeups();

    echo 'second await: ', $again, PHP_EOL;
    echo 'it needed no wakeup: ', $wakeupsBefore === $wakeupsAfter ? 'yes' : 'no', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
complete before the first await: no
first await: 1234
complete after it: yes
second await: 1234
it needed no wakeup: yes
children left: none
