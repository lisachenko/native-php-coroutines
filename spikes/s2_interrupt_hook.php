<?php
declare(strict_types=1);

/**
 * S2 — Forced yield from a z-engine InterruptHook FFI callback.
 *
 * QUESTION
 *   S1 showed that a pcntl signal handler cannot Fiber::suspend() ("Cannot
 *   switch fibers in current execution context"), synchronously or
 *   asynchronously. Does the engine's OWN interrupt callback
 *   (zend_interrupt_function, exposed by z-engine as InterruptHook) sit outside
 *   that block? If yes, an FFI-installed interrupt callback could suspend the
 *   running fiber and give us true preemption.
 *
 * GO-CRITERION (GREEN)
 *   1. Core::init() succeeds under the running minor.
 *   2. The interrupt hook fires (sanity: manual requestInterrupt()).
 *   3. Fiber::suspend() from inside the FFI callback suspends the fiber, the
 *      sentinel reaches the resumer, resume() restores state, and the
 *      preempted arithmetic result equals the non-preempted reference.
 *   4. No crash, no fatal, stable over hundreds of preemptions.
 *
 * DESIGN NOTE — why the double interrupt
 *   The pcntl C signal handler already raises EG(vm_interrupt). On that first
 *   interrupt our hook runs, sees no pending preempt request, and proceeds to
 *   pcntl's chained handler, which dispatches the PHP signal handler. That PHP
 *   handler only sets a flag and re-raises EG(vm_interrupt). The NEXT interrupt
 *   check therefore enters our FFI callback *outside* pcntl's signal-dispatch
 *   frame — which is exactly the context S1 could not reach.
 *
 * SAFETY
 *   The whole callback body is wrapped in try/catch: a throw escaping an FFI
 *   callback is an uncatchable "Throwing from FFI callbacks is not allowed"
 *   fatal. Every class the callback touches is preloaded before install, because
 *   autoloading from an engine callback re-enters the compiler.
 *
 * HOW TO RUN
 *   timeout 30 php8.4 -d ffi.enable=1 -d opcache.jit=off s2_interrupt_hook.php   # with ze84/vendor
 *   timeout 30 php8.5 -d ffi.enable=1 -d opcache.jit=off s2_interrupt_hook.php   # with ze85/vendor
 *
 * VERDICT LINE
 *   "VERDICT S2: GREEN|RED|CRASH|BLOCKED — ..."
 */

const WORK_ITERATIONS = 200_000_000;
const TICK_US         = 10_000;

$minor     = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$vendorDir = ($minor === '8.4')
    ? __DIR__ . '/ze84/vendor/autoload.php'
    : __DIR__ . '/ze85/vendor/autoload.php';

if (!is_file($vendorDir)) {
    printf("VERDICT S2: BLOCKED — z-engine vendor dir not found at %s\n", $vendorDir);
    exit(1);
}
require $vendorDir;

// ---------------------------------------------------------------------------
// 2d child mode — deliberately let an exception escape the FFI callback, to
// document the failure mode empirically. Run only in a forked-off subprocess.
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--throw-probe') {
    \ZEngine\Core::init();
    \ZEngine\Core::preload();
    \ZEngine\Core::setInterruptHandler(static function (object $h): void {
        throw new \RuntimeException('escaping the FFI callback on purpose');
    });
    \ZEngine\Core::$executor->requestInterrupt();
    $s = 0;
    for ($i = 0; $i < 1000; $i++) {
        $s += $i;
    }
    echo "THROW-PROBE: survived, exception did not kill the process\n";
    exit(0);
}

printf("PHP %s | vendor=%s\n", PHP_VERSION, $vendorDir);

// ---------------------------------------------------------------------------
// 2a — Core::init()
// ---------------------------------------------------------------------------
try {
    \ZEngine\Core::init();
} catch (\Throwable $e) {
    printf("VERDICT S2: BLOCKED — Core::init() failed: %s: %s\n", get_class($e), $e->getMessage());
    exit(1);
}
$lock = dirname($vendorDir) . '/../composer.lock';
$ver  = 'unknown';
if (is_file($lock)) {
    $data = json_decode((string) file_get_contents($lock), true);
    foreach (($data['packages'] ?? []) as $p) {
        if (($p['name'] ?? '') === 'lisachenko/z-engine') {
            $ver = ($p['version'] ?? '?') . ' @ ' . substr((string) ($p['source']['reference'] ?? '?'), 0, 10);
        }
    }
}
printf("2a Core::init() OK — z-engine %s\n", $ver);

