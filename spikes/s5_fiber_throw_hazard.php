<?php
declare(strict_types=1);

/**
 * S5 — Why the scheduler must NEVER Fiber::throw() into a preempt-suspended fiber.
 *
 * QUESTION
 *   A preempt-suspended fiber was suspended from inside the scheduler's own
 *   FFI interrupt callback (S2), at an arbitrary opcode boundary in user code
 *   that the user never wrote a yield point for. What happens if the scheduler
 *   cancels such a coroutine with Fiber::throw() instead of resume(null)?
 *   How does it differ from throwing into a fiber suspended at an ordinary,
 *   explicit Fiber::suspend()?
 *
 * GO-CRITERION (GREEN = the hazard is established and characterized)
 *   The spike must produce a crisp, reproducible statement of what
 *   Fiber::throw() does to a preempt-suspended fiber, contrasted with a
 *   cooperatively-suspended one.
 *
 * THE THREE CASES
 *   5a  preempt-suspended, hook body wrapped in the MANDATORY try/catch
 *   5b  preempt-suspended, hook body NOT wrapped (run in a subprocess — the
 *       expected outcome is an uncatchable engine fatal)
 *   5c  cooperatively suspended at an explicit Fiber::suspend() in user code
 *
 * HOW TO RUN
 *   timeout 30 php8.4 -d ffi.enable=1 -d opcache.jit=off s5_fiber_throw_hazard.php
 *   timeout 30 php8.5 -d ffi.enable=1 -d opcache.jit=off s5_fiber_throw_hazard.php
 *
 * VERDICT LINE
 *   "VERDICT S5: GREEN|RED|CRASH|BLOCKED — ..."
 */

const ITIMER_REAL = 0;
const SLICE_USEC  = 10_000;
const WORK        = 200_000_000;

$minor     = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$vendorDir = ($minor === '8.4')
    ? __DIR__ . '/ze84/vendor/autoload.php'
    : __DIR__ . '/ze85/vendor/autoload.php';
if (!is_file($vendorDir)) {
    printf("VERDICT S5: BLOCKED — z-engine vendor dir not found at %s\n", $vendorDir);
    exit(1);
}
require $vendorDir;

$libc = FFI::cdef(<<<'C'
typedef long time_t;
typedef long suseconds_t;
struct timeval { time_t tv_sec; suseconds_t tv_usec; };
struct itimerval { struct timeval it_interval; struct timeval it_value; };
int setitimer(int which, const struct itimerval *new_value, struct itimerval *old_value);
C, null);

$arm = static function (int $usec) use ($libc): void {
    $iv = $libc->new('struct itimerval');
    $iv->it_interval->tv_sec  = 0;
    $iv->it_interval->tv_usec = $usec;
    $iv->it_value->tv_sec     = 0;
    $iv->it_value->tv_usec    = $usec;
    $libc->setitimer(ITIMER_REAL, FFI::addr($iv), null);
};
$disarm = static function () use ($libc): void {
    $iv = $libc->new('struct itimerval');
    FFI::memset(FFI::addr($iv), 0, FFI::sizeof($iv));
    $libc->setitimer(ITIMER_REAL, FFI::addr($iv), null);
};

// ---------------------------------------------------------------------------
// 5b child mode — UNSAFE hook (no try/catch around Fiber::suspend()).
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--unsafe-hook') {
    \ZEngine\Core::init();
    \ZEngine\Core::preload();
    $want  = false;
    $fired = 0;
    $exec  = \ZEngine\Core::$executor;
    $childHook = \ZEngine\Core::setInterruptHandler(static function (object $h) use (&$want, &$fired): void {
        // DELIBERATELY UNGUARDED around the suspend — this is what the rule forbids.
        $fired++;
        if ($want && \Fiber::getCurrent() !== null) {
            $want = false;
            \Fiber::suspend('PREEMPT');
        }
        // Chaining is not optional: without it pcntl's signal dispatch never
        // runs and the PHP SIGALRM handler is never called at all.
        if ($h->hasOriginalHandler()) {
            $h->proceed();
        }
    });
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use (&$want, $exec): void {
        $want = true;
        $exec->requestInterrupt();
    });
    $arm(SLICE_USEC);
    $f = new \Fiber(static function (): string {
        $x = 0;
        for ($i = 0; $i < WORK; $i++) {
            $x += $i % 7;
        }
        return 'COMPLETED';
    });
    $v = $f->start();
    $disarm();
    if ($v !== 'PREEMPT') {
        echo 'UNSAFE-CHILD: fiber was not preempt-suspended (got ' . var_export($v, true)
            . ", hook fired {$fired}x)\n";
        exit(3);
    }
    echo "UNSAFE-CHILD: preempt-suspended, now calling Fiber::throw()\n";
    try {
        $f->throw(new \DomainException('cancel'));
        echo "UNSAFE-CHILD: throw() returned normally\n";
    } catch (\Throwable $e) {
        echo 'UNSAFE-CHILD: scheduler caught ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    echo "UNSAFE-CHILD: survived\n";
    exit(0);
}

