--TEST--
A readiness watch owns no coroutine: it fires a callback that wakes somebody else
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

[$pokeEnd, $wakeEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$runtime = new Runtime();

$runtime->run(function () use ($pokeEnd, $wakeEnd): void {
    $scheduler = Scheduler::active();
    $poller    = $scheduler->poller();

    // Parked on a primitive whose wakeup arrives from outside — the shape a shared channel has.
    $sleeper = Coroutine::spawn(static function () use ($scheduler): void {
        $scheduler->current()?->park('recv on shared channel #1', true);
        $scheduler->suspend(SuspendCommand::BLOCKED);

        echo 'the woken coroutine ran', PHP_EOL;
    });

    $poller->watchReadable($wakeEnd, static function ($stream) use ($poller, $scheduler, $sleeper): void {
        // Pokes are level-triggered: an undrained pipe reports readable forever and spins the
        // poller, so draining is the callback's job.
        fread($stream, 1);
        $poller->unwatch($stream);

        echo 'the watch fired', PHP_EOL;

        if ($sleeper->unpark()) {
            $scheduler->schedule($sleeper);
        }
    });

    echo 'watch registered: ', $poller->hasWatches() ? 'yes' : 'no', PHP_EOL;

    Coroutine::sleep(0.02);
    fwrite($pokeEnd, "\x01");

    Coroutine::sleep(0.02);
    echo 'watch still registered: ', $poller->hasWatches() ? 'yes' : 'no', PHP_EOL;
});

fclose($pokeEnd);
fclose($wakeEnd);
?>
--EXPECT--
watch registered: yes
the watch fired
the woken coroutine ran
watch still registered: no
