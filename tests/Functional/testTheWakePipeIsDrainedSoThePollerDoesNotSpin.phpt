--TEST--
A bounded number of cross-process sends costs a bounded number of poller wakeups
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedSendTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// The wake socket is level-triggered: a poke stays readable until somebody reads it. A poller that
// re-checks without draining therefore returns immediately, forever — a spin that looks like a busy
// runtime rather than like a bug, and one that no test of "did the value arrive?" would notice.
//
// So the assertion is on the *number of wakeups*: 16 sends must cost a small, bounded number of
// them. An undrained pipe turns this into thousands within the deadline.
const SENDS = 16;
const BOUND = 64;

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('stream', SharedChannel::class, 4);

$producer = new SharedSendTask('stream', SENDS, 'x');
$runtime->publishTask($producer);

$runtime->run(static function (RuntimeInterface $self) use ($producer): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the sends never arrived');
    });

    $channel = $self->shared('stream');
    $handle  = $self->spawnParallel($producer);

    $received = 0;

    // The capacity is 4 and there are 16 values, so the producer genuinely has to park on a full
    // ring and be woken again — the wake path is exercised in both directions.
    while ($received < SENDS) {
        [$value, $ok] = $channel->recvOk();

        if (!$ok) {
            break;
        }

        ++$received;
    }

    echo 'received: ', $received, PHP_EOL;
    echo 'producer reported: ', $handle->await(), PHP_EOL;

    $wakeups = $self->arena()?->wakeups() ?? PHP_INT_MAX;

    echo 'wakeups are bounded: ', $wakeups <= BOUND ? 'yes' : 'NO (' . $wakeups . ')', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
received: 16
producer reported: 16
wakeups are bounded: yes
children left: none
