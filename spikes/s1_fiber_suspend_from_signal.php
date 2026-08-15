<?php
declare(strict_types=1);

/**
 * S1 — Fiber::suspend() from a pcntl async signal handler.
 *
 * QUESTION
 *   Can a PHP signal handler installed with pcntl_async_signals(true) call
 *   Fiber::suspend() and thereby force the *currently running fiber* back to its
 *   resumer, without the fiber ever reaching a cooperative yield point?
 *   If yes, Go-style time-slice preemption needs no FFI at all.
 *
 * GO-CRITERION (GREEN)
 *   1. Fiber::suspend() inside the signal handler actually suspends the fiber
 *      (no FiberError, no fatal, no crash).
 *   2. The value passed to suspend() is delivered to the resumer.
 *   3. resume() continues the interrupted call-free loop with intact state:
 *      the preempted arithmetic result is bit-identical to the non-preempted one.
 *   4. An alarm that fires while control is in the scheduler (no fiber running)
 *      is survivable: the handler detects it and does nothing.
 *   5. Stable over at least a few hundred preemptions.
 *
 * MECHANISM UNDER TEST
 *   No FFI is used here on purpose. A ~10 ms tick is produced by a forked child
 *   process that posix_kill()s SIGALRM at the parent; that is exactly the same
 *   signal-delivery path a setitimer would use (S3 covers setitimer itself), so
 *   S1 stays a pure pcntl/posix experiment.
 *
 * HOW TO RUN
 *   timeout 30 php8.4 -d ffi.enable=1 -d opcache.jit=off s1_fiber_suspend_from_signal.php
 *   timeout 30 php8.5 -d ffi.enable=1 -d opcache.jit=off s1_fiber_suspend_from_signal.php
 *
 * VERDICT LINE
 *   "VERDICT S1: GREEN|RED|CRASH|HANG — ..."
 */

const WORK_ITERATIONS = 200_000_000;   // ~4 s of call-free arithmetic
const TICK_US         = 10_000;        // 10 ms preemption slice

if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
    fwrite(STDOUT, "VERDICT S1: BLOCKED — pcntl/posix not available\n");
    exit(1);
}

printf("PHP %s | pid %d | ticks every %d us | %d iterations\n",
    PHP_VERSION, getmypid(), TICK_US, WORK_ITERATIONS);

// ---------------------------------------------------------------------------
// Phase 0 — reference value, no preemption at all.
// ---------------------------------------------------------------------------
$t0 = hrtime(true);
$reference = 0;
for ($i = 0; $i < WORK_ITERATIONS; $i++) {
    $reference += $i % 7;
}
$refSecs = (hrtime(true) - $t0) / 1e9;
printf("phase0 reference: value=%d in %.3f s\n", $reference, $refSecs);

// ---------------------------------------------------------------------------
// Signal handler state.
// ---------------------------------------------------------------------------
$state = [
    'suspendCalls'      => 0,   // handler decided to suspend
    'outsideFiber'      => 0,   // alarm arrived with no fiber running
    'handlerEntries'    => 0,   // handler ran at all
    'suspendError'      => null,
    'suspendErrorCount' => 0,
    'armed'             => false,
];

pcntl_async_signals(true);

pcntl_signal(SIGALRM, function (int $signo, mixed $siginfo) use (&$state): void {
    // A throw escaping a PHP signal handler is an ordinary exception, but a
    // throw escaping into engine-internal frames during preemption is not worth
    // the risk: the whole body is defensive.
    try {
        $state['handlerEntries']++;

        if (!$state['armed']) {
            return; // preemption disabled for this phase
        }

        if (Fiber::getCurrent() === null) {
            // Control is in the scheduler (or in plain top-level code).
            // There is nothing to preempt — this MUST be a no-op.
            $state['outsideFiber']++;
            return;
        }

        $state['suspendCalls']++;
        Fiber::suspend('PREEMPT');
    } catch (\Throwable $e) {
        $state['suspendErrorCount']++;
        if ($state['suspendError'] === null) {
            $state['suspendError'] = get_class($e) . ': ' . $e->getMessage();
        }
    }
});

