--TEST--
A send on a capacity-0 shared channel returns only once another process has taken the value
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SlowTakeRendezvousTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The deposit and the take are two events, and only the second one ends a rendezvous. The worker
// registers a receiver immediately and then holds its own scheduler in a call-free loop for 400 ms,
// so the value can be handed *in* at once but cannot be handed *out* until the loop ends. A send
// that returned at the deposit — which is the earliest point it could — would come back in
// milliseconds; one that waits for the take cannot come back before the loop does.
const BUSY  = 0.4;
const FLOOR = 0.25;

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$receiver = new SlowTakeRendezvousTask('handoff', BUSY);
$runtime->publishTask($receiver);

$runtime->run(static function (TaskRuntime $self) use ($receiver): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the rendezvous never completed');
    });

    $channel = $self->shared('handoff');
    $handle  = $self->spawnParallel($receiver);

    // Test scaffolding, not runtime behaviour: wait until the partner exists so the measurement
    // below covers the take alone and not the wait for somebody to hand the value to.
    for ($round = 0; $round < 2_000 && !$channel->hasWaitingReceiver(); ++$round) {
        Coroutine::sleep(0.005);
    }

    echo 'a partner is registered before the send: ', $channel->hasWaitingReceiver() ? 'yes' : 'no', PHP_EOL;

    $started = microtime(true);
    $channel->send('one handoff');
    $elapsed = microtime(true) - $started;

    echo 'the send waited for the take: ', $elapsed >= FLOOR ? 'yes' : 'NO (' . round($elapsed, 3) . 's)', PHP_EOL;
    echo 'the worker took: ', $handle->await(), PHP_EOL;
    echo 'nothing is left in the handoff slot: ', $channel->count() === 0 ? 'yes' : 'no', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
a partner is registered before the send: yes
the send waited for the take: yes
the worker took: one handoff
nothing is left in the handoff slot: yes
children left: none
