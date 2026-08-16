<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

/**
 * Preemption soak: does a long, busy, preemptive run stay correct and flat?
 *
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-preemption.php
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-preemption.php --self-test
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-preemption.php --inject-leak
 *
 * Preemption is the one part of this runtime that suspends a fiber from *inside an FFI callback*,
 * on a signal the program never asked for, at an instruction the coroutine did not choose. Every
 * test in the suite exercises that for a second or two. The failures it is prone to are the ones a
 * second or two cannot show:
 *
 * - **a lost or duplicated partial result** — a resume that returns to the wrong place shows up as
 *   arithmetic that is subtly wrong, not as a crash. So the burners here are call-free arithmetic
 *   loops whose results are compared, every round, against the same computation run uninterrupted;
 * - **a fiber that is preempted and never drained** — the scheduler holds a strong reference to
 *   every preempted coroutine, and one that is dropped is a fatal error at request shutdown, which
 *   is why the run counts what it spawned against what finished and drains the callback at the end;
 * - **a slow climb** — one wait node, timer entry or coroutine retained per slice, invisible until
 *   an hour in. Same two metrics the other soaks watch, same warmup-then-trend verdict.
 *
 * The workload is a mix on purpose: call-free burners (the hard case for preemption — nothing in
 * them ever reaches a cooperative suspension point), parkers on a rendezvous channel and on the
 * timer heap, and real IO ping-ponging through the poller over socket pairs. A runnable coroutine
 * keeps the scheduler out of its idle turn, so the IO wakeups land at the tail of each round rather
 * than interleaved with the burning — that is the documented scheduler property, not a defect, and
 * it still puts every descriptor through `stream_select()` under a live slice timer.
 *
 * # Why the verdict is a trend and not a threshold
 *
 * Identical to `soak-memory-flatness.php`, deliberately: a warmup prefix is dropped, and a metric
 * fails either by climbing in *every* measured round or by ending more than `--tolerance` bytes
 * above where it started.
 *
 * `--self-test` corrupts one burner's sum by one, which must FAIL the arithmetic check.
 * `--inject-leak` retains a block per round, which must FAIL the memory check. A detector nobody
 * has seen fail is a detector nobody knows works.
 *
 * Exit codes: 0 correct and flat, 1 a check failed, 2 the soak could not run (or never preempted,
 * which measures nothing).
 */

namespace Lisachenko\NativePhpCoroutines\Tools\Preemption;

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Preemption\Preemptor;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return array{
 *     seconds: float, burners: int, parkers: int, ioPairs: int, iterations: int, warmup: int,
 *     tolerance: int, slice: float, injectLeak: bool, selfTest: bool,
 * }
 */
function options(): array
{
    $parsed = getopt('', [
        'seconds:',
        'burners:',
        'parkers:',
        'io-pairs:',
        'iterations:',
        'warmup:',
        'tolerance:',
        'slice:',
        'inject-leak',
        'self-test',
        'help',
    ]) ?: [];

    if (array_key_exists('help', $parsed)) {
        echo 'usage: soak-preemption.php [--seconds=30] [--burners=8] [--parkers=8] [--io-pairs=4] ',
        '[--iterations=3000000] [--warmup=<a quarter of the rounds>] [--tolerance=1048576] ',
        '[--slice=0.01] [--inject-leak] [--self-test]', PHP_EOL;

        exit(0);
    }

    return [
        'seconds' => max(1.0, (float) ($parsed['seconds'] ?? 30)),
        'burners' => max(1, (int) ($parsed['burners'] ?? 8)),
        'parkers' => max(0, (int) ($parsed['parkers'] ?? 8)),
        'ioPairs' => max(0, (int) ($parsed['io-pairs'] ?? 4)),
        // Sized so a round is a few hundred milliseconds: long enough that every burner is sliced
        // several times, short enough that a 30 s run is a readable number of rounds.
        'iterations' => max(1, (int) ($parsed['iterations'] ?? 3_000_000)),
        // Resolved against the round count once the run is over: rounds are timed, not counted up
        // front. -1 means "a quarter of them".
        'warmup'     => isset($parsed['warmup']) ? max(0, (int) $parsed['warmup']) : -1,
        'tolerance'  => max(0, (int) ($parsed['tolerance'] ?? 1024 * 1024)),
        'slice'      => max(0.001, (float) ($parsed['slice'] ?? Preemptor::DEFAULT_SLICE_SECONDS)),
        'injectLeak' => array_key_exists('inject-leak', $parsed),
        'selfTest'   => array_key_exists('self-test', $parsed),
    ];
}