// ---------------------------------------------------------------------------
// Ticker: a forked child that hammers the parent with SIGALRM every TICK_US.
// ---------------------------------------------------------------------------
$parentPid = getmypid();
$tickerPid = pcntl_fork();
if ($tickerPid === -1) {
    fwrite(STDOUT, "VERDICT S1: BLOCKED — pcntl_fork() failed\n");
    exit(1);
}
if ($tickerPid === 0) {
    // child
    pcntl_async_signals(false);
    pcntl_signal(SIGALRM, SIG_IGN);
    $deadline = microtime(true) + 25.0;
    while (microtime(true) < $deadline) {
        usleep(TICK_US);
        if (!posix_kill($parentPid, SIGALRM)) {
            break; // parent gone
        }
    }
    exit(0);
}

$shutdownTicker = static function () use ($tickerPid): void {
    @posix_kill($tickerPid, SIGKILL);
    @pcntl_waitpid($tickerPid, $st, WNOHANG);
};

// ---------------------------------------------------------------------------
// Phase 1 — alarms while NO fiber exists. Must be survivable.
// ---------------------------------------------------------------------------
$state['armed'] = true;
$before = $state['handlerEntries'];
$t0 = hrtime(true);
$spin = 0;
while ((hrtime(true) - $t0) < 500_000_000) { // 0.5 s
    $spin += $spin % 3 + 1;
}
$phase1Entries = $state['handlerEntries'] - $before;
printf("phase1 fiber-less alarms: handlerEntries=%d outsideFiber=%d (survived=%s)\n",
    $phase1Entries, $state['outsideFiber'], 'yes');

// ---------------------------------------------------------------------------
// Phase 2 — the real thing: preempt a call-free loop inside a fiber.
// ---------------------------------------------------------------------------
$fiber = new Fiber(function (): int {
    $x = 0;
    for ($i = 0; $i < WORK_ITERATIONS; $i++) {
        $x += $i % 7;
    }
    return $x;
});

$preemptions       = 0;
$badValues         = 0;
$resumeErrors      = [];
$latencies         = [];
$outsideBeforeWork = $state['outsideFiber'];

$t0   = hrtime(true);
$last = $t0;

try {
    $value = $fiber->start();
    while (!$fiber->isTerminated()) {
        $now = hrtime(true);
        if ($value === 'PREEMPT') {
            $preemptions++;
            $latencies[] = ($now - $last) / 1e6; // ms between scheduler visits
        } else {
            $badValues++;
        }
        $last  = $now;
        $value = $fiber->resume(null);
    }
} catch (\Throwable $e) {
    $resumeErrors[] = get_class($e) . ': ' . $e->getMessage();
}

$workSecs = (hrtime(true) - $t0) / 1e9;
$state['armed'] = false;
$shutdownTicker();

$got = null;
try {
    $got = $fiber->getReturn();
} catch (\Throwable $e) {
    $resumeErrors[] = 'getReturn: ' . get_class($e) . ': ' . $e->getMessage();
}

// ---------------------------------------------------------------------------
// Phase 3 — diagnostics. WHY did phase 2 behave the way it did, and what still
// works? These sub-probes discriminate between "suspending from a userland
// callback is illegal" and "suspending from the VM-interrupt context is illegal",
// and they measure the fallback (handler sets a flag, fiber polls it).
// ---------------------------------------------------------------------------
$diag = [];

