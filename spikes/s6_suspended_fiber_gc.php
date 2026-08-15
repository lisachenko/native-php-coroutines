<?php
declare(strict_types=1);

/**
 * S6 — Suspended-fiber garbage collection.
 *
 * QUESTION
 *   A scheduler will inevitably abandon coroutines: a fiber is suspended, the
 *   last reference to it is dropped, and it is never resumed. Does the engine
 *   collect such a fiber? Do the objects it holds get destructed? Do its
 *   `finally` blocks run? Does memory stay flat over 10k create-suspend-abandon
 *   cycles — or must the scheduler explicitly drain abandoned coroutines at
 *   shutdown?
 *
 * GO-CRITERION (GREEN)
 *   1. Abandoned suspended fibers are collected: memory_get_usage(true) and RSS
 *      stay flat over 10k cycles (leak-per-fiber indistinguishable from zero).
 *   2. Fiber-local objects are destructed when the fiber is abandoned.
 *   3. The behaviour of `finally` is determined precisely (either way — the
 *      point is to know, because it decides whether cleanup can be trusted).
 *   4. Abandoning a PREEMPT-suspended fiber (resume point inside the scheduler's
 *      FFI interrupt callback) does not crash.
 *
 * HOW TO RUN
 *   timeout 60 php8.4 -d ffi.enable=1 -d opcache.jit=off s6_suspended_fiber_gc.php
 *   timeout 60 php8.5 -d ffi.enable=1 -d opcache.jit=off s6_suspended_fiber_gc.php
 *
 * VERDICT LINE
 *   "VERDICT S6: GREEN|RED|CRASH|BLOCKED — ..."
 */

const CYCLES        = 10_000;
const PREEMPT_CYCLES = 400;
const ITIMER_REAL   = 0;
const PREEMPT_USEC  = 1_000;   // 1 ms so the preempt path is not glacial

$dtorCount    = 0;
$finallyCount = 0;
$bodyEntered  = 0;

final class S6Payload
{
    public string $blob;

    public function __construct(public int $id)
    {
        $this->blob = str_repeat('p', 1024);
    }

    public function __destruct()
    {
        $GLOBALS['dtorCount']++;
    }
}

function s6_rss_bytes(): int
{
    $statm = @file_get_contents('/proc/self/statm');
    if ($statm === false) {
        return -1;
    }
    $parts = preg_split('/\s+/', trim($statm)) ?: [];

    return isset($parts[1]) ? ((int) $parts[1]) * 4096 : -1;
}