/**
 * The burner: two interdependent accumulators, no call of any kind inside the loop.
 *
 * Both the reference and the coroutine run *this* function, so the comparison cannot drift apart
 * the day one of them is edited. Being call-free is the point — such a loop never reaches a
 * cooperative suspension point, so the only thing that can interrupt it is the slice timer, and the
 * only thing that can corrupt the answer is preemption resuming it wrongly.
 *
 * @return array{int, int} The sum and the rolling hash.
 */
function burn(int $seed, int $iterations): array
{
    $sum     = 0;
    $rolling = 1;

    for ($index = 0; $index < $iterations; $index++) {
        $sum += ($index + $seed)                    % 7;
        $rolling = ($rolling * 31 + $index + $seed) % 1_000_003;
    }

    return [$sum, $rolling];
}

/**
 * Whether the run got as far as announcing its own verdict.
 *
 * A flag rather than a bare boolean because the shutdown guard has to read it *after* the run has
 * set it, and an object property carries that across without a by-reference capture.
 */
final class Verdict
{
    public bool $reached = false;
}

/** Resident set size in bytes, or null where `/proc/self/statm` is not readable. */
function residentSetSize(): ?int
{
    $statm = @file_get_contents('/proc/self/statm');
    if (!is_string($statm)) {
        return null;
    }

    $fields = preg_split('/\s+/', trim($statm)) ?: [];
    if (!isset($fields[1])) {
        return null;
    }

    $pageSize = 4096;
    if (function_exists('posix_sysconf') && defined('POSIX_SC_PAGESIZE')) {
        $reported = posix_sysconf(POSIX_SC_PAGESIZE);
        if ($reported > 0) {
            $pageSize = $reported;
        }
    }

    return ((int) $fields[1]) * $pageSize;
}

/**
 * The verdict for one metric, in the shape the other soaks report.
 *
 * @param list<int> $samples The measured rounds only; warmup is already dropped.
 * @return array{ok: bool, reason: string}
 */
function trendVerdict(array $samples, int $tolerance): array
{
    $first = $samples[0];
    $last  = $samples[count($samples) - 1];
    $net   = $last - $first;

    $monotonic = true;
    for ($index = 1; $index < count($samples); ++$index) {
        if ($samples[$index] <= $samples[$index - 1]) {
            $monotonic = false;

            break;
        }
    }

    if ($monotonic) {
        return ['ok' => false, 'reason' => sprintf('climbs in every measured round (+%s net)', bytes($net))];
    }

    if ($net > $tolerance) {
        return [
            'ok'     => false,
            'reason' => sprintf('grew %s over the run, above the %s tolerance', bytes($net), bytes($tolerance)),
        ];
    }

    return ['ok' => true, 'reason' => sprintf('net %s%s over the run', $net >= 0 ? '+' : '-', bytes(abs($net)))];
}

function bytes(int $value): string
{
    return sprintf('%.1f KiB', $value / 1024);
}

$options = options();

if (!function_exists('pcntl_signal')) {
    echo 'SOAK preemption: INCONCLUSIVE — ext-pcntl is required to receive the slice timer\'s SIGALRM', PHP_EOL;

    exit(2);
}

/**
 * The no-fatal check, and it has to be a shutdown function to be one.
 *
 * A preempt-suspended fiber that reaches request shutdown still parked inside the interrupt
 * callback is `Throwing from FFI callbacks is not allowed` — a fatal that no catch block sees and
 * that would otherwise end this process quietly, with the last round's line as the final output. So
 * the run announces its own verdict, and anything that ends the process before then says so here.
 */
$verdict = new Verdict();

register_shutdown_function(static function () use ($verdict): void {
    if ($verdict->reached) {
        return;
    }

    echo PHP_EOL, 'SOAK preemption: FAIL — the process ended before the verdict: a fatal error, or a ',
    'fiber left parked in the interrupt callback', PHP_EOL;

    exit(1);
});

/** @var list<resource> $sockets Kept open for the whole run; a round reuses them rather than churning fds. */
$sockets = [];

for ($pair = 0; $pair < $options['ioPairs']; ++$pair) {
    $created = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    if ($created === false) {
        echo 'SOAK preemption: INCONCLUSIVE — stream_socket_pair() failed, so there is no IO to soak', PHP_EOL;

        $verdict->reached = true;

        exit(2);
    }

    stream_set_blocking($created[0], false);
    stream_set_blocking($created[1], false);

    $sockets[] = $created[0];
    $sockets[] = $created[1];
}

