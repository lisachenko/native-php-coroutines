--TEST--
fork() clears the slice timer in the child, and rearmAfterFork() puts it back
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Preemption\ItimerClock;

include __DIR__ . '/../../vendor/autoload.php';

// The clock on its own only raises SIGALRM; the default disposition for it is "terminate the
// process", so anything arming a bare ItimerClock has to say what happens on delivery. The
// Preemptor installs a real handler — here the ticks are simply not the subject.
pcntl_signal(SIGALRM, SIG_IGN);

$clock = new ItimerClock(0.01);
$clock->arm();

echo 'armed in the parent, kernel interval in us: ', $clock->kernelIntervalMicroseconds(), PHP_EOL;

$child = pcntl_fork();

if ($child === 0) {
    // POSIX clears interval timers across fork(): the child inherits the FFI binding and the
    // signal disposition, but not the timer. A worker that does not re-arm is simply never
    // preempted, and nothing anywhere reports it.
    $inherited = $clock->kernelIntervalMicroseconds();

    $clock->rearmAfterFork();

    $rearmed = $clock->kernelIntervalMicroseconds();

    file_put_contents('php://stdout', sprintf(
        "inherited by the child, kernel interval in us: %d\nafter rearmAfterFork(), kernel interval in us: %d\n",
        $inherited,
        $rearmed,
    ));

    $clock->disarm();

    exit(0);
}

pcntl_waitpid($child, $status);

echo 'the child exited cleanly: ', pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0 ? 'yes' : 'no', PHP_EOL;
echo 'the parent timer is untouched, kernel interval in us: ', $clock->kernelIntervalMicroseconds(), PHP_EOL;

$clock->disarm();

echo 'disarmed, kernel interval in us: ', $clock->kernelIntervalMicroseconds(), PHP_EOL;
?>
--EXPECT--
armed in the parent, kernel interval in us: 10000
inherited by the child, kernel interval in us: 0
after rearmAfterFork(), kernel interval in us: 10000
the child exited cleanly: yes
the parent timer is untouched, kernel interval in us: 10000
disarmed, kernel interval in us: 0