// Preload everything the callback can touch (no autoloading inside the hook).
\ZEngine\Core::preload();
class_exists(\ZEngine\System\Hook\InterruptHook::class);
class_exists(\ZEngine\System\ExecutionData::class);
class_exists(\Fiber::class);
class_exists(\FiberError::class);

// ---------------------------------------------------------------------------
// Shared state touched by the FFI callback. Plain scalars only.
// ---------------------------------------------------------------------------
$hookFired      = 0;
$preemptWanted  = false;
$suspendTried   = 0;
$suspendError   = null;
$suspendErrors  = 0;
$callbackErrors = 0;
$enabled        = false;

$executor = \ZEngine\Core::$executor;

$hook = \ZEngine\Core::setInterruptHandler(
    static function (\ZEngine\System\Hook\InterruptHook $h) use (
        &$hookFired, &$preemptWanted, &$suspendTried, &$suspendError,
        &$suspendErrors, &$callbackErrors, &$enabled
    ): void {
        // EVERYTHING inside try/catch: a throw escaping an FFI callback is fatal.
        try {
            $hookFired++;

            if ($enabled && $preemptWanted && \Fiber::getCurrent() !== null) {
                $preemptWanted = false;
                $suspendTried++;
                \Fiber::suspend('PREEMPT');
                // Execution resumes here when the scheduler resumes the fiber.
            }
        } catch (\Throwable $e) {
            $suspendErrors++;
            if ($suspendError === null) {
                $suspendError = get_class($e) . ': ' . $e->getMessage();
            }
        }

        try {
            if ($h->hasOriginalHandler()) {
                $h->proceed();
            }
        } catch (\Throwable $e) {
            $callbackErrors++;
        }
    }
);

// ---------------------------------------------------------------------------
// 2b — sanity: does the hook fire at all?
// ---------------------------------------------------------------------------
$before = $hookFired;
$executor->requestInterrupt();
$spin = 0;
for ($i = 0; $i < 1000; $i++) {
    $spin += $i;
}
printf("2b manual requestInterrupt(): hook fired %d time(s) => %s\n",
    $hookFired - $before, $hookFired > $before ? 'OK' : 'HOOK NEVER FIRED');

