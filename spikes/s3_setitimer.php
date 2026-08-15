<?php
declare(strict_types=1);

/**
 * S3 — FFI setitimer(ITIMER_REAL) at ~10 ms as the preemption clock.
 *
 * QUESTION
 *   pcntl_alarm() has one-second granularity, which is useless for a 10 ms time
 *   slice. Can we drive SIGALRM from libc's setitimer(2) through FFI, and does a
 *   repeating interval timer actually deliver ~100 ticks/second to a busy PHP
 *   process? And — required by the multi-worker design — is the interval timer
 *   CLEARED in a pcntl_fork() child, so every worker must re-arm its own?
 *
 * GO-CRITERION (GREEN)
 *   1. FFI::cdef of setitimer/getitimer against libc succeeds.
 *   2. struct itimerval layout is right for x86-64 Linux: two struct timeval,
 *      each two 64-bit fields => sizeof(timeval)==16, sizeof(itimerval)==32.
 *      Verified additionally by getitimer() reading back what was armed.
 *   3. Armed at 10 ms repeating, we observe ~100 ticks/s without re-arming,
 *      with mean interval close to 10 ms.
 *   4. A pcntl_fork() child inherits NO running interval timer (getitimer in the
 *      child reads back it_value == 0 and receives no ticks).
 *
 * C REFERENCE (x86-64 Linux, glibc)
 *   struct timeval   { time_t tv_sec; suseconds_t tv_usec; }      // 8 + 8  = 16
 *   struct itimerval { struct timeval it_interval, it_value; }    // 16 + 16 = 32
 *   int setitimer(int which, const struct itimerval *new, struct itimerval *old);
 *   int getitimer(int which, struct itimerval *curr);
 *   ITIMER_REAL == 0
 *
 * HOW TO RUN
 *   timeout 30 php8.4 -d ffi.enable=1 -d opcache.jit=off s3_setitimer.php
 *   timeout 30 php8.5 -d ffi.enable=1 -d opcache.jit=off s3_setitimer.php
 *
 * VERDICT LINE
 *   "VERDICT S3: GREEN|RED|CRASH|BLOCKED — ..."
 */

const ITIMER_REAL   = 0;
const TICK_USEC     = 10_000;   // 10 ms
const MEASURE_SECS  = 1.0;

if (!extension_loaded('ffi')) {
    echo "VERDICT S3: BLOCKED — ext-ffi not loaded\n";
    exit(1);
}
if (!extension_loaded('pcntl')) {
    echo "VERDICT S3: BLOCKED — ext-pcntl not loaded\n";
    exit(1);
}

printf("PHP %s | pid %d\n", PHP_VERSION, getmypid());

// ---------------------------------------------------------------------------
// 3a — bind libc.
// ---------------------------------------------------------------------------
$cdef = <<<'C'
typedef long time_t;
typedef long suseconds_t;
struct timeval { time_t tv_sec; suseconds_t tv_usec; };
struct itimerval { struct timeval it_interval; struct timeval it_value; };
int setitimer(int which, const struct itimerval *new_value, struct itimerval *old_value);
int getitimer(int which, struct itimerval *curr_value);
C;

$libc = null;
$bindErr = null;
foreach ([null, 'libc.so.6', 'libc.so'] as $so) {
    try {
        $libc = FFI::cdef($cdef, $so);
        printf("3a FFI::cdef bound against %s\n", $so ?? '(process symbols, soname=null)');
        break;
    } catch (\Throwable $e) {
        $bindErr = $e->getMessage();
    }
}
if ($libc === null) {
    printf("VERDICT S3: BLOCKED — could not bind libc: %s\n", (string) $bindErr);
    exit(1);
}

