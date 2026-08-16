--TEST--
A capacity-0 shared channel hands values between two coroutines of the same process
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The case with no wake event in its path at all. Cross-process, every step of a handoff is
// announced on a socket that stays readable until it is drained, so a missing local re-check is
// covered up by the poller coming round again. Between two coroutines of one process there is no
// socket in the path: the sender has to be woken because the receiver *registered*, and the
// receiver because the sender *deposited*, both synchronously. A missing edge here is a hang, and
// the deadline below is what reports it. (The pool exists only because a shared arena needs one;
// the worker never touches this channel.)
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$runtime->run(static function (TaskRuntime $self): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the same-process rendezvous never completed');
    });

    $channel = $self->shared('handoff');
    $group   = new WaitGroup($self->scheduler());
    $taken   = [];

    $group->add(2);

    // The receiver goes first and parks, so the sender finds a partner already registered.
    Coroutine::spawn(static function () use ($channel, $group, &$taken): void {
        for ($round = 0; $round < 3; ++$round) {
            $taken[] = $channel->recv();
        }

        $group->done();
    });

    // The sender goes second and has to park on the handoff of each value being taken.
    Coroutine::spawn(static function () use ($channel, $group): void {
        for ($round = 0; $round < 3; ++$round) {
            $channel->send('v' . $round);
        }

        $group->done();
    });

    $group->wait();

    echo 'taken: ', implode(' ', $taken), PHP_EOL;
    echo 'the handoff slot is empty: ', $channel->count() === 0 ? 'yes' : 'no', PHP_EOL;
    echo 'no registration is left behind: ', $channel->hasWaitingReceiver() ? 'NO' : 'yes', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
taken: v0 v1 v2
the handoff slot is empty: yes
no registration is left behind: yes
children left: none