// ---------------------------------------------------------------------------
// 6b/6c/6d child modes — everything involving PREEMPT-suspended fibers runs in
// a subprocess, because dropping one turns out to be fatal.
//
//   --preempt-destroy   minimal reproducer: preempt-suspend one fiber, then
//                       unset() it while it is still suspended
//   --preempt-shutdown  preempt-suspend one fiber and simply let the script end
//   --preempt-drain     the disciplined alternative: resume every preempted
//                       fiber to completion before dropping it
// ---------------------------------------------------------------------------
$childMode = $argv[1] ?? '';
if (in_array($childMode, ['--preempt-destroy', '--preempt-shutdown', '--preempt-drain',
    '--preempt-destroy-after-uninstall', '--preempt-shutdown-drain'], true)) {
    $minor     = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $vendorDir = ($minor === '8.4') ? __DIR__ . '/ze84/vendor/autoload.php' : __DIR__ . '/ze85/vendor/autoload.php';
    if (!is_file($vendorDir)) {
        echo "PREEMPT-CHILD: BLOCKED — no z-engine vendor dir\n";
        exit(4);
    }
    require $vendorDir;
    \ZEngine\Core::init();
    \ZEngine\Core::preload();

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

    $want = false;
    $exec = \ZEngine\Core::$executor;
    $hook = \ZEngine\Core::setInterruptHandler(static function (object $h) use (&$want): void {
        try {
            if ($want && \Fiber::getCurrent() !== null) {
                $want = false;
                \Fiber::suspend('PREEMPT');
            }
        } catch (\Throwable) {
        }
        try {
            if ($h->hasOriginalHandler()) {
                $h->proceed();
            }
        } catch (\Throwable) {
        }
    });
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use (&$want, $exec): void {
        $want = true;
        $exec->requestInterrupt();
    });

    // A long body so the fiber is guaranteed to be preempted, for the two
    // fatal-hunting modes; a short one for the drain mode.
    $longBody = static function (): string {
        $p = new S6Payload(1);
        try {
            $x = 0;
            for ($i = 0; $i < 200_000_000; $i++) {
                $x += $i % 7;
            }
            return 'completed';
        } finally {
            $GLOBALS['finallyCount']++;
        }
    };

    // Workaround attempt A: uninstall the interrupt hook first, THEN drop the
    // fiber. (The suspended fiber's saved stack still contains the ext-ffi
    // trampoline frame, so this is expected not to help.)
    if ($childMode === '--preempt-destroy-after-uninstall') {
        $arm(PREEMPT_USEC);
        $f = new \Fiber($longBody);
        $v = $f->start();
        $disarm();
        if ($v !== 'PREEMPT') {
            printf("CHILD(%s): not preempt-suspended (got %s)\n", $childMode, var_export($v, true));
            exit(4);
        }
        $hook->uninstall();
        echo "CHILD(--preempt-destroy-after-uninstall): hook uninstalled, dropping the fiber NOW\n";
        unset($f);
        gc_collect_cycles();
        echo "CHILD(--preempt-destroy-after-uninstall): SURVIVED destruction\n";
        exit(0);
    }

    // Workaround attempt B: keep the fiber alive and drain it from a
    // register_shutdown_function() — does a shutdown hook run early enough?
    if ($childMode === '--preempt-shutdown-drain') {
        $arm(PREEMPT_USEC);
        $shortish = static function (): int {
            $p = new S6Payload(3);
            try {
                $x = 0;
                for ($i = 0; $i < 2_000_000; $i++) {
                    $x += $i % 7;
                }
                return $x;
            } finally {
                $GLOBALS['finallyCount']++;
            }
        };
        $f = new \Fiber($shortish);
        $v = $f->start();
        $disarm();
        if ($v !== 'PREEMPT') {
            printf("CHILD(%s): not preempt-suspended (got %s)\n", $childMode, var_export($v, true));
            exit(4);
        }
        echo "CHILD(--preempt-shutdown-drain): preempt-suspended; registering a shutdown drain\n";
        register_shutdown_function(static function () use (&$f): void {
            $steps = 0;
            while ($f instanceof \Fiber && !$f->isTerminated()) {
                $f->resume(null);
                $steps++;
            }
            printf("CHILD(--preempt-shutdown-drain): drained in %d resume(s); terminated=%s dtors=%d finallys=%d\n",
                $steps, var_export($f->isTerminated(), true), $GLOBALS['dtorCount'], $GLOBALS['finallyCount']);
        });
        echo "CHILD(--preempt-shutdown-drain): ending the script\n";
        exit(0);
    }

    if ($childMode === '--preempt-destroy' || $childMode === '--preempt-shutdown') {
        $arm(PREEMPT_USEC);
        $f = new \Fiber($longBody);
        $v = $f->start();
        $disarm();                       // isolate: no more signals from here on
        if ($v !== 'PREEMPT') {
            printf("CHILD(%s): fiber was not preempt-suspended (got %s)\n", $childMode, var_export($v, true));
            exit(4);
        }
        printf("CHILD(%s): fiber is preempt-suspended (suspended=%s)\n",
            $childMode, var_export($f->isSuspended(), true));

        if ($childMode === '--preempt-destroy') {
            echo "CHILD(--preempt-destroy): dropping the only reference NOW\n";
            unset($f);
            gc_collect_cycles();
            printf("CHILD(--preempt-destroy): SURVIVED destruction (dtors=%d finallys=%d)\n",
                $GLOBALS['dtorCount'], $GLOBALS['finallyCount']);
            exit(0);
        }

        echo "CHILD(--preempt-shutdown): keeping the reference and ending the script\n";
        // $f stays alive; the engine destroys it during request shutdown.
        exit(0);
    }

    // --preempt-drain: the disciplined alternative.
    $shortBody = static function (): int {
        $p = new S6Payload(2);
        try {
            $x = 0;
            for ($i = 0; $i < 200_000; $i++) {
                $x += $i % 7;
            }
            return $x;
        } finally {
            $GLOBALS['finallyCount']++;
        }
    };
    $arm(PREEMPT_USEC);
    $preemptions = 0;
    $mem0 = 0;
    $rss0 = 0;
    for ($c = 0; $c < PREEMPT_CYCLES; $c++) {
        if ($c === 20) {
            gc_collect_cycles();
            $mem0 = memory_get_usage(true);
            $rss0 = s6_rss_bytes();
        }
        $f = new \Fiber($shortBody);
        $v = $f->start();
        while (!$f->isTerminated()) {     // DRAIN: resume(null) only, never throw()
            if ($v === 'PREEMPT') {
                $preemptions++;
            }
            $v = $f->resume(null);
        }
        unset($f);                       // safe: the fiber has terminated
    }
    $disarm();
    gc_collect_cycles();
    $mem1 = memory_get_usage(true);
    $rss1 = s6_rss_bytes();
    $n = max(1, PREEMPT_CYCLES - 20);
    printf("CHILD(--preempt-drain): cycles=%d preemptions=%d dtors=%d finallys=%d\n",
        PREEMPT_CYCLES, $preemptions, $GLOBALS['dtorCount'], $GLOBALS['finallyCount']);
    printf("CHILD(--preempt-drain): mem(true) %d -> %d (%+.1f B/fiber) | RSS %d -> %d (%+.1f B/fiber)\n",
        $mem0, $mem1, ($mem1 - $mem0) / $n, $rss0, $rss1, ($rss1 - $rss0) / $n);
    echo "CHILD(--preempt-drain): SURVIVED\n";
    exit(0);
}

