--TEST--
A preempted coroutine refuses to be thrown into, while a coroutine that suspended itself accepts it
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

include __DIR__ . '/../../vendor/autoload.php';

const ITERATIONS = 4_000_000;

$runtime = new Runtime(preemptive: true);

// Coroutine #1 is the runtime's own main; the looper below is #2.
$runtime->run(static function (): void {
    $looper = Coroutine::spawn(static function (): void {
        $sum = 0;

        for ($index = 0; $index < ITERATIONS; $index++) {
            $sum += $index % 7;
        }
    });

    // Hand it the CPU until the slice timer takes it back. Bounded, not a wall-clock race.
    for ($round = 0; $round < 1_000 && !$looper->isPreemptSuspended(); $round++) {
        Coroutine::yield();
    }

    echo 'the looper is parked by a preemption: ', $looper->isPreemptSuspended() ? 'yes' : 'no', PHP_EOL;
    echo 'its last suspend command: ', $looper->lastSuspend()?->name ?? 'none', PHP_EOL;
    echo 'that command allows a throw: ', $looper->lastSuspend()?->allowsThrow() ? 'yes' : 'no', PHP_EOL;

    try {
        $looper->throwInto(new RuntimeException('cancel'));
    } catch (LogicException $refusal) {
        echo $refusal->getMessage(), PHP_EOL;
    }
});

// The refusal is about the suspension point, not about throwing: a coroutine parked where its own
// code asked to be parked takes the exception exactly there, and its catch and finally run.
$cooperative = new Coroutine(static function (): void {
    try {
        Fiber::suspend(SuspendCommand::YIELD);
    } catch (RuntimeException $cancelled) {
        echo 'the cooperative coroutine caught: ', $cancelled->getMessage(), PHP_EOL;
    } finally {
        echo 'and ran its finally', PHP_EOL;
    }
});

$cooperative->step();
$cooperative->throwInto(new RuntimeException('cancel'));
?>
--EXPECT--
the looper is parked by a preemption: yes
its last suspend command: PREEMPT
that command allows a throw: no
coroutine #2 is suspended by PREEMPT and must not be thrown into; resume it with resume(null) and let it observe a cancellation flag at a cooperative point
the cooperative coroutine caught: cancel
and ran its finally
