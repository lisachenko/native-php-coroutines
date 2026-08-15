--TEST--
Timer::after runs a callback on its deadline, earliest first, and a cancelled timer never fires
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Timer;

include __DIR__ . '/../../vendor/autoload.php';

$runtime = new Runtime();

$runtime->run(function (): void {
    $fired = [];

    Timer::after(0.06, static function () use (&$fired): void {
        $fired[] = 'late';
    });

    Timer::after(0.02, static function () use (&$fired): void {
        $fired[] = 'early';
    });

    // A timer callback runs on the scheduler's own stack, so it may spawn but must not suspend.
    Timer::after(0.04, static function () use (&$fired): void {
        Coroutine::spawn(static function () use (&$fired): void {
            $fired[] = 'spawned-from-a-timer';
        });
    });

    $doomed = Timer::after(0.03, static function () use (&$fired): void {
        $fired[] = 'cancelled';
    });

    echo 'cancelling a pending timer: ', Timer::cancel($doomed) ? 'true' : 'false', PHP_EOL;
    echo 'cancelling it again: ', Timer::cancel($doomed) ? 'true' : 'false', PHP_EOL;

    Coroutine::sleep(0.1);

    echo implode(' ', $fired), PHP_EOL;
});
?>
--EXPECT--
cancelling a pending timer: true
cancelling it again: false
early spawned-from-a-timer late
