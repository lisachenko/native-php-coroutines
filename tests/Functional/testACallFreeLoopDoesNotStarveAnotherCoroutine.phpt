--TEST--
A call-free arithmetic loop does not starve another coroutine when the runtime is preemptive
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

// About 100 ms of work on a current machine: some ten 10 ms slices, so the ticker gets its turn
// many times over even on a loaded box. The bound is on iterations, not on the clock.
const ITERATIONS = 4_000_000;

$state           = new stdClass();
$state->ticks    = 0;
$state->ticksSeenByTheLoop = -1;

$runtime = new Runtime(preemptive: true);

$runtime->run(static function () use ($state): void {
    // Nothing in this body hands control back: no yield, no sleep, not even a function call.
    // Cooperatively, it owns the CPU until the last iteration.
    Coroutine::spawn(static function () use ($state): void {
        $sum = 0;

        for ($index = 0; $index < ITERATIONS; $index++) {
            $sum += $index % 7;
        }

        $state->ticksSeenByTheLoop = $state->ticks;
    });

    Coroutine::spawn(static function () use ($state): void {
        for ($round = 0; $round < 10_000; $round++) {
            $state->ticks++;
            Coroutine::yield();
        }
    });

    for ($round = 0; $round < 3; $round++) {
        Coroutine::yield();
    }
});

echo 'the ticker ran while the loop was still running: ',
    $state->ticksSeenByTheLoop >= 1 ? 'yes' : 'no', PHP_EOL;
echo 'the loop was preempted at least once: ',
    ($runtime->preemptor()?->preemptions() ?? 0) >= 1 ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
the ticker ran while the loop was still running: yes
the loop was preempted at least once: yes
