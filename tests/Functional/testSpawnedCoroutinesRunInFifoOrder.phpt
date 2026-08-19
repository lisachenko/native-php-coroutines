--TEST--
Spawned coroutines run in the order they were spawned, after the coroutine that spawned them
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\CoroutineStatus;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

$runtime = new Runtime();

$runtime->run(function (): void {
    $handles = [];

    foreach (['first', 'second', 'third'] as $name) {
        $handles[] = Coroutine::spawn(function () use ($name): string {
            echo $name, PHP_EOL;

            return strtoupper($name);
        });
    }

    // Spawning queues; it does not transfer control. The spawner keeps the CPU until it suspends.
    foreach ($handles as $handle) {
        echo 'queued as ', $handle->status()->name, PHP_EOL;
    }

    echo 'spawner still running', PHP_EOL;

    Coroutine::sleep(0.01);

    foreach ($handles as $handle) {
        echo 'finished as ', $handle->status()->name, ', returning ', var_export($handle->returnValue(), true), PHP_EOL;
    }

    echo 'terminal status is DONE: ', $handles[0]->status() === CoroutineStatus::DONE ? 'yes' : 'no', PHP_EOL;
});
?>
--EXPECT--
queued as READY
queued as READY
queued as READY
spawner still running
first
second
third
finished as DONE, returning 'FIRST'
finished as DONE, returning 'SECOND'
finished as DONE, returning 'THIRD'
terminal status is DONE: yes
