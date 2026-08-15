--TEST--
An uncaught throwable in any coroutine is a panic that terminates the run and leaves run()
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

try {
    $runtime->run(function (): void {
        Coroutine::spawn(function (): void {
            echo 'the doomed coroutine runs', PHP_EOL;

            throw new RuntimeException('the sky is falling');
        });

        Coroutine::sleep(0.01);

        echo 'main must not resume after the panic', PHP_EOL;
    });
} catch (RuntimeException $panic) {
    echo 'panic escaped run(): ', $panic->getMessage(), PHP_EOL;
}

// A caught panic is a plain exception, so the process is still perfectly usable afterwards.
$second = new Runtime();
$second->run(function (): void {
    echo 'a fresh runtime still works', PHP_EOL;
});
?>
--EXPECT--
the doomed coroutine runs
panic escaped run(): the sky is falling
a fresh runtime still works
