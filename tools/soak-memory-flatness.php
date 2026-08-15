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
 * Memory flatness soak: does sustained spawn / park / resume leak?
 *
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-memory-flatness.php
 *
 * A scheduler is a machine for creating and destroying coroutines, wait nodes, timer entries and
 * poller registrations. The failure it is prone to is not a crash but a slow climb: one wait node
 * that is never unlinked, one timer that stays on the heap, one coroutine the run queue still
 * references. Nothing goes wrong for ten thousand cycles, and then a long-running process dies.
 *
 * So this measures two numbers per round and looks for a *trend*, not for a value:
 *
 * - `memory_get_usage(true)` — the PHP allocator's own footprint, which catches leaked zvals.
 * - RSS from `/proc/self/statm` — what the kernel actually keeps resident, which also catches
 *   allocator arenas that PHP counts as free but never gives back.
 *
 * # Why the verdict is a trend and not a threshold
 *
 * A round-to-round difference means nothing: the allocator grows in chunks, the GC runs when it
 * feels like it, and the first rounds pay for lazily-initialised engine state. A leak, on the other
 * hand, is *shaped*: it grows with the number of cycles and it does not come back. The verdict
 * therefore drops a warmup prefix and then fails on either of two shapes:
 *
 * 1. **strictly monotonic** — every measured round is above the previous one, which is a leak
 *    however small the steps are; or
 * 2. **net growth beyond the tolerance** — the last measured round is more than `--tolerance`
 *    bytes above the first, which is a leak too coarse to hide in allocator noise.
 *
 * Both are per-metric, and the run fails when either metric fails.
 *
 * Exit codes: 0 flat, 1 a climb was detected, 2 the soak could not run.
 */

namespace Lisachenko\NativePhpCoroutines\Tools\MemoryFlatness;

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{rounds: int, coroutines: int, warmup: int, tolerance: int, injectLeak: bool} */
function soakOptions(): array
{
    $options = getopt('', ['rounds:', 'coroutines:', 'warmup:', 'tolerance:', 'inject-leak', 'help']) ?: [];

    if (array_key_exists('help', $options)) {
        echo 'usage: soak-memory-flatness.php [--rounds=40] [--coroutines=250] ',
        '[--warmup=<a quarter of the rounds>] [--tolerance=1048576] [--inject-leak]', PHP_EOL;

        exit(0);
    }

    $rounds     = max(4, (int) ($options['rounds'] ?? 40));
    $coroutines = max(1, (int) ($options['coroutines'] ?? 250));
    $warmup     = (int) ($options['warmup'] ?? intdiv($rounds, 4));
    $tolerance  = max(0, (int) ($options['tolerance'] ?? 1024 * 1024));

    return [
        'rounds'     => $rounds,
        'coroutines' => $coroutines,
        // At least one warmup round, and always at least three measured ones to have a trend at all.
        'warmup'     => max(1, min($warmup, $rounds - 3)),
        'tolerance'  => $tolerance,
        'injectLeak' => array_key_exists('inject-leak', $options),
    ];
}

/**
 * One round: spawn, park on every primitive there is, resume, and tear the runtime down.
 *
 * Every parking path Layer 1 has is exercised, because they own different bookkeeping: a channel
 * parks through a wait queue node, a sleep through the timer heap, a select through a token
 * registered on several channels at once, and IO through the poller's descriptor table.
 */