// 3a — suspend from a userland closure nested inside an INTERNAL function frame
//      (array_map), i.e. a normal call stack, not the interrupt context.
try {
    $f3a = new Fiber(function (): string {
        array_map(static function (int $v): int {
            Fiber::suspend('FROM-ARRAY_MAP');
            return $v;
        }, [1]);
        return 'completed';
    });
    $v3a = $f3a->start();
    $diag['3a suspend inside array_map callback'] = $v3a === 'FROM-ARRAY_MAP'
        ? 'OK (suspended, value delivered)'
        : 'unexpected value ' . var_export($v3a, true);
    if ($f3a->isSuspended()) {
        $f3a->resume(null);
        $diag['3a resume'] = $f3a->isTerminated() ? 'terminated, return=' . $f3a->getReturn() : 'still suspended';
    }
} catch (\Throwable $e) {
    $diag['3a suspend inside array_map callback'] = 'FAILED ' . get_class($e) . ': ' . $e->getMessage();
}

// 3b — suspend from the SAME signal handler, but dispatched SYNCHRONOUSLY via
//      pcntl_signal_dispatch() from inside the fiber (async signals off, so the
//      handler does not run from the VM-interrupt path).
pcntl_async_signals(false);
$state['armed'] = true;
$errBefore      = $state['suspendErrorCount'];
try {
    posix_kill(getmypid(), SIGALRM); // make one signal pending
    $f3b = new Fiber(function (): string {
        pcntl_signal_dispatch(); // handler runs here and calls Fiber::suspend()
        return 'completed-without-suspending';
    });
    $v3b = $f3b->start();
    if ($v3b === 'PREEMPT') {
        $f3b->resume(null);
        $diag['3b suspend via explicit pcntl_signal_dispatch()'] =
            'OK (suspended, value delivered, resumed to: ' . ($f3b->isTerminated() ? $f3b->getReturn() : '?') . ')';
    } else {
        $diag['3b suspend via explicit pcntl_signal_dispatch()'] =
            'DID NOT SUSPEND (fiber returned ' . var_export($v3b, true) . ', suspendErrors +'
            . ($state['suspendErrorCount'] - $errBefore) . ': ' . ($state['suspendError'] ?? 'n/a') . ')';
    }
} catch (\Throwable $e) {
    $diag['3b suspend via explicit pcntl_signal_dispatch()'] = 'FAILED ' . get_class($e) . ': ' . $e->getMessage();
}

// 3c — fallback path: the async handler only SETS A FLAG; the fiber body polls
//      the flag at an injected cooperative checkpoint and suspends itself.
$flag      = false;
$flagFired = 0;
pcntl_signal(SIGALRM, function () use (&$flag, &$flagFired): void {
    $flag = true;
    $flagFired++;
});
pcntl_async_signals(true);

$tickerPid2 = pcntl_fork();
if ($tickerPid2 === 0) {
    pcntl_async_signals(false);
    pcntl_signal(SIGALRM, SIG_IGN);
    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
        usleep(TICK_US);
        if (!posix_kill($parentPid, SIGALRM)) {
            break;
        }
    }
    exit(0);
}

$CHECK_EVERY = 100_000;             // injected checkpoint granularity
$N3          = 60_000_000;
$f3c         = new Fiber(function () use (&$flag, $CHECK_EVERY, $N3): int {
    $x = 0;
    for ($i = 0; $i < $N3; $i++) {
        $x += $i % 7;
        if (($i % $CHECK_EVERY) === 0 && $flag) {
            $flag = false;
            Fiber::suspend('PREEMPT');
        }
    }
    return $x;
});

