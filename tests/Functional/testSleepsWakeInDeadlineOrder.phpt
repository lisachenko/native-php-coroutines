--TEST--
Sleeping coroutines wake in deadline order, not in the order they went to sleep
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
    $woke = [];

    // Deliberately armed longest-first: a queue would report them back in this order, a heap
    // reports them in deadline order.
    foreach (['long' => 0.09, 'short' => 0.01, 'medium' => 0.05] as $name => $delay) {
        Coroutine::spawn(function () use ($name, $delay, &$woke): void {
            Coroutine::sleep($delay);
            $woke[] = $name;
        });
    }

    // Same deadline as an existing timer: ties break by arming order, so it comes after 'medium'.
    Coroutine::spawn(function () use (&$woke): void {
        Coroutine::sleep(0.05);
        $woke[] = 'medium-twin';
    });

    $start = hrtime(true);
    Coroutine::sleep(0.15);
    $elapsed = (hrtime(true) - $start) / 1_000_000_000;

    echo implode(' ', $woke), PHP_EOL;
    echo 'the sleeper that armed last woke last: ', $elapsed >= 0.15 ? 'yes' : 'no', PHP_EOL;
});
?>
--EXPECT--
short medium medium-twin long
the sleeper that armed last woke last: yes