function soakRound(int $coroutines): void
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new \RuntimeException('the soak needs a socket pair, and stream_socket_pair() failed');
    }

    [$writeEnd, $readEnd] = $pair;
    stream_set_blocking($writeEnd, false);
    stream_set_blocking($readEnd, false);

    $runtime = new Runtime();

    $runtime->run(static function (RuntimeInterface $runtime) use ($coroutines, $writeEnd, $readEnd): void {
        $scheduler = $runtime->scheduler();

        $jobs    = new Channel($scheduler);
        $results = new Channel($scheduler, 8);
        $idle    = new Channel($scheduler);
        $group   = new WaitGroup($scheduler);

        // The consumer selects over two channels, so every iteration registers waiters on both and
        // has to unlink the loser — the classic select leak, if it is one.
        $group->add();
        Coroutine::spawn(static function () use ($scheduler, $jobs, $idle, $results, $group, $coroutines): void {
            try {
                for ($taken = 0; $taken < $coroutines;) {
                    $value = Select::on($scheduler)
                        ->recv($jobs, static fn(mixed $job): mixed => $job)
                        ->recv($idle, static fn(): null => null)
                        ->run();

                    if ($value === null) {
                        continue;
                    }

                    ++$taken;
                    $results->send($value);
                }

                $results->close();
            } finally {
                $group->done();
            }
        });

        $group->add();
        Coroutine::spawn(static function () use ($results, $group): void {
            try {
                foreach ($results as $ignored) {
                    // Drained and dropped: this soak is about bookkeeping, not about the values.
                }
            } finally {
                $group->done();
            }
        });

        for ($i = 0; $i < $coroutines; ++$i) {
            $group->add();

            Coroutine::spawn(static function () use ($jobs, $group, $i): void {
                try {
                    Coroutine::sleep(0.0);
                    Coroutine::yield();
                    $jobs->send($i);
                } finally {
                    $group->done();
                }
            });
        }

        $group->add();
        Coroutine::spawn(static function () use ($readEnd, $group): void {
            try {
                Io::awaitReadable($readEnd);
                fread($readEnd, 64);
            } finally {
                $group->done();
            }
        });

        $group->add();
        Coroutine::spawn(static function () use ($writeEnd, $group): void {
            try {
                Io::awaitWritable($writeEnd);
                fwrite($writeEnd, 'tick');
            } finally {
                $group->done();
            }
        });

        $group->wait();
    });

    fclose($writeEnd);
    fclose($readEnd);
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
 * The verdict for one metric.
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
    for ($i = 1; $i < count($samples); ++$i) {
        if ($samples[$i] <= $samples[$i - 1]) {
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

$options = soakOptions();

echo 'PHP ', PHP_VERSION, ' — ', $options['rounds'], ' rounds of ', $options['coroutines'],
' coroutines, first ', $options['warmup'], ' discarded as warmup', PHP_EOL, PHP_EOL;

$allocated = [];
$resident  = [];
$rssBroken = false;

/**
 * Retained deliberately by `--inject-leak`.
 *
 * The flag does not test the runtime — it tests *this script*, by producing exactly the shape the
 * verdict is supposed to catch. Run it after touching the trend logic: a detector nobody has ever
 * seen fail is a detector nobody knows works.
 *
 * @var list<string> $leaked
 */
$leaked = [];

for ($round = 0; $round < $options['rounds']; ++$round) {
    soakRound($options['coroutines']);

    if ($options['injectLeak']) {
        $leaked[] = str_repeat('leak', 256 * 1024);
    }

    // A leak must survive collection to be a leak; a cycle the GC has simply not visited yet is not.
    gc_collect_cycles();

    $allocated[] = memory_get_usage(true);

    $rss = residentSetSize();
    if ($rss === null) {
        $rssBroken = true;
    } else {
        $resident[] = $rss;
    }

    printf(
        'round %3d  allocated %10s  rss %10s%s',
        $round + 1,
        bytes($allocated[count($allocated) - 1]),
        $rss === null ? 'n/a' : bytes($rss),
        PHP_EOL,
    );
}

$measuredAllocated = array_values(array_slice($allocated, $options['warmup']));
$measuredResident  = array_values(array_slice($resident, $options['warmup']));

if ($measuredAllocated === []) {
    echo PHP_EOL, 'SOAK memory-flatness: INCONCLUSIVE — no measured rounds after warmup', PHP_EOL;

    exit(2);
}

$verdicts = ['allocated' => trendVerdict($measuredAllocated, $options['tolerance'])];

if ($measuredResident !== []) {
    $verdicts['rss'] = trendVerdict($measuredResident, $options['tolerance']);
} elseif ($rssBroken) {
    echo PHP_EOL, 'note: /proc/self/statm is unreadable here, so RSS was not measured', PHP_EOL;
}

echo PHP_EOL;

$failed = false;
foreach ($verdicts as $metric => $verdict) {
    printf('%-10s %s — %s%s', $metric, $verdict['ok'] ? 'flat' : 'CLIMBING', $verdict['reason'], PHP_EOL);
    $failed = $failed || !$verdict['ok'];
}

echo PHP_EOL, 'SOAK memory-flatness: ', $failed ? 'FAIL' : 'PASS', PHP_EOL;

exit($failed ? 1 : 0);
