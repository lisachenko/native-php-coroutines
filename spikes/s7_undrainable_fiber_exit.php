<?php
declare(strict_types=1);

/**
 * S7 — How does a process end while a preempt-suspended fiber is still alive?
 *
 * QUESTION
 *   S6 established that a fiber suspended inside the interrupt callback may not
 *   be destroyed: dropping the last reference, or leaving one alive at request
 *   shutdown, is `PHP Fatal error: Throwing from FFI callbacks is not allowed`,
 *   and uninstalling the hook first does not help. The drain
 *   (`resume(null)` until it terminates or parks) is the way out — but a
 *   coroutine that never cooperates is never drained, and the drain spins
 *   forever (issue #18).
 *
 *   Bounding that drain means deciding to stop while a fiber is still suspended
 *   in the callback. So: what endings does a process have from there, and is
 *   there one that reaches the end of the process WITHOUT the engine destroying
 *   that fiber?
 *
 * GO-CRITERION (GREEN)
 *   There is at least one ending that (a) terminates the process promptly,
 *   (b) preserves everything the runtime printed as its diagnosis, and
 *   (c) never produces the S6 fatal — i.e. the fiber is never destroyed.
 *
 * HOW TO RUN
 *   timeout 120 php8.4 -d ffi.enable=1 -d opcache.jit=off s7_undrainable_fiber_exit.php
 *   timeout 120 php8.5 -d ffi.enable=1 -d opcache.jit=off s7_undrainable_fiber_exit.php
 *
 *   z-engine is taken from spikes/ze84|ze85/vendor when those trees exist (the
 *   arrangement spikes/README.md describes), otherwise from the package's own
 *   vendor/ — which is only correct for the minor that tree was resolved by.
 *
 * VERDICT LINE
 *   "VERDICT S7: GREEN|RED|CRASH|BLOCKED — ..."
 */

const CHILD_MODES = [
    '--leave-installed',
    '--leave-uninstalled',
    '--exit',
    '--sigterm',
    '--sigkill',
    '--late-shutdown-function',
    '--drain',
];

const PREEMPT_USEC  = 2_000;
const CHILD_TIMEOUT = 15.0;
const FFI_FATAL     = 'Throwing from FFI callbacks is not allowed';