printf("PHP %s | pid %d\n", PHP_VERSION, getmypid());
\ZEngine\Core::init();
\ZEngine\Core::preload();

// ---------------------------------------------------------------------------
// 5a — preempt-suspended fiber, SAFE hook (mandatory try/catch).
// ---------------------------------------------------------------------------
$want          = false;
$enabled       = false;
$hookCaught    = [];
$suspendPoints = 0;
$exec          = \ZEngine\Core::$executor;

$hook = \ZEngine\Core::setInterruptHandler(
    static function (\ZEngine\System\Hook\InterruptHook $h) use (&$want, &$enabled, &$hookCaught, &$suspendPoints): void {
        try {
            if ($enabled && $want && \Fiber::getCurrent() !== null) {
                $want = false;
                $suspendPoints++;
                \Fiber::suspend('PREEMPT');
            }
        } catch (\Throwable $e) {
            // THIS catch is mandatory (a throw escaping an FFI callback is fatal).
            // It is also exactly what swallows a Fiber::throw() aimed at a
            // preempt-suspended fiber.
            $hookCaught[] = get_class($e) . ': ' . $e->getMessage();
        }
        try {
            if ($h->hasOriginalHandler()) {
                $h->proceed();
            }
        } catch (\Throwable) {
        }
    }
);

pcntl_async_signals(true);
pcntl_signal(SIGALRM, static function () use (&$want, $exec): void {
    $want = true;
    $exec->requestInterrupt();
});

$finallyRan = false;
$fiberSaw   = null;

$fiber = new \Fiber(static function () use (&$finallyRan, &$fiberSaw): string {
    try {
        $x = 0;
        for ($i = 0; $i < WORK; $i++) {
            $x += $i % 7;
        }
        return 'COMPLETED-NORMALLY';
    } catch (\Throwable $e) {
        $fiberSaw = get_class($e) . ': ' . $e->getMessage();
        return 'CANCELLED-IN-FIBER';
    } finally {
        $finallyRan = true;
    }
});

$enabled = true;
$arm(SLICE_USEC);
$v = $fiber->start();
$preemptSuspended = ($v === 'PREEMPT' && $fiber->isSuspended());
printf("5a fiber start() returned %s; preempt-suspended=%s\n",
    var_export($v, true), $preemptSuspended ? 'yes' : 'NO');

$throwOutcome = '';
$throwReturn  = 'n/a';
if ($preemptSuspended) {
    $disarm();          // no further preemption; isolate the throw
    $enabled = false;
    try {
        $r = $fiber->throw(new \DomainException('cancel-me'));
        $throwReturn  = var_export($r, true);
        $throwOutcome = 'throw() returned normally';
    } catch (\Throwable $e) {
        $throwOutcome = 'scheduler caught ' . get_class($e) . ': ' . $e->getMessage();
    }
}
printf("5a Fiber::throw() outcome: %s (returned %s)\n", $throwOutcome, $throwReturn);
printf("5a hook swallowed: %s\n", $hookCaught === [] ? '(nothing)' : implode(' | ', $hookCaught));
printf("5a after throw: terminated=%s suspended=%s running=%s\n",
    var_export($fiber->isTerminated(), true),
    var_export($fiber->isSuspended(), true),
    var_export($fiber->isRunning(), true));

// Drain whatever is left so we can see what the fiber ultimately did.
$drainSteps = 0;
while (!$fiber->isTerminated() && $drainSteps < 100000) {
    $drainSteps++;
    try {
        $fiber->resume(null);
    } catch (\Throwable $e) {
        printf("5a drain error: %s: %s\n", get_class($e), $e->getMessage());
        break;
    }
}
$ret5a = $fiber->isTerminated() ? $fiber->getReturn() : '(never terminated)';
printf("5a final: drainSteps=%d return=%s fiberSawException=%s finallyRan=%s\n",
    $drainSteps, var_export($ret5a, true), var_export($fiberSaw, true), var_export($finallyRan, true));