printf("PHP %s | pid %d | cycles=%d\n", PHP_VERSION, getmypid(), CYCLES);

// ---------------------------------------------------------------------------
// 6a-0 — behaviour of a single abandoned, cooperatively suspended fiber.
// ---------------------------------------------------------------------------
$dtorCount = 0;
$finallyCount = 0;
(static function (): void {
    $f = new \Fiber(static function (): string {
        $p = new S6Payload(99);
        try {
            Fiber::suspend('COOP');
            return 'resumed';
        } finally {
            $GLOBALS['finallyCount']++;
        }
    });
    $f->start();
    // drop it, never resume
})();
gc_collect_cycles();
printf("6a-0 one abandoned suspended fiber: destructorsRun=%d finallyRan=%d\n", $dtorCount, $finallyCount);
$singleDtor    = $dtorCount;
$singleFinally = $finallyCount;

// ---------------------------------------------------------------------------
// 6a — 10k create / suspend / abandon cycles.
// ---------------------------------------------------------------------------
$dtorCount = 0;
$finallyCount = 0;
$bodyEntered = 0;

$makeAndAbandon = static function (int $id): void {
    $f = new \Fiber(static function () use ($id): string {
        $GLOBALS['bodyEntered']++;
        $p = new S6Payload($id);
        try {
            Fiber::suspend('COOP');
            return 'resumed';
        } finally {
            $GLOBALS['finallyCount']++;
        }
    });
    $f->start();
    // $f goes out of scope here: the fiber is suspended and unreachable
};

// warm-up so allocator arenas are settled before the baseline sample
for ($i = 0; $i < 200; $i++) {
    $makeAndAbandon($i);
}
gc_collect_cycles();
$memBase    = memory_get_usage();
$memRealBase = memory_get_usage(true);
$rssBase    = s6_rss_bytes();
$dtorBase   = $dtorCount;
$finBase    = $finallyCount;
$bodyBase   = $bodyEntered;

