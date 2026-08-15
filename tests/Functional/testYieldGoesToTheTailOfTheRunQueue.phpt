--TEST--
A yielding coroutine goes to the tail of the run queue, so peers round-robin fairly
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;

include __DIR__ . '/../../vendor/autoload.php';

$runtime = new Runtime();

$runtime->run(function (): void {
    $turns = [];

    foreach (['A', 'B', 'C'] as $name) {
        Coroutine::spawn(function () use ($name, &$turns): void {
            for ($round = 1; $round <= 3; ++$round) {
                $turns[] = $name . $round;
                Coroutine::yield();
            }
        });
    }

    // A yield that put the coroutine back at the *head* would produce A1 A2 A3 B1 B2 B3 ...:
    // one coroutine would run to completion while its peers starved.
    Coroutine::sleep(0.01);

    echo implode(' ', $turns), PHP_EOL;
});
?>
--EXPECT--
A1 B1 C1 A2 B2 C2 A3 B3 C3