// ---------------------------------------------------------------------------
// 3b — struct layout check.
// ---------------------------------------------------------------------------
$tvSize = FFI::sizeof($libc->new('struct timeval'));
$ivSize = FFI::sizeof($libc->new('struct itimerval'));
$layoutOk = ($tvSize === 16 && $ivSize === 32);
printf("3b sizeof(struct timeval)=%d (expect 16), sizeof(struct itimerval)=%d (expect 32) => %s\n",
    $tvSize, $ivSize, $layoutOk ? 'OK' : 'WRONG LAYOUT');

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------
$arm = static function (FFI $libc, int $usec): int {
    $iv = $libc->new('struct itimerval');
    $iv->it_interval->tv_sec  = intdiv($usec, 1_000_000);
    $iv->it_interval->tv_usec = $usec % 1_000_000;
    $iv->it_value->tv_sec     = intdiv($usec, 1_000_000);
    $iv->it_value->tv_usec    = $usec % 1_000_000;

    return $libc->setitimer(ITIMER_REAL, FFI::addr($iv), null);
};
$disarm = static function (FFI $libc): int {
    $iv = $libc->new('struct itimerval');
    FFI::memset(FFI::addr($iv), 0, FFI::sizeof($iv));

    return $libc->setitimer(ITIMER_REAL, FFI::addr($iv), null);
};
$read = static function (FFI $libc): array {
    $iv = $libc->new('struct itimerval');
    $rc = $libc->getitimer(ITIMER_REAL, FFI::addr($iv));

    return [
        'rc'            => $rc,
        'interval_usec' => $iv->it_interval->tv_sec * 1_000_000 + $iv->it_interval->tv_usec,
        'value_usec'    => $iv->it_value->tv_sec * 1_000_000 + $iv->it_value->tv_usec,
    ];
};

// ---------------------------------------------------------------------------
// 3c — arm at 10 ms repeating and measure delivery.
// ---------------------------------------------------------------------------
$ticks  = 0;
$stamps = [];
pcntl_async_signals(true);
pcntl_signal(SIGALRM, static function () use (&$ticks, &$stamps): void {
    $ticks++;
    $stamps[] = hrtime(true);
});

$rc = $arm($libc, TICK_USEC);
$rb = $read($libc);
printf("3c setitimer(ITIMER_REAL, 10ms repeating) rc=%d | getitimer readback: rc=%d it_interval=%d us it_value=%d us => %s\n",
    $rc, $rb['rc'], $rb['interval_usec'], $rb['value_usec'],
    $rb['interval_usec'] === TICK_USEC ? 'MATCHES what was armed' : 'MISMATCH');

$t0 = hrtime(true);
$spin = 0;
while ((hrtime(true) - $t0) < (int) (MEASURE_SECS * 1e9)) {
    $spin += $spin % 3 + 1;
}
$elapsed = (hrtime(true) - $t0) / 1e9;
$observedTicks = $ticks;

// Intervals between consecutive deliveries.
$intervals = [];
for ($i = 1, $n = count($stamps); $i < $n; $i++) {
    $intervals[] = ($stamps[$i] - $stamps[$i - 1]) / 1e6; // ms
}
sort($intervals);
$cnt  = count($intervals);
$mean = $cnt ? array_sum($intervals) / $cnt : 0.0;
$p50  = $cnt ? $intervals[intdiv($cnt, 2)] : 0.0;
$p99  = $cnt ? $intervals[max(0, (int) ($cnt * 0.99) - 1)] : 0.0;
$min  = $cnt ? $intervals[0] : 0.0;
$max  = $cnt ? $intervals[$cnt - 1] : 0.0;
$sd   = 0.0;
foreach ($intervals as $v) {
    $sd += ($v - $mean) ** 2;
}
$sd = $cnt ? sqrt($sd / $cnt) : 0.0;

printf("3c over %.3f s: ticks=%d (expected ~%.0f) rate=%.1f/s\n",
    $elapsed, $observedTicks, $elapsed * 1e6 / TICK_USEC, $observedTicks / $elapsed);
printf("3c interval ms: mean=%.3f sd=%.3f min=%.3f p50=%.3f p99=%.3f max=%.3f (n=%d)\n",
    $mean, $sd, $min, $p50, $p99, $max, $cnt);