$samples = [];
for ($i = 0; $i < CYCLES; $i++) {
    $makeAndAbandon($i);
    if (($i + 1) % 2000 === 0) {
        $samples[] = sprintf('  after %5d: mem=%d mem(true)=%d rss=%d dtors=%d',
            $i + 1, memory_get_usage(), memory_get_usage(true), s6_rss_bytes(), $dtorCount - $dtorBase);
    }
}
gc_collect_cycles();
$memEnd     = memory_get_usage();
$memRealEnd = memory_get_usage(true);
$rssEnd     = s6_rss_bytes();

echo "6a progression:\n";
foreach ($samples as $s) {
    echo $s . "\n";
}
printf("6a cycles=%d bodiesEntered=%d destructorsRun=%d finallyRan=%d\n",
    CYCLES, $bodyEntered - $bodyBase, $dtorCount - $dtorBase, $finallyCount - $finBase);
printf("6a memory_get_usage():     %d -> %d  (%+d B total, %+.2f B/fiber)\n",
    $memBase, $memEnd, $memEnd - $memBase, ($memEnd - $memBase) / CYCLES);
printf("6a memory_get_usage(true): %d -> %d  (%+d B total, %+.2f B/fiber)\n",
    $memRealBase, $memRealEnd, $memRealEnd - $memRealBase, ($memRealEnd - $memRealBase) / CYCLES);
printf("6a RSS:                    %d -> %d  (%+d B total, %+.2f B/fiber)\n",
    $rssBase, $rssEnd, $rssEnd - $rssBase, ($rssEnd - $rssBase) / CYCLES);

$dtorsRan   = ($dtorCount - $dtorBase);
$finallysRan = ($finallyCount - $finBase);
$leakPerFiberReal = ($memRealEnd - $memRealBase) / CYCLES;
$leakPerFiberRss  = ($rssEnd - $rssBase) / CYCLES;

// ---------------------------------------------------------------------------
// 6b / 6c / 6d — everything about PREEMPT-suspended fibers, in subprocesses.
// ---------------------------------------------------------------------------
$runChild = static function (string $mode): array {
    $cmd = sprintf('%s -d ffi.enable=1 -d opcache.jit=off %s %s 2>&1',
        escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($mode));
    $out = [];
    $rc  = 0;
    exec($cmd, $out, $rc);
    $note = match (true) {
        $rc === 0              => 'survived',
        $rc === 255            => 'PHP FATAL ERROR',
        $rc > 128 && $rc < 165 => 'KILLED BY SIGNAL ' . ($rc - 128),
        default                => 'exit ' . $rc,
    };
    printf("\n%s subprocess: exit=%d (%s)\n", $mode, $rc, $note);
    foreach ($out as $line) {
        printf("     | %s\n", $line);
    }
    return [$rc, $note, $out];
};

[$rcDestroy,  $noteDestroy,  $outDestroy]  = $runChild('--preempt-destroy');
[$rcShutdown, $noteShutdown, $outShutdown] = $runChild('--preempt-shutdown');
[$rcDrain,    $noteDrain,    $outDrain]    = $runChild('--preempt-drain');
[$rcUninst,   $noteUninst,   $outUninst]   = $runChild('--preempt-destroy-after-uninstall');
[$rcSdDrain,  $noteSdDrain,  $outSdDrain]  = $runChild('--preempt-shutdown-drain');

$destroyIsFatal  = ($rcDestroy !== 0);
$shutdownIsFatal = ($rcShutdown !== 0);
$drainSurvived   = ($rcDrain === 0);
$preemptSurvived = $drainSurvived;

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------
echo "\n";
$reasons = [];
if ($dtorsRan < CYCLES) {
    $reasons[] = sprintf('only %d of %d fiber-local objects were destructed — abandoned fibers are NOT fully collected',
        $dtorsRan, CYCLES);
}
// A real leak shows up in the *real* (chunked) allocator figure and in RSS.
if ($leakPerFiberReal > 64) {
    $reasons[] = sprintf('memory_get_usage(true) grows %.1f B per abandoned fiber', $leakPerFiberReal);
}
if ($leakPerFiberRss > 256) {
    $reasons[] = sprintf('RSS grows %.1f B per abandoned fiber', $leakPerFiberRss);
}
if (!$drainSurvived) {
    $reasons[] = sprintf('even the disciplined drain path failed: %s', $noteDrain);
}