if ($hookFired === $before) {
    printf("VERDICT S2: BLOCKED — InterruptHook never fired; cannot test suspension\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2c — the real test: preempt a call-free loop from inside the FFI callback.
// ---------------------------------------------------------------------------
$reference = 0;
$t0 = hrtime(true);
for ($i = 0; $i < WORK_ITERATIONS; $i++) {
    $reference += $i % 7;
}
$refSecs = (hrtime(true) - $t0) / 1e9;

pcntl_async_signals(true);
pcntl_signal(SIGALRM, static function () use (&$preemptWanted, $executor): void {
    // Cannot suspend here (proved by S1) — only arm the request and re-raise
    // the VM interrupt so the FFI callback runs outside pcntl's dispatch frame.
    $preemptWanted = true;
    $executor->requestInterrupt();
});

$parentPid = getmypid();
$tickerPid = pcntl_fork();
if ($tickerPid === 0) {
    pcntl_async_signals(false);
    pcntl_signal(SIGALRM, SIG_IGN);
    $deadline = microtime(true) + 20.0;
    while (microtime(true) < $deadline) {
        usleep(TICK_US);
        if (!posix_kill($parentPid, SIGALRM)) {
            break;
        }
    }
    exit(0);
}

$enabled = true;
$fiber   = new \Fiber(static function (): int {
    $x = 0;
    for ($i = 0; $i < WORK_ITERATIONS; $i++) {
        $x += $i % 7;
    }
    return $x;
});

$preemptions = 0;
$bad         = 0;
$lat         = [];
$schedErr    = [];
$t0          = hrtime(true);
$last        = $t0;

try {
    $v = $fiber->start();
    while (!$fiber->isTerminated()) {
        $now = hrtime(true);
        if ($v === 'PREEMPT') {
            $preemptions++;
            $lat[] = ($now - $last) / 1e6;
        } else {
            $bad++;
        }
        $last = $now;
        $v    = $fiber->resume(null);
    }
} catch (\Throwable $e) {
    $schedErr[] = get_class($e) . ': ' . $e->getMessage();
}
$workSecs = (hrtime(true) - $t0) / 1e9;
$enabled  = false;
@posix_kill($tickerPid, SIGKILL);
@pcntl_waitpid($tickerPid, $st, WNOHANG);

$got = null;
try {
    $got = $fiber->getReturn();
} catch (\Throwable $e) {
    $schedErr[] = 'getReturn: ' . get_class($e) . ': ' . $e->getMessage();
}

sort($lat);
$n    = count($lat);
$mean = $n ? array_sum($lat) / $n : 0.0;
$p99  = $n ? $lat[max(0, (int) ($n * 0.99) - 1)] : 0.0;
$max  = $n ? $lat[$n - 1] : 0.0;

printf("2c reference %.3f s | preempted work %.3f s (%+.1f%%)\n",
    $refSecs, $workSecs, $refSecs > 0 ? ($workSecs / $refSecs - 1) * 100 : 0);
printf("2c hookFired=%d suspendTried=%d preemptions=%d badValues=%d\n",
    $hookFired, $suspendTried, $preemptions, $bad);
printf("2c slice ms: mean=%.3f p99=%.3f max=%.3f\n", $mean, $p99, $max);
printf("2c suspendErrors=%d first=%s | proceedErrors=%d\n",
    $suspendErrors, $suspendError ?? '(none)', $callbackErrors);
printf("2c result=%s reference=%d stateIntact=%s\n",
    var_export($got, true), $reference, $got === $reference ? 'YES' : 'NO');
if ($schedErr) {
    printf("2c schedulerErrors: %s\n", implode(' | ', $schedErr));
}

$hook->uninstall();

// ---------------------------------------------------------------------------
// 2e — design refinement: can the hook decide by DEADLINE instead of waiting for
// the PHP signal handler to set a flag? pcntl's C-level signal handler already
// raises EG(vm_interrupt) for any registered signal, so the hook fires on the
// first interrupt after every tick. If the hook compares hrtime() against its
// own deadline it can suspend right there — one interrupt per preemption
// instead of two, and no dependency on pcntl's PHP-level dispatch.
// ---------------------------------------------------------------------------
$deadline   = PHP_INT_MAX;
$fired2e    = 0;
$tried2e    = 0;
$errs2e     = 0;
$firstErr2e = null;
$on2e       = false;

$hook2 = \ZEngine\Core::setInterruptHandler(
    static function (\ZEngine\System\Hook\InterruptHook $h) use (
        &$deadline, &$fired2e, &$tried2e, &$errs2e, &$firstErr2e, &$on2e
    ): void {
        try {
            $fired2e++;
            if ($on2e && hrtime(true) >= $deadline && \Fiber::getCurrent() !== null) {
                $deadline = PHP_INT_MAX;
                $tried2e++;
                \Fiber::suspend('PREEMPT');
            }
        } catch (\Throwable $e) {
            $errs2e++;
            $firstErr2e ??= get_class($e) . ': ' . $e->getMessage();
        }
        try {
            if ($h->hasOriginalHandler()) {
                $h->proceed();
            }
        } catch (\Throwable) {
        }
    }
);

// An EMPTY PHP signal handler: it exists only so pcntl registers a C handler
// that raises EG(vm_interrupt). It sets no flag and does no work.
pcntl_signal(SIGALRM, static function (): void {
});

$tickerPid2 = pcntl_fork();
if ($tickerPid2 === 0) {
    pcntl_async_signals(false);
    pcntl_signal(SIGALRM, SIG_IGN);
    $dl = microtime(true) + 15.0;
    while (microtime(true) < $dl) {
        usleep(TICK_US);
        if (!posix_kill($parentPid, SIGALRM)) {
            break;
        }
    }
    exit(0);
}

$N2e   = 60_000_000;
$ref2e = 0;
for ($i = 0; $i < $N2e; $i++) {
    $ref2e += $i % 7;
}

$on2e     = true;
$deadline = hrtime(true) + TICK_US * 1000;
$f2e      = new \Fiber(static function () use ($N2e): int {
    $x = 0;
    for ($i = 0; $i < $N2e; $i++) {
        $x += $i % 7;
    }
    return $x;
});
$p2e   = 0;
$lat2e = [];
$t2e   = hrtime(true);
$last  = $t2e;
$v2e   = $f2e->start();
while (!$f2e->isTerminated()) {
    $now = hrtime(true);
    if ($v2e === 'PREEMPT') {
        $p2e++;
        $lat2e[] = ($now - $last) / 1e6;
    }
    $last     = $now;
    $deadline = hrtime(true) + TICK_US * 1000;   // next slice starts now
    $v2e      = $f2e->resume(null);
}
$secs2e = (hrtime(true) - $t2e) / 1e9;
$on2e   = false;
@posix_kill($tickerPid2, SIGKILL);
@pcntl_waitpid($tickerPid2, $st2, WNOHANG);
sort($lat2e);
$n2e = count($lat2e);
printf("2e deadline-driven hook (no flag from the PHP handler): preemptions=%d in %.3f s, hookFired=%d (%.2f per preemption), slice ms mean=%.3f max=%.3f, errors=%d %s, result=%s\n",
    $p2e, $secs2e, $fired2e, $p2e > 0 ? $fired2e / $p2e : 0,
    $n2e ? array_sum($lat2e) / $n2e : 0, $n2e ? $lat2e[$n2e - 1] : 0,
    $errs2e, $firstErr2e ?? '', $f2e->getReturn() === $ref2e ? 'CORRECT' : 'WRONG');
$hook2->uninstall();

// ---------------------------------------------------------------------------
// 2d — what happens when a throw escapes the FFI callback? Run in a subprocess
// so the (expected) fatal cannot take this run down.
// ---------------------------------------------------------------------------
$cmd = sprintf(
    '%s -d ffi.enable=1 -d opcache.jit=off %s --throw-probe 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(__FILE__)
);
$out = [];
$rc  = 0;
exec($cmd, $out, $rc);
$outStr = trim(implode(' / ', $out));
$rcNote = match (true) {
    $rc === 0                  => ' (survived)',
    $rc === 255                => ' (PHP fatal error)',
    $rc > 128 && $rc < 165     => sprintf(' (killed by signal %d)', $rc - 128),
    default                    => '',
};
$fatal = '';
foreach ($out as $line) {
    if (str_contains($line, 'Fatal error')) {
        $fatal = trim($line);
    }
}
printf("2d throw-escaping-FFI-callback probe: exit=%d%s fatal=%s\n",
    $rc, $rcNote, $fatal === '' ? '(none captured)' : $fatal);

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------
$reasons = [];
if ($suspendErrors > 0) {
    $reasons[] = sprintf('Fiber::suspend() from the FFI interrupt callback raised %s (%dx)',
        $suspendError, $suspendErrors);
}
if ($preemptions === 0) {
    $reasons[] = 'zero preemptions observed';
}
if ($bad > 0) {
    $reasons[] = sprintf('%d non-sentinel resume values', $bad);
}
if ($got !== $reference) {
    $reasons[] = 'state corrupted';
}
if ($schedErr) {
    $reasons[] = 'scheduler errors: ' . implode(' | ', $schedErr);
}
if ($preemptions > 0 && $preemptions < 300) {
    $reasons[] = sprintf('only %d preemptions — below the stability bar', $preemptions);
}

if ($reasons === []) {
    printf("VERDICT S2: GREEN — Fiber::suspend() is legal from a z-engine InterruptHook FFI callback; %d preemptions, mean slice %.2f ms, max %.2f ms, state intact.\n",
        $preemptions, $mean, $max);
    exit(0);
}
printf("VERDICT S2: RED — %s\n", implode('; ', $reasons));
exit(1);
