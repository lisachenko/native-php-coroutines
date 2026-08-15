--TEST--
When main returns, coroutines that are still pending are discarded rather than awaited
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

$started = [];

$runtime->run(function () use (&$started): void {
    $sleeper = Coroutine::spawn(function () use (&$started): void {
        $started[] = 'sleeper';
        Coroutine::sleep(30.0);
        echo 'the sleeper must never get here', PHP_EOL;
    });

    // Let the sleeper reach its 30-second park before anything else is queued.
    Coroutine::yield();

    $queued = Coroutine::spawn(function () use (&$started): void {
        $started[] = 'queued';
        echo 'the queued coroutine must never get here', PHP_EOL;
    });

    echo 'sleeper is ', $sleeper->status()->name, PHP_EOL;
    echo 'queued peer is ', $queued->status()->name, PHP_EOL;
    echo 'main returns now', PHP_EOL;
});

// Go semantics: the run is over when main is, and this line is reached immediately rather than
// thirty seconds from now.
echo 'run returned, started: ', implode(' ', $started), PHP_EOL;

// Discarded means gone, not deferred: a later run on the same runtime does not inherit them.
$runtime->run(function (): void {
    Coroutine::sleep(0.02);
    echo 'the second run saw nothing left over', PHP_EOL;
});

echo 'still started: ', implode(' ', $started), PHP_EOL;
?>
--EXPECT--
sleeper is BLOCKED
queued peer is READY
main returns now
run returned, started: sleeper
the second run saw nothing left over
still started: sleeper
