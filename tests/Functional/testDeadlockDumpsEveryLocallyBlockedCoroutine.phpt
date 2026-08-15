--TEST--
A deadlock names every locally blocked coroutine, its wait and its spawn site, and skips externally wakeable ones
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

include __DIR__ . '/../../vendor/autoload.php';

$runtime = new Runtime();

/**
 * Stands in for a blocking primitive: park with a description, then hand control over for good.
 * (Channels and wait groups arrive with their own ticket; the scheduler only ever sees this much.)
 */
$parkForever = static function (string $waitDescription, bool $externallyWakeable = false): void {
    $scheduler = Scheduler::active();
    $scheduler->current()?->park($waitDescription, $externallyWakeable);
    $scheduler->suspend(SuspendCommand::BLOCKED);
};

$spawnLine = 0;

try {
    $runtime->run(function () use ($parkForever, &$spawnLine): void {
        $spawnLine = __LINE__ + 1;
        Coroutine::spawn(static fn (): mixed => $parkForever('recv on channel #3'));

        // Waiting on something only another process could deliver: excluded from the dump, because
        // no amount of local scheduling could ever have woken it.
        Coroutine::spawn(static fn (): mixed => $parkForever('recv on shared channel #9', true));

        Coroutine::yield();

        $parkForever('wait on WaitGroup #1');
    });
} catch (DeadlockException $deadlock) {
    // The spawn site is an absolute path and a line number, so the headline is printed with those
    // folded away and checked exactly, once, below.
    echo preg_replace('/spawned at .+$/m', 'spawned at <origin>', $deadlock->getMessage()), PHP_EOL;
    echo '---', PHP_EOL;

    $dump = $deadlock->blockedCoroutines();

    echo 'coroutines reported: ', count($dump), PHP_EOL;
    echo 'the externally wakeable one is not among them: ',
        array_filter($dump, static fn (array $e): bool => str_contains($e['wait'], 'shared')) === [] ? 'yes' : 'no',
        PHP_EOL;
    // The file half is whatever the engine compiled — PHPUnit feeds a .phpt body through stdin,
    // so it reads "Standard input code" here rather than a path. The line is the load-bearing
    // part: it must point at the spawn, not at any frame inside the runtime.
    echo 'the origin points at the spawn line: ',
        str_ends_with($dump[1]['origin'], ':' . $spawnLine) ? 'yes' : 'no',
        PHP_EOL;
}
?>
--EXPECT--
all coroutines are asleep - deadlock!
coroutine #1 [wait on WaitGroup #1], spawned at <origin>
coroutine #2 [recv on channel #3], spawned at <origin>
---
coroutines reported: 2
the externally wakeable one is not among them: yes
the origin points at the spawn line: yes