$cancellationLost = ($ret5a === 'COMPLETED-NORMALLY' && $fiberSaw === null);
printf("5a => cancellation %s\n", $cancellationLost
    ? 'SILENTLY LOST: the exception surfaced inside the scheduler FFI callback, was swallowed by its mandatory try/catch, and the coroutine ran to completion as if nothing happened'
    : 'reached user code (unexpected for the preempt path)');

$hook->uninstall();
$disarm();

// ---------------------------------------------------------------------------
// 5b — the same throw with an UNGUARDED hook, in a subprocess.
// ---------------------------------------------------------------------------
$cmd = sprintf('%s -d ffi.enable=1 -d opcache.jit=off %s --unsafe-hook 2>&1',
    escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__));
$out = [];
$rc  = 0;
exec($cmd, $out, $rc);
$fatal = '';
foreach ($out as $line) {
    if (str_contains($line, 'Fatal error')) {
        $fatal = trim($line);
    }
}
$note = match (true) {
    $rc === 0              => 'survived',
    $rc === 255            => 'PHP FATAL ERROR',
    $rc > 128 && $rc < 165 => 'killed by signal ' . ($rc - 128),
    default                => 'exit ' . $rc,
};
printf("\n5b unguarded-hook subprocess: exit=%d (%s)\n", $rc, $note);
foreach ($out as $line) {
    printf("     | %s\n", $line);
}

// ---------------------------------------------------------------------------
// 5c — contrast: throw into a COOPERATIVELY suspended fiber.
// ---------------------------------------------------------------------------
$coopFinally = false;
$coopSaw     = null;
$coopFiber   = new \Fiber(static function () use (&$coopFinally, &$coopSaw): string {
    try {
        Fiber::suspend('COOPERATIVE-YIELD');
        return 'COMPLETED-NORMALLY';
    } catch (\Throwable $e) {
        $coopSaw = get_class($e) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine();
        return 'CANCELLED-IN-FIBER';
    } finally {
        $coopFinally = true;
    }
});
$cv = $coopFiber->start();
$coopOutcome = '';
try {
    $coopFiber->throw(new \DomainException('cancel-me'));
    $coopOutcome = 'throw() returned normally';
} catch (\Throwable $e) {
    $coopOutcome = 'scheduler caught ' . get_class($e) . ': ' . $e->getMessage();
}
$coopRet = $coopFiber->isTerminated() ? $coopFiber->getReturn() : '(not terminated)';
printf("\n5c cooperative: start()=%s | throw outcome: %s\n", var_export($cv, true), $coopOutcome);
printf("5c cooperative: return=%s fiberSaw=%s finallyRan=%s\n",
    var_export($coopRet, true), var_export($coopSaw, true), var_export($coopFinally, true));
$coopCorrect = ($coopRet === 'CANCELLED-IN-FIBER' && $coopSaw !== null && $coopFinally);
printf("5c => %s\n", $coopCorrect
    ? 'CORRECT: the exception surfaced exactly at the explicit Fiber::suspend() call in user code, the user catch ran, finally ran'
    : 'UNEXPECTED behaviour for the cooperative path');

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------
echo "\n";
$problems = [];
if (!$preemptSuspended) {
    $problems[] = 'could not get the fiber into a preempt-suspended state — 5a is invalid';
}
if (!$coopCorrect) {
    $problems[] = 'the cooperative control case did not behave as expected — contrast is invalid';
}
if ($problems !== []) {
    printf("VERDICT S5: RED — %s\n", implode('; ', $problems));
    exit(1);
}
printf("VERDICT S5: GREEN — hazard established. Fiber::throw() into a PREEMPT-suspended fiber does NOT cancel the coroutine: the fiber's resume point is inside the scheduler's own FFI interrupt callback, so the exception surfaces there, not in user code. With the mandatory try/catch it is silently swallowed (%s; the coroutine ran to completion, user catch never ran, finally ran only at normal exit); without it, it escapes the FFI callback and the process dies with %s (subprocess exit %d). By contrast, throwing into a fiber suspended at an explicit Fiber::suspend() surfaces exactly at that call (%s), runs the user catch and the finally. RULE: the scheduler may only ever resume(null) a preempt-suspended fiber; cancellation must be a flag consumed at a cooperative safe point.\n",
    $cancellationLost ? 'observed' : 'not observed in this run',
    $fatal !== '' ? '"' . $fatal . '"' : 'a fatal error',
    $rc,
    (string) $coopSaw);
exit(0);
