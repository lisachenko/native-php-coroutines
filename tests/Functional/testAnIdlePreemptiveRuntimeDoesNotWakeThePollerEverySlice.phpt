--TEST--
An idle preemptive runtime blocks in one poll instead of waking once per slice
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\StreamPoller;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

include __DIR__ . '/../../vendor/autoload.php';

// A free-running 10 ms slice timer raises SIGALRM about a hundred times a second, and every one of
// them cuts stream_select() short — so an idle preemptive server pays ~100 wakeups a second to
// preempt nothing at all. The assertion is on the *count* rather than on the clock: no timing
// measurement can tell "slept for a second" from "woke a hundred times and went back to sleep".
const IDLE_SECONDS = 1.0;
const BOUND        = 5;

[$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$runtime = new Runtime(preemptive: true);
$armed   = false;

$wallBefore = hrtime(true);

$runtime->run(static function (TaskRuntime $self) use ($readEnd, &$armed): void {
    $armed = $self->preemptor()?->isArmed() ?? false;

    // Nothing is ever written to this descriptor. It is here so the idle turn is a real blocking
    // stream_select() with something in its read set — the shape a server waiting for work has.
    Coroutine::spawn(static function () use ($readEnd): void {
        Io::awaitReadable($readEnd);

        echo 'the reader must never wake', PHP_EOL;
    });

    Coroutine::sleep(IDLE_SECONDS);
});

$wall    = (hrtime(true) - $wallBefore) / 1_000_000_000;
$poller  = $runtime->scheduler()->poller();
$wakeups = $poller instanceof StreamPoller ? $poller->wakeups() : PHP_INT_MAX;

echo 'the runtime was slicing throughout: ', $armed ? 'yes' : 'no', PHP_EOL;
echo 'it idled for the whole second: ', $wall >= 0.99 ? 'yes' : 'no', PHP_EOL;
echo 'poller wakeups over that second: ', $wakeups <= BOUND ? 'far below 100' : 'NO (' . $wakeups . ')', PHP_EOL;

fclose($writeEnd);
fclose($readEnd);
?>
--EXPECT--
the runtime was slicing throughout: yes
it idled for the whole second: yes
poller wakeups over that second: far below 100
