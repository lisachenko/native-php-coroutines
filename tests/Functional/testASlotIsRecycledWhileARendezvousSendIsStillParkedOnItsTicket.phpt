--TEST--
A result slot is claimed and given back while a rendezvous send waits for its value to be taken
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SleepingTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SlowTakeRendezvousTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Two waits that have nothing to do with each other, deliberately overlapped. A rendezvous sender
// parks a second time on its TICKET — a position in one channel's ring, settled by that channel's
// head passing it — while a join handle takes a result slot's answer and gives the slot back to the
// free list, generation bumped and waiter words wiped.
//
// The structures are disjoint (separate allocations, separate mutexes, bump allocation never
// reuses an address), so the only thing they share is the process's one wake socket. Both wakeups
// therefore land as the same content-free poke, and each waiter re-reads its own predicate: a slot
// event may wake the sender spuriously, which costs one re-check of `head > ticket` and nothing
// else. This test is that argument made observable — if a recycled slot ever disturbed a ticket
// wait, the send would either return early or never return at all.
$runtime = new Runtime(workers: 2, arenaSize: 32 << 20, slots: 8);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$receiver = new SlowTakeRendezvousTask('handoff', 0.4);
$sleeper  = new SleepingTask(0.15, 7);
$runtime->publishTask($receiver);
$runtime->publishTask($sleeper);

$runtime->run(static function (TaskRuntime $self) use ($receiver, $sleeper): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the overlapped waits never both completed');
    });

    $channel = $self->shared('handoff');
    $group   = new WaitGroup($self->scheduler());
    $order   = [];

    $taker = $self->spawnParallel($receiver, 0);

    $group->add(2);

    Coroutine::spawn(static function () use ($channel, $group, &$order): void {
        // Parks first for a partner, then a second time on the ticket, and the slot below is
        // settled and released squarely inside that second park.
        $channel->send('handed over');
        $order[] = 'the rendezvous completed';
        $group->done();
    });

    Coroutine::spawn(static function () use ($self, $sleeper, $group, &$order): void {
        $order[] = 'the join handle answered: ' . $self->spawnParallel($sleeper, 1)->await();
        $group->done();
    });

    $group->wait();

    $order[] = 'the receiver took: ' . $taker->await();

    // Every slot settled and came back; the sleeper's did so while the ticket wait was still
    // outstanding, which is the interleaving under test.
    $table = $self->arena()?->slotTable();

    echo implode(PHP_EOL, $order), PHP_EOL;
    echo 'slots still out: ', $table?->outstanding() ?? -1, PHP_EOL;
    echo 'the handoff slot is empty: ', $channel->count() === 0 ? 'yes' : 'no', PHP_EOL;
    echo 'no registration is left behind: ', $channel->hasWaitingReceiver() ? 'NO' : 'yes', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the join handle answered: 7
the rendezvous completed
the receiver took: handed over
slots still out: 0
the handoff slot is empty: yes
no registration is left behind: yes
children left: none
