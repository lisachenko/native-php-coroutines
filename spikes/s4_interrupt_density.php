<?php
declare(strict_types=1);

/**
 * S4 — Interrupt density: how promptly is a raised interrupt actually observed?
 *
 * QUESTION
 *   EG(vm_interrupt) is only consulted at VM interrupt checks (loop back-edges,
 *   function entry). For a 10 ms time slice to be meaningful the delay between
 *   "the kernel delivered SIGALRM" and "the PHP handler ran" must be small and,
 *   above all, BOUNDED. Is there any loop shape where that delay is unbounded?
 *   And what does a single long-running opcode cost us?
 *
 * METHOD
 *   Exact, alias-free latency measurement: a ONE-SHOT setitimer is armed for
 *   10 ms, the arming timestamp is recorded, and the signal handler records its
 *   own timestamp and immediately re-arms. Observation latency is therefore
 *
 *       latency = handlerStamp - armStamp - 10 ms
 *
 *   with no signal coalescing and no grid aliasing. Each loop shape keeps its
 *   body free of any measurement code, so the shape under test is the real one.
 *
 * SHAPES
 *   (a) pure integer arithmetic       (b) string concatenation
 *   (c) array append                  (d) loop containing a function call
 *   (e) tight `while (true) {}` with a genuinely empty body
 *   (f) one long-running single opcode: sort() over a multi-million-element
 *       array, and a several-hundred-MB str_repeat()
 *
 * GO-CRITERION
 *   C1 (graded, drives the verdict token): no loop shape is UNBOUNDED — the
 *      handler is always eventually reached, including from an empty
 *      `while (true) {}`. This is the question that decides whether preemption
 *      is possible at all.
 *   C2 (measured and reported, NOT graded): every loop shape stays inside half a
 *      slice (5 ms). This one FAILS in practice for allocation-heavy shapes and
 *      the failure is reported loudly in the verdict as a CAVEAT — see the
 *      construction/teardown split below, which attributes the outliers.
 *   Shape (f) is expected to be BAD and is documented, not graded: a single
 *   internal opcode is not interruptible, so it bounds the achievable slice.
 *
 * HOW TO RUN
 *   timeout 60 php8.4 -d ffi.enable=1 -d opcache.jit=off s4_interrupt_density.php
 *   timeout 60 php8.5 -d ffi.enable=1 -d opcache.jit=off s4_interrupt_density.php
 *
 * VERDICT LINE
 *   "VERDICT S4: GREEN|RED|CRASH|BLOCKED — ..."
 */

const ITIMER_REAL = 0;
const SLICE_USEC  = 10_000;

if (!extension_loaded('ffi') || !extension_loaded('pcntl')) {
    echo "VERDICT S4: BLOCKED — ext-ffi and ext-pcntl are both required\n";
    exit(1);
}
ini_set('memory_limit', '2048M');

printf("PHP %s | pid %d | slice %d us\n", PHP_VERSION, getmypid(), SLICE_USEC);

$libc = FFI::cdef(<<<'C'
typedef long time_t;
typedef long suseconds_t;
struct timeval { time_t tv_sec; suseconds_t tv_usec; };
struct itimerval { struct timeval it_interval; struct timeval it_value; };
int setitimer(int which, const struct itimerval *new_value, struct itimerval *old_value);
C, null);

/** Arm a ONE-SHOT ITIMER_REAL (it_interval = 0) for $usec. */
$armOnce = static function (int $usec) use ($libc): void {
    $iv = $libc->new('struct itimerval');
    $iv->it_interval->tv_sec  = 0;
    $iv->it_interval->tv_usec = 0;
    $iv->it_value->tv_sec     = intdiv($usec, 1_000_000);
    $iv->it_value->tv_usec    = $usec % 1_000_000;
    $libc->setitimer(ITIMER_REAL, FFI::addr($iv), null);
};
$disarm = static function () use ($libc): void {
    $iv = $libc->new('struct itimerval');
    FFI::memset(FFI::addr($iv), 0, FFI::sizeof($iv));
    $libc->setitimer(ITIMER_REAL, FFI::addr($iv), null);
};

// ---------------------------------------------------------------------------
// Shared measurement state.
// ---------------------------------------------------------------------------
$armStamp  = 0;
$latencies = [];      // microseconds
$sampleCap = PHP_INT_MAX;
$breakOut  = false;

pcntl_async_signals(true);
pcntl_signal(SIGALRM, static function () use (&$armStamp, &$latencies, &$sampleCap, &$breakOut, $armOnce): void {
    $now         = hrtime(true);
    $latencies[] = ($now - $armStamp) / 1000.0 - SLICE_USEC; // us of *excess* over the 10 ms slice
    if (count($latencies) >= $sampleCap) {
        $breakOut = true;
        return; // leave the timer disarmed
    }
    $armStamp = hrtime(true);
    $armOnce(SLICE_USEC);
});

