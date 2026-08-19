--TEST--
A coroutine inside a critical section keeps the CPU, and the deferred preemption lands right after it
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

$state                = new stdClass();
$state->ticks         = 0;
$state->before        = -1;
$state->inside        = -1;
$state->after         = -1;
$state->depthInside   = -1;
$state->depthOutside  = -1;

$runtime   = new Runtime(preemptive: true);
$preemptor = $runtime->preemptor();

$runtime->run(static function () use ($state, $preemptor): void {
    Coroutine::spawn(static function () use ($state, $preemptor): void {
        // Let the ticker run once first, so "nothing ran during the section" is a real
        // observation rather than the trivial truth that nothing had run yet at all.
        Coroutine::yield();

        $state->before = $state->ticks;

        $preemptor->enterCriticalSection();

        $sum = 0;

        for ($index = 0; $index < ITERATIONS; $index++) {
            $sum += $index % 7;
        }

        $state->depthInside = $preemptor->criticalSectionDepth();
        $state->inside      = $state->ticks;

        $preemptor->leaveCriticalSection();

        $state->depthOutside = $preemptor->criticalSectionDepth();

        for ($index = 0; $index < ITERATIONS; $index++) {
            $sum += $index % 7;
        }

        $state->after = $state->ticks;
    });

    Coroutine::spawn(static function () use ($state): void {
        for ($round = 0; $round < 10_000; $round++) {
            $state->ticks++;
            Coroutine::yield();
        }
    });

    for ($round = 0; $round < 6; $round++) {
        Coroutine::yield();
    }
});

echo 'the ticker had already run before the section: ', $state->before >= 1 ? 'yes' : 'no', PHP_EOL;
echo 'critical section depth inside: ', $state->depthInside, PHP_EOL;
echo 'critical section depth after leaving: ', $state->depthOutside, PHP_EOL;
echo 'the ticker ran during the section: ', $state->inside > $state->before ? 'yes' : 'no', PHP_EOL;
echo 'the ticker ran once the section was left: ', $state->after > $state->inside ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
the ticker had already run before the section: yes
critical section depth inside: 1
critical section depth after leaving: 0
the ticker ran during the section: no
the ticker ran once the section was left: yes