// One reference per burner seed, computed here in ordinary straight-line code that nothing
// interrupts. Every round compares against it, so a wrong answer is caught in the round it happens
// in rather than at the end of the run.
/** @var list<array{int, int}> $reference */
$reference = [];

for ($seed = 0; $seed < $options['burners']; ++$seed) {
    $reference[] = burn($seed, $options['iterations']);
}

echo 'PHP ', PHP_VERSION, ' — preemptive soak for ', sprintf('%.0fs', $options['seconds']), ': ',
$options['burners'], ' burners of ', $options['iterations'], ' iterations, ', $options['parkers'],
' parkers, ', $options['ioPairs'], ' IO pairs per round, ', sprintf('%.0f ms', $options['slice'] * 1000),
' slice', $options['selfTest'] ? ' (SELF-TEST: one burner is corrupted, this must FAIL)' : '',
$options['injectLeak'] ? ' (INJECT-LEAK: a block is retained per round, this must FAIL)' : '',
PHP_EOL, PHP_EOL;

/** @var list<int> $allocated */
$allocated = [];
/** @var list<int> $resident */
$resident = [];

$spawned    = 0;
$finished   = 0;
$mismatches = 0;
$checks     = 0;
$rounds     = 0;
$elapsed    = 0.0;

/**
 * Retained deliberately by `--inject-leak`, exactly as in `soak-memory-flatness.php`.
 *
 * @var list<string> $leaked
 */
$leaked = [];

$runtime = new Runtime(preemptive: true, slice: $options['slice']);

$runtime->run(static function (TaskRuntime $self) use (
    $options,
    $reference,
    $sockets,
    &$allocated,
    &$resident,
    &$spawned,
    &$finished,
    &$mismatches,
    &$checks,
    &$rounds,
    &$elapsed,
    &$leaked,
): void {
    $scheduler = $self->scheduler();
    $preemptor = $self->preemptor();
    $started   = microtime(true);
    $previous  = 0;

    while (microtime(true) - $started < $options['seconds']) {
        $group       = new WaitGroup($scheduler);
        $rendezvous  = new Channel($scheduler);
        $roundChecks = 0;
        $roundWrong  = 0;

        for ($seed = 0; $seed < $options['burners']; ++$seed) {
            $group->add();
            ++$spawned;

            Coroutine::spawn(static function () use (
                $seed,
                $options,
                $reference,
                $group,
                &$finished,
                &$roundChecks,
                &$roundWrong,
            ): void {
                try {
                    [$sum, $rolling] = burn($seed, $options['iterations']);

                    // The corruption --self-test injects: the arithmetic check has to be able to
                    // see a single wrong unit, or it is not checking anything.
                    if ($options['selfTest'] && $seed === 0) {
                        ++$sum;
                    }

                    ++$roundChecks;

                    if ([$sum, $rolling] !== $reference[$seed]) {
                        ++$roundWrong;
                    }
                } finally {
                    ++$finished;
                    $group->done();
                }
            });
        }

        // Parkers: half of them park on the timer heap and then hand a value over a rendezvous
        // channel, one consumer takes exactly as many as were sent. Both wait queues are exercised,
        // and the count is exact so nothing can park for the rest of the run.
        if ($options['parkers'] > 0) {
            $group->add();
            ++$spawned;

            Coroutine::spawn(static function () use ($rendezvous, $options, $group, &$finished): void {
                try {
                    for ($taken = 0; $taken < $options['parkers']; ++$taken) {
                        $rendezvous->recv();
                    }
                } finally {
                    ++$finished;
                    $group->done();
                }
            });

            for ($parker = 0; $parker < $options['parkers']; ++$parker) {
                $group->add();
                ++$spawned;

                Coroutine::spawn(static function () use ($rendezvous, $parker, $group, &$finished): void {
                    try {
                        Coroutine::sleep(0.001);
                        Coroutine::yield();
                        $rendezvous->send($parker);
                    } finally {
                        ++$finished;
                        $group->done();
                    }
                });
            }
        }

        // Real IO: a four-byte round trip per pair, every byte accounted for, so neither end can
        // park on a readiness that never comes.
        for ($pair = 0; $pair < $options['ioPairs']; ++$pair) {
            $left  = $sockets[$pair * 2];
            $right = $sockets[$pair * 2 + 1];

            $group->add();
            ++$spawned;

            Coroutine::spawn(static function () use ($right, $group, &$finished): void {
                try {
                    Io::awaitReadable($right);
                    fread($right, 4);
                    Io::awaitWritable($right);
                    fwrite($right, 'pong');
                } finally {
                    ++$finished;
                    $group->done();
                }
            });

            $group->add();
            ++$spawned;

            Coroutine::spawn(static function () use ($left, $group, &$finished): void {
                try {
                    Io::awaitWritable($left);
                    fwrite($left, 'ping');
                    Io::awaitReadable($left);
                    fread($left, 4);
                } finally {
                    ++$finished;
                    $group->done();
                }
            });
        }

        $group->wait();

        if ($options['injectLeak']) {
            $leaked[] = str_repeat('leak', 256 * 1024);
        }

        // A leak must survive collection to be a leak; a cycle the GC has not visited yet is not.
        gc_collect_cycles();

        $checks     += $roundChecks;
        $mismatches += $roundWrong;
        ++$rounds;

        $allocated[] = memory_get_usage(true);

        $rss = residentSetSize();
        if ($rss !== null) {
            $resident[] = $rss;
        }

        $total   = $preemptor?->preemptions() ?? 0;
        $elapsed = microtime(true) - $started;

        printf(
            'round %3d  elapsed %5.1fs  preemptions %6d (+%4d)  allocated %10s  rss %10s  arithmetic %s%s',
            $rounds,
            $elapsed,
            $total,
            $total - $previous,
            bytes($allocated[count($allocated) - 1]),
            $rss        === null ? 'n/a' : bytes($rss),
            $roundWrong === 0 ? 'ok' : sprintf('WRONG x%d', $roundWrong),
            PHP_EOL,
        );

        $previous = $total;
    }
});

