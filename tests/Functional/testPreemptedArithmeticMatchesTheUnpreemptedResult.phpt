--TEST--
A computation cut into slices by preemption produces exactly the result it produces uninterrupted
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

// The reference: the same arithmetic, in ordinary straight-line code that nothing interrupts.
$reference = 0;
$rolling   = 1;

for ($index = 0; $index < ITERATIONS; $index++) {
    $reference += $index % 7;
    $rolling    = ($rolling * 31 + $index) % 1_000_003;
}

$state           = new stdClass();
$state->sum      = -1;
$state->rolling  = -1;

$runtime = new Runtime(preemptive: true);

$runtime->run(static function () use ($state): void {
    Coroutine::spawn(static function () use ($state): void {
        $sum     = 0;
        $rolling = 1;

        // Two interdependent accumulators updated per iteration: a preemption that landed
        // between them, or that lost a partial result, would show up as a mismatch.
        for ($index = 0; $index < ITERATIONS; $index++) {
            $sum     += $index % 7;
            $rolling  = ($rolling * 31 + $index) % 1_000_003;
        }

        $state->sum     = $sum;
        $state->rolling = $rolling;
    });

    for ($round = 0; $round < 3; $round++) {
        Coroutine::yield();
    }
});

echo 'the computation was preempted: ',
    ($runtime->preemptor()?->preemptions() ?? 0) >= 1 ? 'yes' : 'no', PHP_EOL;
echo 'the sum matches the uninterrupted reference: ', $state->sum === $reference ? 'yes' : 'no', PHP_EOL;
echo 'the rolling hash matches the uninterrupted reference: ',
    $state->rolling === $rolling ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
the computation was preempted: yes
the sum matches the uninterrupted reference: yes
the rolling hash matches the uninterrupted reference: yes
