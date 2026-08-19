--TEST--
A signal that cuts an idle poll short still leaves slicing armed for whatever runs next
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

include __DIR__ . '/../../vendor/autoload.php';

// The clock is stopped for the duration of an idle poll, so the re-arm has to survive every way out
// of that poll — and the interesting one is the way out that does not look like a return at all.
// A signal makes stream_select() fail with EINTR, and the poller *retries inside the same call*
// with what is left of the timeout. A re-arm placed on the readiness path, or after the select
// rather than around the whole poll, is skipped on exactly that path: the process then runs on
// cooperatively for the rest of its life behind a preemptor that still reports itself armed.
//
// SIGUSR1 from a helper process, not pcntl_alarm(): on Linux alarm() and setitimer(ITIMER_REAL) are
// the same timer, so an alarm here would silently overwrite the slice clock under test.
const IDLE_SECONDS  = 0.5;
const SIGNAL_AFTER  = 200_000;
const ITERATIONS    = 4_000_000;
const SLICE_MICROSECONDS = 10_000;

$parent = posix_getpid();
$helper = pcntl_fork();

if ($helper === 0) {
    usleep(SIGNAL_AFTER);
    posix_kill($parent, SIGUSR1);

    exit(0);
}

$interrupted = 0;

// restart_syscalls: false is what makes the kernel report EINTR to the select instead of
// restarting it behind PHP's back.
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, static function () use (&$interrupted): void {
    ++$interrupted;
}, false);

[$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

$state                     = new stdClass();
$state->ticks              = 0;
$state->ticksSeenByTheLoop = -1;
$state->kernelInterval     = -1;
$state->paused             = true;

$runtime = new Runtime(preemptive: true);

$wallBefore = hrtime(true);

$runtime->run(static function (TaskRuntime $self) use ($readEnd, $state): void {
    Coroutine::spawn(static function () use ($readEnd): void {
        Io::awaitReadable($readEnd);

        echo 'the reader must never wake', PHP_EOL;
    });

    // One idle poll, interrupted partway through by the helper's SIGUSR1 and retried from inside
    // the poller with the remaining timeout.
    Coroutine::sleep(IDLE_SECONDS);

    // Read from the kernel rather than from the preemptor's own bookkeeping: getitimer(2) is the
    // only witness that can tell a timer this process armed from one it merely believes in.
    $state->kernelInterval = $self->preemptor()?->clock()->kernelIntervalMicroseconds() ?? -1;
    $state->paused         = $self->preemptor()?->isSlicingPaused() ?? true;

    // And the behaviour the interval exists for: a call-free loop must still lose the CPU.
    Coroutine::spawn(static function () use ($state): void {
        $sum = 0;

        for ($index = 0; $index < ITERATIONS; $index++) {
            $sum += $index % 7;
        }

        $state->ticksSeenByTheLoop = $state->ticks;
    });

    Coroutine::spawn(static function () use ($state): void {
        for ($round = 0; $round < 10_000; $round++) {
            $state->ticks++;
            Coroutine::yield();
        }
    });

    for ($round = 0; $round < 3; $round++) {
        Coroutine::yield();
    }
});

$wall = (hrtime(true) - $wallBefore) / 1_000_000_000;

pcntl_waitpid($helper, $status);

echo 'the poll was cut short by a signal: ', $interrupted > 0 ? 'yes' : 'no', PHP_EOL;
echo 'it was retried for the rest of the timeout: ', $wall >= IDLE_SECONDS ? 'yes' : 'no', PHP_EOL;
echo 'the kernel interval afterwards, in us: ', $state->kernelInterval, PHP_EOL;
echo 'slicing was left paused: ', $state->paused ? 'yes' : 'no', PHP_EOL;
echo 'a call-free loop was still preempted afterwards: ',
    $state->ticksSeenByTheLoop >= 1 && ($runtime->preemptor()?->preemptions() ?? 0) >= 1 ? 'yes' : 'no', PHP_EOL;

fclose($writeEnd);
fclose($readEnd);
?>
--EXPECT--
the poll was cut short by a signal: yes
it was retried for the rest of the timeout: yes
the kernel interval afterwards, in us: 10000
slicing was left paused: no
a call-free loop was still preempted afterwards: yes
