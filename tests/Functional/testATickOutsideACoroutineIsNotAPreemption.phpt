--TEST--
Slice ticks that land outside any coroutine are harmless and preempt nothing
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Preemption\Preemptor;
use Lisachenko\NativePhpCoroutines\Scheduler;

include __DIR__ . '/../../vendor/autoload.php';

const ITERATIONS = 4_000_000;

$reference = 0;

for ($index = 0; $index < ITERATIONS; $index++) {
    $reference += $index % 7;
}

$scheduler = new Scheduler();
$preemptor = new Preemptor($scheduler);

$scheduler->attachPreemptor($preemptor);
$preemptor->arm();

// The clock is running and the interrupt callback is installed, but this loop is plain top-level
// code: no fiber, no coroutine, nothing the scheduler owns. Every tick that arrives here has
// nothing to suspend, and suspending anyway would tear the process's own stack apart.
$sum = 0;

for ($index = 0; $index < ITERATIONS; $index++) {
    $sum += $index % 7;
}

$preemptor->disarm();

echo 'the slice timer was armed over the loop: yes', PHP_EOL;
echo 'preemptions taken outside a coroutine: ', $preemptor->preemptions(), PHP_EOL;
echo 'the loop result is intact: ', $sum === $reference ? 'yes' : 'no', PHP_EOL;
echo 'the timer is disarmed again: ', $preemptor->isArmed() ? 'no' : 'yes', PHP_EOL;
?>
--EXPECT--
the slice timer was armed over the loop: yes
preemptions taken outside a coroutine: 0
the loop result is intact: yes
the timer is disarmed again: yes
