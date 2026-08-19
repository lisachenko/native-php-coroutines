--TEST--
A coroutine still parked in the preemption callback is drained out of it, never dropped
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

const ITERATIONS = 4_000_000;

$state             = new stdClass();
$state->finished   = false;
$state->finallyRan = false;

// This line proves the process reached the end of request shutdown. A preempt-suspended fiber left
// for the engine to destroy is a fatal error inside an FFI callback, which no catch clause sees and
// which would kill the process before this ran.
register_shutdown_function(static function () use ($state): void {
    echo 'the process reached shutdown: yes', PHP_EOL;
    echo 'the discarded coroutine had been drained to completion: ',
        $state->finished ? 'yes' : 'no', PHP_EOL;
    echo 'its finally ran: ', $state->finallyRan ? 'yes' : 'no', PHP_EOL;
});

$runtime = new Runtime(preemptive: true);

$runtime->run(static function () use ($state): void {
    Coroutine::spawn(static function () use ($state): void {
        try {
            $sum = 0;

            for ($index = 0; $index < ITERATIONS; $index++) {
                $sum += $index % 7;
            }

            $state->finished = true;
        } finally {
            $state->finallyRan = true;
        }
    });

    // Main returns while the looper is still mid-loop, which in Go semantics discards it. A
    // cooperatively parked coroutine is simply dropped here; one parked inside the preemption
    // callback cannot be, so the scheduler drains it first.
    Coroutine::yield();
});

echo 'the run returned: yes', PHP_EOL;
echo 'the looper was preempted: ',
    ($runtime->preemptor()?->preemptions() ?? 0) >= 1 ? 'yes' : 'no', PHP_EOL;
echo 'the slice timer is disarmed: ',
    $runtime->preemptor()?->isArmed() === false ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
the run returned: yes
the looper was preempted: yes
the slice timer is disarmed: yes
the process reached shutdown: yes
the discarded coroutine had been drained to completion: yes
its finally ran: yes
