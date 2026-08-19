--TEST--
A capacity-0 shared channel that lost a select is no longer a rendezvous partner, seen from a worker
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\ProbeRendezvousPartnerTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// A select case on a rendezvous channel registers this process as a partner, and the registration
// lives in the arena where every sibling reads it. Left behind by a losing case it would be worse
// than a leak: the next send in another process would deposit a value for a coroutine that resolved
// its select long ago, and then wait for a take that nobody is coming to perform.
//
// So the check is deliberately made from the *other* process, against the shared count rather than
// against this process's own waiter list.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$probe = new ProbeRendezvousPartnerTask('handoff');
$runtime->publishTask($probe);

$runtime->run(static function (TaskRuntime $self) use ($probe): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the select never resolved');
    });

    $shared = $self->shared('handoff');
    $local  = new Channel($self->scheduler(), 1);

    Coroutine::spawn(static function () use ($local): void {
        Coroutine::sleep(0.05);
        $local->send('local wins');
    });

    // Nothing is ready when this runs, so the select genuinely parks — and parking is what puts a
    // registration into the arena on behalf of the shared case.
    $outcome = Select::on($self->scheduler())
        ->recv($shared, static fn(mixed $value): string => 'shared: ' . (string) $value)
        ->recv($local, static fn(mixed $value): string => 'local: ' . (string) $value)
        ->run();

    echo $outcome, PHP_EOL;
    echo 'this process still lists a waiter: ', $shared->hasWaitingReceiver() ? 'yes' : 'no', PHP_EOL;
    echo 'a worker sees: ', $self->spawnParallel($probe)->await(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
local: local wins
this process still lists a waiter: no
a worker sees: nobody is waiting
children left: none
