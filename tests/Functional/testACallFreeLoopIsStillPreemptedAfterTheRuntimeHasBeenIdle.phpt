--TEST--
A call-free loop that starts after an idle stretch is preempted exactly as one that never idled
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

// The clock is stopped while the process blocks in the poller, so every idle stretch is a chance to
// come back cooperative by accident. This is the ordinary way out of that poll — no signal, no
// error, just a timer deadline arriving — and it has to end with slicing running again, because
// the coroutines that were waiting on that deadline are the ones about to get the CPU.
const IDLE_SECONDS = 0.1;
const ITERATIONS   = 4_000_000;

$state                     = new stdClass();
$state->ticks              = 0;
$state->ticksSeenByTheLoop = -1;

$runtime = new Runtime(preemptive: true);

$runtime->run(static function () use ($state): void {
    // Nothing else is runnable and nothing is registered with the poller, so the scheduler really
    // does go idle here rather than taking a turn round the run queue.
    Coroutine::sleep(IDLE_SECONDS);

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
