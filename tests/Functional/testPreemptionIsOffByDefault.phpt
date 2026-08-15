--TEST--
A default runtime is fully cooperative: no preemptor, no timer, and a call-free loop starves everybody
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

const ITERATIONS = 4_000_000;

$state                     = new stdClass();
$state->ticks              = 0;
$state->ticksSeenByTheLoop = -1;

$runtime = new Runtime();

echo 'the default runtime is preemptive: ', $runtime->isPreemptive() ? 'yes' : 'no', PHP_EOL;
echo 'it composed a preemptor: ', $runtime->preemptor() !== null ? 'yes' : 'no', PHP_EOL;

$runtime->run(static function () use ($state): void {
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

// Exactly the same program as the preemptive test, and here the loop runs to its last iteration
// before anybody else gets the CPU. That is the cooperative contract, not a defect.
echo 'the ticker ran while the loop was still running: ',
    $state->ticksSeenByTheLoop >= 1 ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
the default runtime is preemptive: no
it composed a preemptor: no
the ticker ran while the loop was still running: no
