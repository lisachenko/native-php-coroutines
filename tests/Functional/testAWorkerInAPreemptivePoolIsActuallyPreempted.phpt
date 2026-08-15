--TEST--
A call-free loop inside a task does not starve another coroutine in that same worker
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\Tests\Support\PreemptionProbeTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The measurement is taken **inside the worker**, and it is the one that cannot be faked: how many
// times a ticker coroutine ran while a call-free loop in the same process was still going. Only a
// preemption can produce a non-zero answer, because nothing in that loop hands control back.
//
// Asserting that the child's interval timer is armed would not do. It is armed under the broken
// wiring too — a child that re-arms the *inherited parent* preemptor has a live timer and a stale
// scheduler binding, so shouldPreempt() consults a scheduler that never runs anything and answers
// false forever. Under that wiring this test reports 0 and fails, which is the point of it.
$runtime = new Runtime(workers: 1, preemptive: true, arenaSize: 32 << 20);

$probe = new PreemptionProbeTask();
$runtime->publishTask($probe);

$runtime->run(static function (RuntimeInterface $self) use ($probe): void {
    Timer::after(45.0, static function (): void {
        throw new RuntimeException('deadline: the worker never answered the preemption probe');
    });

    $ticks = $self->spawnParallel($probe)->await();

    echo 'the ticker ran while the loop was still running: ',
        is_int($ticks) && $ticks >= 1 ? 'yes' : 'no (' . get_debug_type($ticks) . ')',
        PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the ticker ran while the loop was still running: yes
children left: none
