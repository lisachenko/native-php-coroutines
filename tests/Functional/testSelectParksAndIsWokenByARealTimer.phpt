--TEST--
A select parks on the real scheduler and is woken by a send from a sleeping coroutine
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\Select;

include __DIR__ . '/../../vendor/autoload.php';

// Neither case is ready when the select runs, so it must genuinely park on both
// channels and stay parked while the scheduler idles on its timer heap. The
// winning send then has to reach it through the real unpark path, and the losing
// channel must be unlinked rather than left holding a stale waiter.
(new Runtime())->run(function (RuntimeInterface $runtime): void {
    $scheduler = $runtime->scheduler();

    $fast = new Channel($scheduler, capacity: 1);
    $slow = new Channel($scheduler, capacity: 1);

    Coroutine::spawn(function () use ($fast): void {
        Coroutine::sleep(0.01);
        $fast->send('fast');
    });

    $result = Select::on($scheduler)
        ->recv($fast, static fn(mixed $value): string => 'fast: ' . (string) $value)
        ->recv($slow, static fn(mixed $value): string => 'slow: ' . (string) $value)
        ->run();

    echo $result, PHP_EOL;

    // The loser must have been unlinked when the select resolved. If its waiter
    // were still queued, this send would try to hand the value to a coroutine
    // that has long since moved on; with capacity spare it simply buffers.
    $slow->send('late');
    echo 'losing channel buffered: ', $slow->count(), PHP_EOL;
});
?>
--EXPECT--
fast: fast
losing channel buffered: 1