function s7_autoload(): ?string
{
    $minor      = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidates = [
        __DIR__ . ($minor === '8.4' ? '/ze84/vendor/autoload.php' : '/ze85/vendor/autoload.php'),
        __DIR__ . '/../vendor/autoload.php',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// CHILD — every mode ends with a preempt-suspended fiber still alive, except
// --drain, which is the control: it drains and exits the ordinary way.
// ---------------------------------------------------------------------------
$mode = $argv[1] ?? '';

if (in_array($mode, CHILD_MODES, true)) {
    $autoload = s7_autoload();
    if ($autoload === null) {
        fwrite(STDERR, "CHILD: BLOCKED — no vendor/autoload.php with z-engine\n");
        exit(4);
    }

    require $autoload;
    \ZEngine\Core::init();
    \ZEngine\Core::preload();

    $libc = FFI::cdef(<<<'C'
typedef long time_t;
typedef long suseconds_t;
struct timeval { time_t tv_sec; suseconds_t tv_usec; };
struct itimerval { struct timeval it_interval; struct timeval it_value; };
int setitimer(int which, const struct itimerval *new_value, struct itimerval *old_value);
C, null);

    $setInterval = static function (int $usec) use ($libc): void {
        $value = $libc->new('struct itimerval');
        $value->it_interval->tv_sec  = 0;
        $value->it_interval->tv_usec = $usec;
        $value->it_value->tv_sec     = 0;
        $value->it_value->tv_usec    = $usec;
        $libc->setitimer(0 /* ITIMER_REAL */, FFI::addr($value), null);
    };

    $want     = false;
    $executor = \ZEngine\Core::$executor;
    $hook     = \ZEngine\Core::setInterruptHandler(static function (object $hook) use (&$want): void {
        try {
            if ($want && \Fiber::getCurrent() !== null) {
                $want = false;
                \Fiber::suspend('PREEMPT');
            }
        } catch (\Throwable) {
        }

        try {
            if ($hook->hasOriginalHandler()) {
                $hook->proceed();
            }
        } catch (\Throwable) {
        }
    });

    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use (&$want, $executor): void {
        $want = true;
        $executor->requestInterrupt();
    });

    register_shutdown_function(static function () use ($mode): void {
        printf("CHILD(%s): a shutdown function ran\n", $mode);
    });

    $setInterval(PREEMPT_USEC);

    // The issue's coroutine, exactly: no park, no return, no cooperative point.
    $runaway = new \Fiber(static function (): void {
        $x = 0;

        while (true) {
            $x++;
        }
    });

    // For the control mode, a body that does finish once it is resumed enough.
    if ($mode === '--drain') {
        $runaway = new \Fiber(static function (): int {
            $sum = 0;

            for ($index = 0; $index < 2_000_000; $index++) {
                $sum += $index % 7;
            }

            return $sum;
        });
    }

    $suspendedWith = $runaway->start();
    $setInterval(0);

    if ($suspendedWith !== 'PREEMPT') {
        printf("CHILD(%s): never preempt-suspended (got %s)\n", $mode, var_export($suspendedWith, true));
        exit(4);
    }

    printf("CHILD(%s): the fiber is preempt-suspended\n", $mode);

    switch ($mode) {
        case '--drain':
            $resumes = 0;

            while (!$runaway->isTerminated()) {
                $setInterval(PREEMPT_USEC);
                $runaway->resume(null);
                $setInterval(0);
                $resumes++;
            }

            printf("CHILD(--drain): drained in %d resume(s)\n", $resumes);

            break;

        case '--leave-uninstalled':
            $hook->uninstall();
            printf("CHILD(--leave-uninstalled): hook uninstalled, letting the script end\n");

            break;

        case '--leave-installed':
            printf("CHILD(--leave-installed): letting the script end with the fiber alive\n");

            break;

        case '--exit':
            printf("CHILD(--exit): calling exit(70) with the fiber alive\n");
            exit(70);

        case '--sigterm':
        case '--sigkill':
            $signal = $mode === '--sigkill' ? SIGKILL : SIGTERM;
            printf("CHILD(%s): diagnosis on stdout before the signal\n", $mode);
            fwrite(STDERR, sprintf("CHILD(%s): diagnosis on stderr before the signal\n", $mode));
            flush();
            posix_kill(posix_getpid(), $signal);

            // Reached only if the signal did not end the process.
            printf("CHILD(%s): SURVIVED the signal\n", $mode);

            break;

        case '--late-shutdown-function':
            printf("CHILD(--late-shutdown-function): registering from inside shutdown\n");
            register_shutdown_function(static function (): void {
                register_shutdown_function(static function (): void {
                    printf("CHILD(--late-shutdown-function): the late registration ran\n");
                    fwrite(STDERR, "CHILD(--late-shutdown-function): killing from the late one\n");
                    flush();
                    posix_kill(posix_getpid(), SIGKILL);
                });
            });

            break;
    }

    exit(0);
}

// ---------------------------------------------------------------------------
// PARENT
// ---------------------------------------------------------------------------
/** @return array{stdout: string, stderr: string, exit: int, signal: int, seconds: float, timedOut: bool} */
function s7_run(string $mode): array
{
    // The array form execs the binary directly: no shell in between to turn a signal death into an
    // ordinary exit code and print "Killed" into the child's stderr.
    $command = [PHP_BINARY, '-d', 'ffi.enable=1', '-d', 'opcache.jit=off', __FILE__, $mode];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $started     = hrtime(true);
    $process     = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'proc_open failed', 'exit' => -1, 'signal' => 0,
            'seconds' => 0.0, 'timedOut' => false];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout   = '';
    $stderr   = '';
    $deadline = microtime(true) + CHILD_TIMEOUT;
    $timedOut = false;
    $status   = proc_get_status($process);

    while ($status['running']) {
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process, SIGKILL);

            break;
        }

        $read   = [$pipes[1], $pipes[2]];
        $write  = [];
        $except = [];

        if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
        }

        $status = proc_get_status($process);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [
        'stdout'   => $stdout,
        'stderr'   => $stderr,
        'exit'     => $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'],
        'signal'   => $status['signaled'] ? $status['termsig'] : 0,
        'seconds'  => (hrtime(true) - $started) / 1e9,
        'timedOut' => $timedOut,
    ];
}

printf("S7 — endings available to a process holding an undrainable fiber (PHP %s)\n\n", PHP_VERSION);

if (s7_autoload() === null) {
    echo "VERDICT S7: BLOCKED — no vendor/autoload.php carrying z-engine\n";
    exit(3);
}

$results = [];

foreach (CHILD_MODES as $childMode) {
    $result             = s7_run($childMode);
    $results[$childMode] = $result;
    $combined            = $result['stdout'] . $result['stderr'];

    printf(
        "%-26s exit=%-4s signal=%-2d %.2fs fatal=%s diagnosisKept=%s%s\n",
        $childMode,
        (string) $result['exit'],
        $result['signal'],
        $result['seconds'],
        str_contains($combined, FFI_FATAL) ? 'YES' : 'no ',
        str_contains($combined, 'preempt-suspended') ? 'yes' : 'NO ',
        $result['timedOut'] ? ' TIMED-OUT' : '',
    );

    foreach (explode("\n", trim($combined)) as $line) {
        if ($line !== '') {
            printf("    | %s\n", $line);
        }
    }
}

$kill  = $results['--sigkill'];
$clean = !str_contains($kill['stdout'] . $kill['stderr'], FFI_FATAL)
    && !$kill['timedOut']
    && $kill['signal'] === SIGKILL
    && str_contains($kill['stdout'], 'diagnosis on stdout')
    && str_contains($kill['stderr'], 'diagnosis on stderr');

$fatalOnLeave = str_contains($results['--leave-installed']['stdout'] . $results['--leave-installed']['stderr'], FFI_FATAL);

printf(
    "\nVERDICT S7: %s — leaving the fiber for request shutdown %s; a self-directed SIGKILL after the "
    . "diagnosis %s\n",
    $clean ? 'GREEN' : 'RED',
    $fatalOnLeave ? 'IS the S6 fatal' : 'did NOT fatal (unexpected)',
    $clean ? 'ends the process with the diagnosis intact and no fatal' : 'did not behave as required',
);

exit($clean ? 0 : 1);