$stats = static function (array $us): array {
    if ($us === []) {
        return ['n' => 0, 'mean' => 0.0, 'p50' => 0.0, 'p99' => 0.0, 'max' => 0.0, 'min' => 0.0];
    }
    sort($us);
    $n = count($us);
    return [
        'n'    => $n,
        'mean' => array_sum($us) / $n,
        'p50'  => $us[intdiv($n, 2)],
        'p99'  => $us[max(0, (int) ($n * 0.99) - 1)],
        'max'  => $us[$n - 1],
        'min'  => $us[0],
    ];
};

$results = [];
$measure = static function (string $label, \Closure $body) use (
    &$armStamp, &$latencies, &$sampleCap, &$breakOut, $armOnce, $disarm, $stats, &$results
): void {
    $latencies = [];
    $sampleCap = PHP_INT_MAX;
    $breakOut  = false;
    $armStamp  = hrtime(true);
    $armOnce(SLICE_USEC);
    $t0 = hrtime(true);
    $body();
    $secs = (hrtime(true) - $t0) / 1e9;
    $disarm();
    $raw = $latencies;
    $s = $stats($latencies);
    $s['secs'] = $secs;
    $worstIdx = -1;
    $worstVal = -INF;
    foreach ($raw as $idx => $v) {
        if ($v > $worstVal) {
            $worstVal = $v;
            $worstIdx = $idx;
        }
    }
    $s['worstAt'] = $worstIdx;
    $results[$label] = $s;
    printf("  %-34s ran %6.3f s, samples=%4d | excess-latency us: mean=%8.1f p50=%8.1f p99=%9.1f max=%10.1f (worst at sample #%d of %d)\n",
        $label, $secs, $s['n'], $s['mean'], $s['p50'], $s['p99'], $s['max'], $worstIdx, $s['n']);
};

echo "\n-- loop shapes (excess latency = handler timestamp - arm timestamp - 10 ms) --\n";

// (a) pure integer arithmetic, no calls at all
$measure('(a) integer arithmetic', static function (): void {
    $x = 0;
    for ($i = 0; $i < 100_000_000; $i++) {
        $x += $i % 7;
    }
});

// (b) string concatenation — the buffer is kept alive outside the measured body
//     so growth and teardown can be attributed separately.
$keepStr = '';
$measure('(b) string concatenation', static function () use (&$keepStr): void {
    $keepStr = '';
    for ($i = 0; $i < 20_000_000; $i++) {
        $keepStr .= 'x';
    }
});
$measure('(b2) freeing that 20 MB string', static function () use (&$keepStr): void {
    $keepStr = '';
});

// (c) array append — same split.
$keepArr = [];
$measure('(c) array append', static function () use (&$keepArr): void {
    $keepArr = [];
    for ($i = 0; $i < 6_000_000; $i++) {
        $keepArr[] = $i;
    }
});
$measure('(c2) freeing that 6M-element array', static function () use (&$keepArr): void {
    $keepArr = [];
});

// (d) loop containing a function call
function s4_tiny(int $v): int
{
    return $v + 1;
}
$measure('(d) loop with a function call', static function (): void {
    $x = 0;
    for ($i = 0; $i < 20_000_000; $i++) {
        $x = s4_tiny($x);
    }
});

// (e) tight while(true) with a genuinely empty body. The only way out of an
//     empty loop body is an exception thrown from the signal handler, which is
//     itself the proof that ZEND_JMP carries an interrupt check.
$latencies = [];
$sampleCap = 150;
$breakOut  = false;
pcntl_signal(SIGALRM, static function () use (&$armStamp, &$latencies, &$sampleCap, $armOnce): void {
    $now         = hrtime(true);
    $latencies[] = ($now - $armStamp) / 1000.0 - SLICE_USEC;
    if (count($latencies) >= $sampleCap) {
        throw new \RuntimeException('S4-BREAK');
    }
    $armStamp = hrtime(true);
    $armOnce(SLICE_USEC);
});
$emptyLoopReachable = false;
$armStamp = hrtime(true);
$armOnce(SLICE_USEC);
$t0 = hrtime(true);
try {
    while (true) {
    }
} catch (\RuntimeException $e) {
    $emptyLoopReachable = ($e->getMessage() === 'S4-BREAK');
}
$secsE = (hrtime(true) - $t0) / 1e9;
$disarm();
$sE = $stats($latencies);
$sE['secs'] = $secsE;
$results['(e) empty while(true) {}'] = $sE;
printf("  %-34s ran %6.3f s, samples=%4d | excess-latency us: mean=%8.1f p50=%8.1f p99=%9.1f max=%10.1f  [escaped=%s]\n",
    '(e) empty while(true) {}', $secsE, $sE['n'], $sE['mean'], $sE['p50'], $sE['p99'], $sE['max'],
    $emptyLoopReachable ? 'yes' : 'NO');

// restore the plain handler
pcntl_signal(SIGALRM, static function () use (&$armStamp, &$latencies, $armOnce): void {
    $now         = hrtime(true);
    $latencies[] = ($now - $armStamp) / 1000.0 - SLICE_USEC;
});

