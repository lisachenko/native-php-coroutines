--TEST--
Only the unpark that performed the transition returns true; later ones are harmless no-ops
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

include __DIR__ . '/../../vendor/autoload.php';

$runtime = new Runtime();

$runtime->run(function (): void {
    $scheduler = Scheduler::active();

    $waiter = Coroutine::spawn(static function () use ($scheduler): void {
        $scheduler->current()?->park('recv on channel #1');
        $scheduler->suspend(SuspendCommand::BLOCKED);

        echo 'the waiter resumed exactly once', PHP_EOL;
    });

    Coroutine::yield();

    echo 'parked as: ', $waiter->status()->name, ' on "', $waiter->waitDescription(), '"', PHP_EOL;

    // This is the select case that won.
    echo 'first unpark: ', $waiter->unpark() ? 'true' : 'false', PHP_EOL;

    // And these are the losing cases, still holding a wait node they have not unlinked yet. A
    // second true here would mean scheduling an already-runnable coroutine — running it twice.
    echo 'second unpark: ', $waiter->unpark() ? 'true' : 'false', PHP_EOL;
    echo 'third unpark: ', $waiter->unpark() ? 'true' : 'false', PHP_EOL;

    echo 'now: ', $waiter->status()->name, ', waiting on ', var_export($waiter->waitDescription(), true), PHP_EOL;

    $scheduler->schedule($waiter);
    Coroutine::yield();

    // Unparking something that was never parked, or has already finished, is safe and false.
    echo 'unpark after it finished: ', $waiter->unpark() ? 'true' : 'false', PHP_EOL;
});
?>
--EXPECT--
parked as: BLOCKED on "recv on channel #1"
first unpark: true
second unpark: false
third unpark: false
now: READY, waiting on NULL
the waiter resumed exactly once
unpark after it finished: false