foreach ($sockets as $socket) {
    fclose($socket);
}

$preemptions = $runtime->preemptor()?->preemptions() ?? 0;
$scheduler   = $runtime->scheduler();

// Nothing should be left parked inside the interrupt callback: `run()` drains what it discards. The
// count is reported rather than assumed, because a fiber still in there at request shutdown is a
// fatal error, and "none left" is the only answer that means the drain worked.
$drained = $scheduler instanceof Scheduler ? $scheduler->drainPreempted() : 0;

$warmup = $options['warmup'] >= 0 ? $options['warmup'] : intdiv($rounds, 4);
$warmup = max(1, min($warmup, $rounds - 3));

$measured = ['allocated' => array_values(array_slice($allocated, $warmup))];

if ($resident !== []) {
    $measured['rss'] = array_values(array_slice($resident, $warmup));
}

echo PHP_EOL;

if ($rounds < 4 || $measured['allocated'] === []) {
    printf(
        'SOAK preemption: INCONCLUSIVE — %d round(s) in %.1fs is not a trend; raise --seconds or ' .
        'lower --iterations%s',
        $rounds,
        $elapsed,
        PHP_EOL,
    );

    $verdict->reached = true;

    exit(2);
}

if ($preemptions === 0) {
    printf(
        'SOAK preemption: INCONCLUSIVE — nothing was ever preempted in %.1fs, so this run measured ' .
        'a cooperative workload%s',
        $elapsed,
        PHP_EOL,
    );

    $verdict->reached = true;

    exit(2);
}

printf(
    'preemption %s — %d suspensions over %.1fs of continuous run (%.0f/s), first %d round(s) dropped as warmup%s',
    'observed',
    $preemptions,
    $elapsed,
    $preemptions / max($elapsed, 0.001),
    $warmup,
    PHP_EOL,
);

printf(
    'arithmetic %s — %d burner results against the uninterrupted reference, %d wrong%s',
    $mismatches === 0 ? 'correct' : 'CORRUPTED',
    $checks,
    $mismatches,
    PHP_EOL,
);

$failed = $mismatches !== 0;

foreach ($measured as $metric => $samples) {
    $trend = trendVerdict($samples, $options['tolerance']);

    printf('%-10s %s — %s%s', $metric, $trend['ok'] ? 'flat' : 'CLIMBING', $trend['reason'], PHP_EOL);

    $failed = $failed || !$trend['ok'];
}

$fibersOk = $spawned === $finished && $drained === 0;

printf(
    'fibers     %s — %d spawned, %d finished, %d still parked in the interrupt callback%s',
    $fibersOk ? 'clean' : 'LEAKED',
    $spawned,
    $finished,
    $drained,
    PHP_EOL,
);

$failed = $failed || !$fibersOk;

$disarmed = $runtime->preemptor()?->isArmed() === false;

printf('timer      %s after the run%s', $disarmed ? 'disarmed' : 'STILL ARMED', PHP_EOL);

$failed = $failed || !$disarmed;

echo PHP_EOL, 'SOAK preemption: ', $failed ? 'FAIL' : 'PASS', PHP_EOL;

$verdict->reached = true;

exit($failed ? 1 : 0);