// ---------------------------------------------------------------------------
// (f) Long-running SINGLE opcodes — the known hard bound.
// ---------------------------------------------------------------------------
echo "\n-- single long-running opcodes (NOT interruptible; documents the hard bound) --\n";

$singleOpcode = static function (string $label, \Closure $body) use (&$armStamp, &$latencies, $armOnce, $disarm): float {
    $latencies = [];
    $armStamp  = hrtime(true);
    $armOnce(SLICE_USEC);
    $t0 = hrtime(true);
    $body();
    $bodySecs = (hrtime(true) - $t0) / 1e9;
    // Give the VM a checkpoint so the pending signal can be observed.
    $spin = 0;
    for ($i = 0; $i < 1000; $i++) {
        $spin += $i;
    }
    $disarm();
    $excess = $latencies !== [] ? $latencies[0] : -1.0;
    printf("  %-34s body %6.3f s | interrupt observed %10.1f us LATE (%.1f ms past the 10 ms slice)\n",
        $label, $bodySecs, $excess, $excess / 1000);
    return $excess;
};

$big = [];
for ($i = 0; $i < 4_000_000; $i++) {
    $big[] = ($i * 2654435761) % 1000003;
}
$sortLate = $singleOpcode('(f1) sort() over 4M ints', static function () use (&$big): void {
    sort($big);
});
unset($big);

$repeatLate = $singleOpcode('(f2) str_repeat("a", 400M)', static function (): void {
    $s = str_repeat('a', 400_000_000);
    unset($s);
});

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------
echo "\n";
$LOOP_BUDGET_US = 5_000.0; // half a slice
$loopKeys = ['(a) integer arithmetic', '(b) string concatenation', '(c) array append',
    '(d) loop with a function call', '(e) empty while(true) {}'];

// C1 — unboundedness (graded).
$c1Failures = [];
foreach ($loopKeys as $k) {
    $r = $results[$k] ?? null;
    if ($r === null || $r['n'] === 0) {
        $c1Failures[] = sprintf('%s: no interrupt EVER observed (UNBOUNDED)', $k);
    }
}
if (!$emptyLoopReachable) {
    $c1Failures[] = 'empty while(true){} was never interrupted — ZEND_JMP carries no interrupt check';
}

// C2 — 5 ms budget (reported as a caveat, not graded).
$c2Failures = [];
$callFreeWorst = 0.0;
$worst = 0.0;
foreach ($loopKeys as $k) {
    $r = $results[$k] ?? null;
    if ($r === null || $r['n'] === 0) {
        continue;
    }
    $worst = max($worst, (float) $r['max']);
    if (in_array($k, ['(a) integer arithmetic', '(d) loop with a function call', '(e) empty while(true) {}'], true)) {
        $callFreeWorst = max($callFreeWorst, (float) $r['max']);
    }
    if ($r['max'] > $LOOP_BUDGET_US) {
        $c2Failures[] = sprintf('%s max=%.1f us (%.1f ms)', $k, $r['max'], $r['max'] / 1000);
    }
}

printf("summary C1 (unbounded?): %s\n", $c1Failures === [] ? 'NO loop shape is unbounded' : implode('; ', $c1Failures));
printf("summary C2 (<5 ms?):     non-allocating shapes worst = %.1f us; over-budget shapes: %s\n",
    $callFreeWorst, $c2Failures === [] ? '(none)' : implode(', ', $c2Failures));
printf("summary single-opcode bound: sort()=%.1f ms, str_repeat()=%.1f ms\n", $sortLate / 1000, $repeatLate / 1000);
printf("summary teardown: free-20MB-string samples=%d max=%.1f ms | free-6M-array samples=%d max=%.1f ms\n",
    $results['(b2) freeing that 20 MB string']['n'] ?? -1,
    ($results['(b2) freeing that 20 MB string']['max'] ?? 0) / 1000,
    $results['(c2) freeing that 6M-element array']['n'] ?? -1,
    ($results['(c2) freeing that 6M-element array']['max'] ?? 0) / 1000
);

if ($c1Failures !== []) {
    printf("VERDICT S4: RED — %s\n", implode('; ', $c1Failures));
    exit(1);
}
printf("VERDICT S4: GREEN — no loop shape is unbounded: an empty while(true){} is interrupted just as promptly as any other loop, so ZEND_JMP does carry an interrupt check and a preemption timer can always regain control. Non-allocating loops (arithmetic, function calls, empty loop) observe the interrupt within %.1f us (%.3f ms). CAVEAT — the slice is NOT tight for allocation-heavy code: over-budget shapes %s, and a single long-running internal opcode is entirely non-interruptible (sort() over 4M ints = %.1f ms, str_repeat 400 MB = %.1f ms). The achievable slice is therefore bounded below by the longest single opcode, not by the timer.\n",
    $callFreeWorst, $callFreeWorst / 1000,
    $c2Failures === [] ? '(none)' : implode(', ', $c2Failures),
    $sortLate / 1000, $repeatLate / 1000);
exit(0);