$ref3      = 0;
for ($i = 0; $i < $N3; $i++) {
    $ref3 += $i % 7;
}
$c3        = 0;
$lat3      = [];
$t3        = hrtime(true);
$last3     = $t3;
$v3c       = $f3c->start();
while (!$f3c->isTerminated()) {
    $now = hrtime(true);
    if ($v3c === 'PREEMPT') {
        $c3++;
        $lat3[] = ($now - $last3) / 1e6;
    }
    $last3 = $now;
    $v3c   = $f3c->resume(null);
}
$secs3 = (hrtime(true) - $t3) / 1e9;
@posix_kill($tickerPid2, SIGKILL);
@pcntl_waitpid($tickerPid2, $st2, WNOHANG);
sort($lat3);
$n3 = count($lat3);
$diag['3c flag+cooperative-checkpoint fallback'] = sprintf(
    'preemptions=%d in %.3f s, result %s, slice ms mean=%.3f p99=%.3f max=%.3f, signals=%d',
    $c3,
    $secs3,
    $f3c->getReturn() === $ref3 ? 'CORRECT' : 'WRONG',
    $n3 ? array_sum($lat3) / $n3 : 0,
    $n3 ? $lat3[max(0, (int) ($n3 * 0.99) - 1)] : 0,
    $n3 ? $lat3[$n3 - 1] : 0,
    $flagFired
);

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
sort($latencies);
$n    = count($latencies);
$mean = $n ? array_sum($latencies) / $n : 0.0;
$p50  = $n ? $latencies[intdiv($n, 2)] : 0.0;
$p99  = $n ? $latencies[max(0, (int) ($n * 0.99) - 1)] : 0.0;
$max  = $n ? $latencies[$n - 1] : 0.0;

printf("phase2 work: %.3f s (reference %.3f s, overhead %+.1f%%)\n",
    $workSecs, $refSecs, $refSecs > 0 ? ($workSecs / $refSecs - 1) * 100 : 0);
printf("phase2 preemptions=%d badResumeValues=%d\n", $preemptions, $badValues);
printf("phase2 scheduler-visit interval ms: mean=%.3f p50=%.3f p99=%.3f max=%.3f\n",
    $mean, $p50, $p99, $max);
printf("phase2 handler: entries=%d suspendCalls=%d outsideFiber(total)=%d outsideFiber(during work)=%d\n",
    $state['handlerEntries'], $state['suspendCalls'], $state['outsideFiber'],
    $state['outsideFiber'] - $outsideBeforeWork);
printf("phase2 suspendErrors=%d first=%s\n",
    $state['suspendErrorCount'], $state['suspendError'] ?? '(none)');
printf("phase2 result=%s reference=%d stateIntact=%s\n",
    var_export($got, true), $reference, $got === $reference ? 'YES' : 'NO');
if ($resumeErrors) {
    printf("phase2 resumeErrors: %s\n", implode(' | ', $resumeErrors));
}

echo "\n-- phase3 diagnostics --\n";
foreach ($diag as $k => $v) {
    printf("  %-46s : %s\n", $k, $v);
}
echo "\n";

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------
$reasons = [];
if ($state['suspendErrorCount'] > 0) {
    $reasons[] = sprintf('Fiber::suspend() from handler raised %s (%dx)',
        $state['suspendError'], $state['suspendErrorCount']);
}
if ($preemptions === 0) {
    $reasons[] = 'zero preemptions observed';
}
if ($badValues > 0) {
    $reasons[] = sprintf('%d resume values were not the sentinel', $badValues);
}
if ($got !== $reference) {
    $reasons[] = sprintf('state corrupted: got %s expected %d', var_export($got, true), $reference);
}
if ($resumeErrors) {
    $reasons[] = 'scheduler errors: ' . implode(' | ', $resumeErrors);
}
if ($phase1Entries === 0) {
    $reasons[] = 'ticker never delivered a signal in phase 1 (test invalid)';
}
if ($preemptions > 0 && $preemptions < 200) {
    $reasons[] = sprintf('only %d preemptions — below the "few hundred" stability bar', $preemptions);
}

if ($reasons === []) {
    printf("VERDICT S1: GREEN — Fiber::suspend() from a pcntl async signal handler preempts a call-free loop; %d preemptions, mean slice %.2f ms, max %.2f ms, state intact (result==reference), fiber-less alarms harmless (%d no-ops).\n",
        $preemptions, $mean, $max, $state['outsideFiber']);
    exit(0);
}
printf("VERDICT S1: RED — %s\n", implode('; ', $reasons));
exit(1);