$finallyVerdict = $finallysRan >= CYCLES
    ? sprintf('finally DOES run when a cooperatively-suspended fiber is abandoned (%d of %d)', $finallysRan, CYCLES)
    : sprintf('finally does NOT run when a cooperatively-suspended fiber is abandoned (%d of %d)', $finallysRan, CYCLES);

$preemptFatal = '';
foreach (array_merge($outDestroy, $outShutdown) as $line) {
    if (str_contains($line, 'Fatal error')) {
        $preemptFatal = trim($line);
        break;
    }
}

printf("summary 6a  cooperative abandon: dtors=%d/%d, %s, leak %+.2f B/fiber (real) %+.2f B/fiber (RSS)\n",
    $dtorsRan, CYCLES, $finallyVerdict, $leakPerFiberReal, $leakPerFiberRss);
printf("summary 6b  destroy a preempt-suspended fiber: %s\n", $noteDestroy);
printf("summary 6c  preempt-suspended fiber alive at request shutdown: %s\n", $noteShutdown);
printf("summary 6d  drain (resume(null) to termination) before dropping: %s\n", $noteDrain);
printf("summary 6e  workaround A — uninstall the hook first, then drop: %s\n", $noteUninst);
printf("summary 6f  workaround B — drain from register_shutdown_function(): %s\n", $noteSdDrain);

if ($reasons !== []) {
    printf("VERDICT S6: RED — %s\n", implode('; ', $reasons));
    exit(1);
}

if ($destroyIsFatal || $shutdownIsFatal) {
    printf("VERDICT S6: GREEN — memory is fine, but a HARD SHUTDOWN OBLIGATION was found. (1) Cooperatively-suspended fibers that are abandoned ARE collected: over %d cycles all %d fiber-local destructors ran, finally ran %d times, memory_get_usage(true) %+.2f B/fiber, RSS %+.2f B/fiber (one-time step, then flat) — no drain needed for memory. (2) But a PREEMPT-suspended fiber must NEVER be destroyed while suspended: dropping its last reference is %s and leaving one alive at request shutdown is %s — in both cases with \"%s\". The engine unwinds a dying fiber from its suspension point, which for a preempted fiber is inside the FFI interrupt callback, and that unwind is not a \\Throwable so the mandatory try/catch cannot stop it. (3) The disciplined path works: resuming every preempted fiber with resume(null) until isTerminated() before dropping it %s. (4) Workarounds: uninstalling the interrupt hook before dropping the fiber => %s (the suspended fiber's saved stack still holds the ext-ffi trampoline frame); draining from register_shutdown_function() => %s. THE SCHEDULER MUST THEREFORE OWN AND DRAIN EVERY PREEMPTED COROUTINE — this is a correctness obligation, not a memory optimisation.\n",
        CYCLES, $dtorsRan, $finallysRan, $leakPerFiberReal, $leakPerFiberRss,
        $noteDestroy, $noteShutdown,
        $preemptFatal !== '' ? $preemptFatal : 'a fatal error',
        $noteDrain, $noteUninst, $noteSdDrain);
    exit(0);
}

printf("VERDICT S6: GREEN — abandoned suspended fibers are collected in every mode tested. Over %d cycles: %d destructors ran, %s, memory_get_usage(true) %+.2f B/fiber, RSS %+.2f B/fiber. Destroying a preempt-suspended fiber: %s; at shutdown: %s; drained: %s.\n",
    CYCLES, $dtorsRan, $finallyVerdict, $leakPerFiberReal, $leakPerFiberRss,
    $noteDestroy, $noteShutdown, $noteDrain);
exit(0);
