--TEST--
A run of cross-process rendezvous handoffs costs a bounded number of wakeups in both processes
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\RendezvousSendTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// A rendezvous is the shape most at risk of a quiet regression into polling: neither side can
// proceed until the other one moves, so "try again in a moment" always eventually works and no test
// of "did the values arrive?" would ever notice. The assertion is therefore on the *number of
// wakeups* each process needed. Every handoff is three state changes — a registration, a deposit
// and a take — so a small multiple of the handoff count is generous, while a poll loop reaches the
// bound within the first few milliseconds. Measured at the time of writing: 14 in the worker and 9
// here, against a bound of 64.
//
// Both sides are counted, because they park for different reasons: the receiver waits for a record
// and the sender waits first for a partner and then for its own record to be taken.
const HANDOFFS = 8;
const BOUND    = 64;

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$sender = new RendezvousSendTask('handoff', HANDOFFS, 'h', 0.0, BOUND);
$runtime->publishTask($sender);

$runtime->run(static function (TaskRuntime $self) use ($sender): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the handoffs never completed');
    });

    $channel = $self->shared('handoff');
    $handle  = $self->spawnParallel($sender);

    $received = [];

    while (count($received) < HANDOFFS) {
        [$value, $ok] = $channel->recvOk();

        if (!$ok) {
            break;
        }

        $received[] = $value;
    }

    echo 'received: ', implode(' ', $received), PHP_EOL;
    echo 'the worker reported: ', $handle->await(), PHP_EOL;

    $wakeups = $self->arena()?->wakeups() ?? PHP_INT_MAX;

    echo 'this process woke a bounded number of times: ',
        $wakeups <= BOUND ? 'yes' : 'NO (' . $wakeups . ')',
        PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
received: h0 h1 h2 h3 h4 h5 h6 h7
the worker reported: sent 8, handoff waited for a receiver, wakeups bounded
this process woke a bounded number of times: yes
children left: none