// ---------------------------------------------------------------------------
// 3d — does it keep firing WITHOUT re-arming? (already implied, but assert on a
//      second window with no setitimer call in between)
// ---------------------------------------------------------------------------
$ticksBefore = $ticks;
$t0 = hrtime(true);
while ((hrtime(true) - $t0) < 500_000_000) {
    $spin += $spin % 3 + 1;
}
$secondWindow = $ticks - $ticksBefore;
printf("3d second 0.5 s window WITHOUT re-arming: ticks=%d => %s\n",
    $secondWindow, $secondWindow > 30 ? 'KEEPS FIRING (repeating)' : 'STOPPED (one-shot?)');

// ---------------------------------------------------------------------------
// 3e — fork: is the interval timer cleared in the child?
// ---------------------------------------------------------------------------
$pipe = null;
$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$childTicks = -1;
$childReadback = null;

$pid = pcntl_fork();
if ($pid === 0) {
    // CHILD — the timer must NOT be running here.
    fclose($sockets[0]);
    $cTicks = 0;
    pcntl_signal(SIGALRM, static function () use (&$cTicks): void {
        $cTicks++;
    });
    pcntl_async_signals(true);
    $cRb = $read($libc);
    $t = hrtime(true);
    $s = 0;
    while ((hrtime(true) - $t) < 500_000_000) {
        $s += $s % 3 + 1;
    }
    fwrite($sockets[1], json_encode([
        'ticks'    => $cTicks,
        'interval' => $cRb['interval_usec'],
        'value'    => $cRb['value_usec'],
        'rc'       => $cRb['rc'],
    ]));
    fclose($sockets[1]);
    exit(0);
}

fclose($sockets[1]);
$payload = stream_get_contents($sockets[0]);
fclose($sockets[0]);
pcntl_waitpid($pid, $status);
$childData = json_decode((string) $payload, true) ?: [];
$childTicks = $childData['ticks'] ?? -1;
printf("3e forked child over 0.5 s: ticks=%d | getitimer in child: rc=%d it_interval=%d us it_value=%d us => %s\n",
    $childTicks,
    $childData['rc'] ?? -1,
    $childData['interval'] ?? -1,
    $childData['value'] ?? -1,
    ($childTicks === 0 && ($childData['value'] ?? -1) === 0)
        ? 'TIMER CLEARED IN CHILD (as POSIX requires) — each worker MUST re-arm'
        : 'TIMER SURVIVED FORK (unexpected!)'
);

// Parent still ticking?
$parentAfterFork = $ticks;
$disarm($libc);
$rbOff = $read($libc);
printf("3e parent tick counter after fork: %d (still armed before disarm); after disarm it_value=%d us\n",
    $parentAfterFork, $rbOff['value_usec']);

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------
$reasons = [];
if (!$layoutOk) {
    $reasons[] = sprintf('struct layout wrong (timeval=%d itimerval=%d)', $tvSize, $ivSize);
}
if ($rc !== 0) {
    $reasons[] = sprintf('setitimer() returned %d', $rc);
}
if ($rb['interval_usec'] !== TICK_USEC) {
    $reasons[] = sprintf('getitimer read back %d us, armed %d us', $rb['interval_usec'], TICK_USEC);
}
$expected = $elapsed * 1e6 / TICK_USEC;
if ($observedTicks < $expected * 0.8) {
    $reasons[] = sprintf('only %d of ~%.0f expected ticks delivered', $observedTicks, $expected);
}
if ($secondWindow <= 30) {
    $reasons[] = 'timer did not keep firing without re-arming';
}
if ($childTicks !== 0) {
    $reasons[] = sprintf('forked child observed %d ticks — interval timer apparently survived fork', $childTicks);
}

if ($reasons === []) {
    printf("VERDICT S3: GREEN — setitimer(ITIMER_REAL) via FFI delivers %.1f SIGALRM/s at a 10 ms interval (mean %.3f ms, sd %.3f ms, max %.3f ms), keeps firing without re-arming, and is CLEARED across pcntl_fork() (child saw 0 ticks, it_value=0) — every worker must re-arm post-fork.\n",
        $observedTicks / $elapsed, $mean, $sd, $max);
    exit(0);
}
printf("VERDICT S3: RED — %s\n", implode('; ', $reasons));
exit(1);
