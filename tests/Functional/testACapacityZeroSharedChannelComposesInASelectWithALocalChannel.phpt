--TEST--
One select resolves a capacity-0 shared channel and a local channel, and unlinks the losers
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\RendezvousSendTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// A rendezvous case is the one that used not to exist here at all. Registering a receiver on behalf
// of a select case is what makes the sibling's handoff possible, and unlinking it when another case
// wins is what keeps the next handoff from being deposited for a coroutine that has moved on — so
// the loop below runs the select more times than there are shared values, and the last round is won
// by the local channel with the shared registration withdrawn behind it.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$sender = new RendezvousSendTask('handoff', 3, 'shared-');
$runtime->publishTask($sender);

$runtime->run(static function (TaskRuntime $self) use ($sender): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the select never resolved four times');
    });

    $shared = $self->shared('handoff');
    $local  = new Channel($self->scheduler(), 1);

    $self->spawnParallel($sender);

    Coroutine::spawn(static function () use ($local): void {
        Coroutine::sleep(0.3);
        $local->send('local');
    });

    $seen = [];

    for ($round = 0; $round < 4; ++$round) {
        Select::on($self->scheduler())
            ->recv($shared, static function (mixed $value) use (&$seen): void {
                $seen[] = 'shared:' . $value;
            })
            ->recv($local, static function (mixed $value) use (&$seen): void {
                $seen[] = 'local:' . $value;
            })
            ->run();
    }

    sort($seen);

    echo implode(PHP_EOL, $seen), PHP_EOL;
    echo 'the losing case left no registration: ', $shared->hasWaitingReceiver() ? 'NO' : 'yes', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
local:local
shared:shared-0
shared:shared-1
shared:shared-2
the losing case left no registration: yes
children left: none
