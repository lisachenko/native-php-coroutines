--TEST--
A rendezvous select abandoned by a cancelled context leaves no registration behind
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Context;
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

// The other way a waiting receiver goes away: the work it was doing was cancelled. Cancellation is
// a channel that closes, so it reaches the select as an ordinary winning case — and the rendezvous
// case it beat has to withdraw its arena registration exactly as any other loser does, or the whole
// family goes on believing this process is ready to take a handoff.
$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('handoff', SharedChannel::class, 0);

$probe = new ProbeRendezvousPartnerTask('handoff');
$runtime->publishTask($probe);

$runtime->run(static function (TaskRuntime $self) use ($probe): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the cancellation never resolved the select');
    });

    $scheduler = $self->scheduler();
    $shared    = $self->shared('handoff');
    $context   = Context::withCancel($scheduler);

    Coroutine::spawn(static function () use ($context): void {
        Coroutine::sleep(0.05);
        $context->cancel();
    });

    $outcome = Select::on($scheduler)
        ->recv($shared, static fn(mixed $value): string => 'handoff: ' . (string) $value)
        ->recv($context->done(), static fn(): string => 'cancelled')
        ->run();

    echo $outcome, PHP_EOL;
    echo 'a worker sees: ', $self->spawnParallel($probe)->await(), PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
cancelled
a worker sees: nobody is waiting
children left: none
