--TEST--
A receive on a capacity-0 shared channel parks on the poller until another process sends
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
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

// The other direction of the handshake: this process asks for a value nobody has offered yet, so it
// registers itself as the partner and parks in the one stream_select() of the process. The worker
// waits 400 ms before it even tries to send, and the value still arrives — through the wake socket,
// not through anything this scheduler could have produced on its own.
const DELAY = 0.4;
const FLOOR = 0.25;

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$sender = new RendezvousSendTask('handoff', 1, 'late-', 0.0, 64, DELAY);
$runtime->publishTask($sender);

$runtime->run(static function (TaskRuntime $self) use ($sender): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the receive never woke');
    });

    $channel = $self->shared('handoff');
    $handle  = $self->spawnParallel($sender);

    $started = microtime(true);
    $value   = $channel->recv();
    $elapsed = microtime(true) - $started;

    echo 'received: ', $value, PHP_EOL;
    echo 'the receive parked until the sender arrived: ', $elapsed >= FLOOR ? 'yes' : 'NO (' . round($elapsed, 3) . 's)', PHP_EOL;
    echo 'the worker reported: ', $handle->await(), PHP_EOL;
    echo 'no registration is left behind: ', $channel->hasWaitingReceiver() ? 'NO' : 'yes', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
received: late-0
the receive parked until the sender arrived: yes
the worker reported: sent 1, handoff waited for a receiver, wakeups bounded
no registration is left behind: yes
children left: none
