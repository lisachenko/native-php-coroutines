--TEST--
One select over a shared channel and a local channel resolves both, the shared one through its FD
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
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedSendTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('jobs', SharedChannel::class, 8);

$producer = new SharedSendTask('jobs', 3, 'job-');
$runtime->publishTask($producer);

$runtime->run(static function (TaskRuntime $self) use ($producer): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the select never resolved four times');
    });

    $shared = $self->shared('jobs');
    $local  = new Channel($self->scheduler(), 1);

    // A local channel knows its own readiness; a shared one is changed by another process, so the
    // poller has to learn about it from the kernel. That difference is the whole reason
    // readinessFd() exists on the interface.
    echo 'local readiness fd: ', $local->readinessFd() === null ? 'none' : 'a descriptor', PHP_EOL;
    echo 'shared readiness fd: ', is_resource($shared->readinessFd()) ? 'a descriptor' : 'none', PHP_EOL;

    $self->spawnParallel($producer);

    Coroutine::spawn(static function () use ($local): void {
        Coroutine::sleep(0.05);
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
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
local readiness fd: none
shared readiness fd: a descriptor
local:local
shared:job-0
shared:job-1
shared:job-2
children left: none
