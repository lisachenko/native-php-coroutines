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
 * Arena watermark soak: does a steady-state parallel workload keep taking arena memory?
 *
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-arena-watermark.php
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-arena-watermark.php --self-test
 *
 * The arena is a **bump allocator**: one cursor, moved forward under a lock, never moved back. There
 * is no free list, there is no GC to bail it out, and nothing is reclaimed until the region dies
 * with the process that created it. So the failure mode is not a crash, it is a cursor that never
 * stops moving — and a long-running pool then dies of `ArenaException::exhausted()` hours in.
 *
 * # Why the criterion is a plateau and not zero growth
 *
 * Leak-until-teardown is the design, not a defect, and some operations are *documented* to cost a
 * block every time:
 *
 * - **rewriting a shared string property costs a new arena block per write.** A reader may be
 *   following the old pointer at that very moment, so the old bytes cannot be freed. N writes cost
 *   N blocks, for ever.
 * - the first rounds pay for lazily-created structures — a channel's ring, a result-slot table, the
 *   roots directory — which are allocated once and then reused.
 *
 * What must *not* grow is a steady state built from operations that are supposed to be free:
 * scalars through a shared channel, integer results through result slots, and reads of a shared
 * object. That is what this soak drives, and the verdict is that the watermark **plateaus** after
 * warmup rather than that it is zero.
 *
 * `--self-test` swaps the scalar payload for a fresh string per round, which is exactly the
 * documented per-write cost. It must FAIL. A detector nobody has seen fail is a detector nobody
 * knows works — and the same goes for the slot detector, so `--leak-slots` takes a slot every round
 * without giving it back and must FAIL on the slot series.
 *
 * # What else it watches
 *
 * - **memory flatness** of the parent process, the same two metrics `soak-memory-flatness.php` uses,
 *   because the arena being flat says nothing about the request heap that reads it;
 * - **result-slot consumption**, which is the thing issue #16 was about. Slots come out of a
 *   pre-sized table that cannot grow, and they are now *recycled*: one is taken per
 *   `spawnParallel()` and handed back when its handle has taken the answer. So the criterion is a
 *   plateau here too, and a strict one — the table's high-water mark must stop moving entirely
 *   once the steady state is reached. A climb means slots are not coming back, which is the
 *   failure that used to kill a long-running pool hours in;
 * - **leftover children**, because a soak that leaves a worker behind has not proven anything about
 *   a runtime that shuts down.
 *
 * Exit codes: 0 plateau, 1 the watermark or the process memory climbed (or a child survived),
 * 2 the soak could not run.
 */

namespace Lisachenko\NativePhpCoroutines\Tools\ArenaWatermark;

use Lisachenko\NativePhpCoroutines\ChannelInterface;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * The shared object this soak writes to, defined here rather than borrowed from the test fixtures.
 *
 * A soak is the code that decides whether a leak exists, so it carries its own subject: a tool that
 * only compiles because a test fixture happens to be shaped a certain way is a tool that stops
 * measuring the day the fixture changes. No plain-array property anywhere either — the engine grows
 * a HashTable into the private heap of whichever process filled it.
 */
final class SoakCounter
{
    public int $value = 0;

    public string $label = '';
}

/** Hands back the integer it was built with: a result that costs the arena nothing. */
final class SoakEchoTask implements Task
{
    public function __construct(private readonly int $value) {}

    public function run(TaskRuntime $runtime): mixed
    {
        return $this->value;
    }
}

/**
 * @return array{rounds: int, tasks: int, warmup: int, tolerance: int, workers: int, slots: int,
 *               selfTest: bool, leakSlots: bool}
 */
