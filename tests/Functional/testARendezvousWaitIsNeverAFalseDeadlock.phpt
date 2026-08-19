--TEST--
A coroutine parked on a capacity-0 shared channel is not reported as a deadlock
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
use Lisachenko\NativePhpCoroutines\Tests\Support\RendezvousSendTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// Deadlock detection fires when nothing runnable is left and nothing *local* could ever make one
// runnable again. Both halves of a rendezvous are exactly that case — a receiver waiting for a
// partner who is in another process, and a sender waiting for its own record to be taken there —
// so both parks are marked externally wakeable, and there is deliberately no timer here keeping the
// scheduler busy on their behalf. A missing exclusion ends this run with a DeadlockException
// instead of the lines below.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$sender = new RendezvousSendTask('handoff', 2, 'v', 0.0, 64, 0.3);
$runtime->publishTask($sender);

$runtime->run(static function (TaskRuntime $self) use ($sender): void {
    $shared = $self->shared('handoff');
    $group  = new WaitGroup($self->scheduler());

    $self->spawnParallel($sender);

    $group->add(1);

    Coroutine::spawn(static function () use ($shared, $group): void {
        // Parked here with an empty run queue, an empty timer heap and nothing local that could
        // ever produce this value: the definition of the state the detector reports on.
        echo 'the rendezvous answered: ', $shared->recv(), PHP_EOL;
        echo 'and again: ', $shared->recv(), PHP_EOL;
        $group->done();
    });

    $group->wait();

    echo 'no deadlock was reported: yes', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
the rendezvous answered: v0
and again: v1
no deadlock was reported: yes
children left: none
