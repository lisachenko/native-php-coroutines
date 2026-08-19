<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Running a whole PHP process from a test, with a deadline on it.
 *
 * Some behaviour can only be observed at the end of a process: how it exits, what it wrote on its
 * way out, whether it exits at all. A test cannot observe that about itself, and a test that
 * *becomes* the runaway process it is checking hangs the suite instead of failing it. So the risky
 * script runs as a child here, supervised: read both pipes, hold a deadline, and `SIGKILL` it if the
 * deadline passes — a regression then comes back as `timedOut: true`, which is an assertion, not a
 * hang.
 */
declare(strict_types=1);

namespace Lisachenko\NativePhpCoroutines\Tests\Support;

/**
 * Run $script in its own PHP process and wait for it, but never longer than $timeout.
 *
 * The child gets the same two INI settings every `.phpt` in this suite declares: they are not
 * inherited from the parent's command line. Nothing filters diagnostics here either — a deprecation
 * raised inside the child is something a test should show, not swallow (see #39).
 *
 * @param  string $script  Absolute path of the PHP file to run.
 * @param  float  $timeout Seconds to wait before killing it.
 * @return array{stdout: string, stderr: string, signal: int, exitCode: int|null, timedOut: bool,
 *               seconds: float}
 */
function superviseChildProcess(string $script, float $timeout): array
{
    // The array form execs the binary directly. A string would go through a shell, which turns a
    // signalled death into an ordinary exit code and writes its own "Killed" into stderr — exactly
    // the two things a test about how a process ends must be able to tell apart.
    $command = [
        PHP_BINARY,
        '-d', 'ffi.enable=1',
        '-d', 'opcache.jit=off',
        $script,
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $started     = hrtime(true);
    $process     = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'proc_open() failed', 'signal' => 0, 'exitCode' => null,
            'timedOut' => false, 'seconds' => 0.0];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout   = '';
    $stderr   = '';
    $deadline = microtime(true) + $timeout;
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
        'signal'   => $status['signaled'] ? $status['termsig'] : 0,
        'exitCode' => $status['signaled'] ? null : $status['exitcode'],
        'timedOut' => $timedOut,
        'seconds'  => (hrtime(true) - $started) / 1e9,
    ];
}