function options(): array
{
    $parsed = getopt('', [
        'rounds:', 'tasks:', 'warmup:', 'tolerance:', 'workers:', 'slots:', 'self-test', 'leak-slots', 'help',
    ]) ?: [];

    if (array_key_exists('help', $parsed)) {
        echo 'usage: soak-arena-watermark.php [--rounds=40] [--tasks=8] ',
        '[--warmup=<a quarter of the rounds>] [--tolerance=4096] [--workers=2] [--slots=64] ',
        '[--self-test] [--leak-slots]', PHP_EOL;

        exit(0);
    }

    $rounds = max(4, (int) ($parsed['rounds'] ?? 40));
    $warmup = (int) ($parsed['warmup'] ?? intdiv($rounds, 4));

    return [
        'rounds' => $rounds,
        'tasks'  => max(1, (int) ($parsed['tasks'] ?? 8)),
        // At least one warmup round, and always at least three measured ones to have a trend at all.
        'warmup'    => max(1, min($warmup, $rounds - 3)),
        'tolerance' => max(0, (int) ($parsed['tolerance'] ?? 4096)),
        'workers'   => max(1, (int) ($parsed['workers'] ?? 2)),
        // Deliberately far fewer than the run will spawn: the point is that the supply circulates.
        'slots'     => max(1, (int) ($parsed['slots'] ?? 64)),
        'selfTest'  => array_key_exists('self-test', $parsed),
        'leakSlots' => array_key_exists('leak-slots', $parsed),
    ];
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
 * The verdict for one metric, in the same shape `soak-memory-flatness.php` reports.
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

/**
 * Slot consumption has to be exactly flat once the steady state is reached.
 *
 * A slot is a countable record, not a byte watermark: the pool takes one per spawn and gives it
 * back when the handle takes the answer, so a workload of constant concurrency creates a constant
 * number of records and then stops creating any. One extra record is not noise, it is a slot that
 * did not come back — which is exactly what issue #16 was about.
 *
 * @param list<int> $samples High-water marks of the measured rounds.
 * @return array{ok: bool, reason: string}
 */
function slotPlateauVerdict(array $samples): array
{
    if ($samples === []) {
        return ['ok' => false, 'reason' => 'no measured rounds'];
    }

    $first = $samples[0];
    $last  = $samples[count($samples) - 1];

    if ($last !== $first) {
        return [
            'ok'     => false,
            'reason' => sprintf('%d slot records at the first measured round, %d at the last', $first, $last),
        ];
    }

    return ['ok' => true, 'reason' => sprintf('flat at %d slot record(s) across the measured rounds', $first)];
}

/** What "no children" looks like: -1 means this process has none at all. */
function childrenLeft(): string
{
    $status = 0;
    $pid    = pcntl_waitpid(-1, $status, WNOHANG);

    return match (true) {
        $pid === -1 => 'none',
        $pid === 0  => 'still running',
        default     => 'a zombie',
    };
}

if (!function_exists('pcntl_fork')) {
    echo 'SOAK arena-watermark: INCONCLUSIVE — ext-pcntl is required to fork a pool', PHP_EOL;

    exit(2);
}

$options = options();

// The table is sized for CONCURRENCY, not for throughput: a slot is taken per spawn and handed back
// when its handle takes the answer, and this soak awaits each task before spawning the next. So the
// supply is deliberately a fraction of what the run will spawn — a table big enough to absorb the
// whole run would prove nothing about slots coming back.
$slotCount = $options['slots'];
$spawns    = $options['rounds'] * $options['tasks'];

$runtime = new Runtime(workers: $options['workers'], arenaSize: 64 << 20, slots: $slotCount);
$arena   = $runtime->arena();

if ($arena === null) {
    echo 'SOAK arena-watermark: INCONCLUSIVE — the runtime mapped no arena', PHP_EOL;

    exit(2);
}

$runtime->declareShared('soak.channel', SharedChannel::class, 16);
$runtime->declareShared('soak.counter', SoakCounter::class);

// One published task per round shape, so the directory hands out an inherited address and the round
// itself allocates nothing for the task graph — the measurement is about the workload, not about
// re-persisting the same object forty times.
$scalarTask = new SoakEchoTask(4242);
$runtime->publishTask($scalarTask);

echo 'PHP ', PHP_VERSION, ' — ', $options['rounds'], ' rounds of ', $options['tasks'],
' tasks on ', $options['workers'], ' workers over ', $slotCount, ' result slots (', $spawns,
' spawns), first ', $options['warmup'], ' rounds discarded as warmup',
$options['selfTest'] ? ' (SELF-TEST: a string is rewritten every round, this must FAIL)' : '',
$options['leakSlots'] ? ' (LEAK-SLOTS: a slot is taken and never given back, this must FAIL)' : '',
PHP_EOL, PHP_EOL;

/** @var list<int> $watermarks */
$watermarks = [];
/** @var list<int> $allocated */
$allocated = [];
/** @var list<int> $resident */
$resident = [];
/** @var list<int> $slotHighWater Distinct slot records the table has ever created. */
$slotHighWater = [];
/** @var list<int> $slotsOutstanding Slots handed out and not yet given back, at the end of a round. */
$slotsOutstanding = [];

$slotsRecycled = 0;

$runtime->run(static function (TaskRuntime $self) use (
    $options,
    $arena,
    $scalarTask,
    &$watermarks,
    &$allocated,
    &$resident,
    &$slotHighWater,
    &$slotsOutstanding,
    &$slotsRecycled,
): void {
    $channel = $self->shared('soak.channel');
    $counter = $self->shared('soak.counter');
    $store   = $arena->store();
    $slots   = $arena->slotTable();

    if (!$channel instanceof ChannelInterface || !$counter instanceof SoakCounter) {
        throw new \RuntimeException('the soak roots did not bind in this process');
    }

    for ($round = 0; $round < $options['rounds']; ++$round) {
        for ($task = 0; $task < $options['tasks']; ++$task) {
            // An integer result is complete inside the record: no arena block, no interning.
            $self->spawnParallel($scalarTask)->await();

            // A scalar through the ring is a word written into a pre-allocated slot.
            $channel->send($round * 1000 + $task);
            $channel->recv();
        }

        if ($options['leakSlots']) {
            // The slot-detector's own failure on demand: a slot taken and never released is the
            // pre-recycling behaviour, and the high-water series must climb because of it.
            $slots->allocateSlot();
        }

        // A scalar property write allocates nothing; a *string* rewrite allocates a block, which is
        // exactly the documented per-write cost --self-test exists to prove is detectable.
        $handle = $store->mutableHandle($counter);
        $handle->writeScalar('value', $round);

        if ($options['selfTest']) {
            $handle->writeString('label', 'round-' . $round . '-' . str_repeat('x', 64));
        }

        // Read back by named property — never a dump, which would make engine C code write a
        // per-process pointer into the shared struct and segfault every sibling afterwards.
        if ($counter->value !== $round) {
            throw new \RuntimeException('the shared counter did not take the round number');
        }

        gc_collect_cycles();

        $watermarks[]       = $arena->arena()->watermark();
        $allocated[]        = memory_get_usage(true);
        $slotHighWater[]    = $slots->highWaterMark();
        $slotsOutstanding[] = $slots->outstanding();

        $rss = residentSetSize();
        if ($rss !== null) {
            $resident[] = $rss;
        }

        printf(
            'round %3d  watermark %12s  allocated %10s  rss %10s  slots %4d high / %3d out%s',
            $round + 1,
            bytes($watermarks[count($watermarks) - 1]),
            bytes($allocated[count($allocated) - 1]),
            $rss === null ? 'n/a' : bytes($rss),
            $slotHighWater[count($slotHighWater) - 1],
            $slotsOutstanding[count($slotsOutstanding) - 1],
            PHP_EOL,
        );
    }

    $slotsRecycled = $slots->recycled();
});

$measured = [
    'watermark' => array_values(array_slice($watermarks, $options['warmup'])),
    'allocated' => array_values(array_slice($allocated, $options['warmup'])),
];

if ($resident !== []) {
    $measured['rss'] = array_values(array_slice($resident, $options['warmup']));
}

if ($measured['watermark'] === []) {
    echo PHP_EOL, 'SOAK arena-watermark: INCONCLUSIVE — no measured rounds after warmup', PHP_EOL;

    exit(2);
}

$slotSeries = array_values(array_slice($slotHighWater, $options['warmup']));

echo PHP_EOL;
printf(
    'result slots: %d spawns over a table of %d — %d records ever created, %d recycled, %d still out%s',
    $spawns,
    $slotCount,
    $slotHighWater === [] ? 0 : $slotHighWater[count($slotHighWater) - 1],
    $slotsRecycled,
    $slotsOutstanding === [] ? 0 : $slotsOutstanding[count($slotsOutstanding) - 1],
    PHP_EOL,
);

// The slot criterion is stricter than the byte ones on purpose: a slot is either handed back or it
// is not, so a steady state has an exactly flat high-water mark and there is no tolerance to spend.
$slotVerdict = slotPlateauVerdict($slotSeries);

printf(
    '%-10s %s — %s%s',
    'slots',
    $slotVerdict['ok'] ? 'plateau' : 'CLIMBING',
    $slotVerdict['reason'],
    PHP_EOL,
);

$failed = !$slotVerdict['ok'];

foreach ($measured as $metric => $samples) {
    $verdict = trendVerdict($samples, $options['tolerance']);

    printf('%-10s %s — %s%s', $metric, $verdict['ok'] ? 'plateau' : 'CLIMBING', $verdict['reason'], PHP_EOL);

    $failed = $failed || !$verdict['ok'];
}

$children = childrenLeft();

printf('children   %s%s', $children === 'none' ? 'none left' : 'LEFTOVER: ' . $children, PHP_EOL);

$failed = $failed || $children !== 'none';

echo PHP_EOL, 'SOAK arena-watermark: ', $failed ? 'FAIL' : 'PASS', PHP_EOL;

exit($failed ? 1 : 0);
