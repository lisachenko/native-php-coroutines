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
 * Process-hygiene soak: does a run leave anything behind?
 *
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-no-leftover-children.php
 *
 * The invariant is one sentence: **when a run is over, this process has no children — neither alive
 * nor unreaped.** An orphaned worker keeps holding the arena mapping and the control socket open,
 * and a zombie keeps a pid slot; both are invisible until a machine has run the program a few
 * thousand times.
 *
 * Today the runtime is Layer 1 and forks nothing, so a green run means "the cooperative runtime
 * spawns no processes of its own" — a real property, worth pinning before the parallel layer can
 * quietly break it. When workers land (#7) this same tool becomes the supervision test: point it at
 * a workload with `workers > 0` and the invariant does not change one word.
 *
 * # Why the check reads /proc rather than trusting waitpid
 *
 * `pcntl_waitpid()` only ever tells you about children this process has not yet reaped, so a child
 * that is *alive and running* is precisely the case it does not report. `/proc/<pid>/stat` gives
 * both halves: field 4 is the parent pid, so it finds live orphans, and field 3 is the state, so a
 * `Z` finds zombies. The reap loop still runs afterwards — a leftover that can be reaped is still a
 * leftover, and reaping it is how the tool avoids leaving one of its own behind.
 *
 * Exit codes: 0 clean, 1 something survived the run, 2 the soak could not run here.
 */

namespace Lisachenko\NativePhpCoroutines\Tools\NoLeftoverChildren;

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{rounds: int, coroutines: int, workers: int, selfTest: bool} */
function soakOptions(): array
{
    $options = getopt('', ['rounds:', 'coroutines:', 'workers:', 'self-test', 'help']) ?: [];

    if (array_key_exists('help', $options)) {
        echo 'usage: soak-no-leftover-children.php [--rounds=20] [--coroutines=100] [--workers=0] ',
        '[--self-test]', PHP_EOL;

        exit(0);
    }

    return [
        'rounds'     => max(1, (int) ($options['rounds'] ?? 20)),
        'coroutines' => max(1, (int) ($options['coroutines'] ?? 100)),
        'workers'    => max(0, (int) ($options['workers'] ?? 0)),
        'selfTest'   => array_key_exists('self-test', $options),
    ];
}

/**
 * Every process whose parent is $parent, with its state letter.
 *
 * @return array<int, string> pid => state (`R`, `S`, `Z`, …)
 */
function childrenOf(int $parent): array
{
    $children = [];

    foreach (glob('/proc/[0-9]*/stat') ?: [] as $statFile) {
        $stat = @file_get_contents($statFile);
        if (!is_string($stat)) {
            // The process ended between the glob and the read; that is not a child of ours anymore.
            continue;
        }

        // The comm field is parenthesised and may itself contain spaces, so fields are counted from
        // the last ')' rather than from the start of the line.
        $commEnd = strrpos($stat, ')');
        if ($commEnd === false) {
            continue;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $commEnd + 1))) ?: [];
        if (count($fields) < 2) {
            continue;
        }

        [$state, $parentPid] = $fields;
        if ((int) $parentPid !== $parent) {
            continue;
        }

        $pid = (int) basename(dirname($statFile));
        if ($pid !== 0) {
            $children[$pid] = (string) $state;
        }
    }

    return $children;
}

/** @return list<int> Pids reaped here — a child that outlived the run, whatever its exit status. */
function reapEverything(): array
{
    $reaped = [];

    while (true) {
        $status = 0;
        $pid    = pcntl_waitpid(-1, $status, WNOHANG);
        if ($pid <= 0) {
            return $reaped;
        }

        $reaped[] = $pid;
    }
}

/** One round of ordinary Layer 1 work: coroutines, channels, timers and one IO park. */
function soakRound(int $coroutines, int $workers): void
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new \RuntimeException('the soak needs a socket pair, and stream_socket_pair() failed');
    }

    [$writeEnd, $readEnd] = $pair;
    stream_set_blocking($writeEnd, false);
    stream_set_blocking($readEnd, false);

    $runtime = new Runtime($workers);

    $runtime->run(static function (RuntimeInterface $runtime) use ($coroutines, $writeEnd, $readEnd): void {
        $scheduler = $runtime->scheduler();

        $jobs  = new Channel($scheduler, 4);
        $group = new WaitGroup($scheduler);

        $group->add();
        Coroutine::spawn(static function () use ($jobs, $group, $coroutines): void {
            try {
                for ($taken = 0; $taken < $coroutines; ++$taken) {
                    $jobs->recv();
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

/**
 * Produce exactly the two failures this tool exists to catch, so the detector itself is tested.
 *
 * One child stays alive (an orphaned worker) and one exits without being reaped (a zombie). Both are
 * cleaned up before the script ends — a hygiene tool that leaks processes would be a joke.
 *
 * @return list<int>
 */
function forkDeliberateLeftovers(): array
{
    $leftovers = [];

    $alive = pcntl_fork();
    if ($alive === 0) {
        sleep(30);

        exit(0);
    }

    if ($alive > 0) {
        $leftovers[] = $alive;
    }

    $zombie = pcntl_fork();
    if ($zombie === 0) {
        exit(0);
    }

    if ($zombie > 0) {
        $leftovers[] = $zombie;

        // Give the kernel a moment to move it to Z; without this the probe may see it still running,
        // which is a different (also reported) failure.
        usleep(50_000);
    }

    return $leftovers;
}

if (!function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
    echo 'SOAK no-leftover-children: INCONCLUSIVE — ext-pcntl and ext-posix are required', PHP_EOL;

    exit(2);
}

if (!is_dir('/proc/self')) {
    echo 'SOAK no-leftover-children: INCONCLUSIVE — /proc is not available on this platform', PHP_EOL;

    exit(2);
}

$options = soakOptions();
$self    = getmypid();

if (!is_int($self)) {
    echo 'SOAK no-leftover-children: INCONCLUSIVE — this process has no pid to compare against', PHP_EOL;

    exit(2);
}

echo 'PHP ', PHP_VERSION, ' — pid ', $self, ', ', $options['rounds'], ' rounds of ',
$options['coroutines'], ' coroutines, workers: ', $options['workers'], PHP_EOL;

$before = childrenOf($self);
if ($before !== []) {
    echo 'note: this process already had children before the run: ',
    implode(', ', array_keys($before)), PHP_EOL;
}

try {
    for ($round = 0; $round < $options['rounds']; ++$round) {
        soakRound($options['coroutines'], $options['workers']);
    }
} catch (\LogicException $refused) {
    // Asking for workers today is refused by design; say which ticket implements it and stop, rather
    // than reporting a green run that proved nothing.
    echo PHP_EOL, 'SOAK no-leftover-children: INCONCLUSIVE — ', $refused->getMessage(), PHP_EOL;

    exit(2);
}

echo 'rounds completed', PHP_EOL;

if ($options['selfTest']) {
    echo 'self-test: forking one live child and one zombie on purpose', PHP_EOL;
    forkDeliberateLeftovers();
}

// A worker that was signalled at the end of a run needs a moment to die; polling for a bounded
// window keeps a slow exit from reading as a leak, and a real leak from being waited on forever.
$survivors = [];
$deadline  = microtime(true) + 2.0;
do {
    $survivors = array_diff_key(childrenOf($self), $before);
    if ($survivors === []) {
        break;
    }

    usleep(50_000);
} while (microtime(true) < $deadline);

// A zombie is found by both probes; it is one leftover, not two.
$reaped = array_values(array_diff(reapEverything(), array_keys($survivors)));

foreach ($survivors as $pid => $state) {
    printf('leftover: pid %d in state %s%s', $pid, $state === 'Z' ? 'Z (zombie, unreaped)' : $state, PHP_EOL);
}

foreach ($reaped as $pid) {
    printf('leftover: pid %d was still reapable after the run%s', $pid, PHP_EOL);
}

if ($options['selfTest']) {
    // Whatever the verdict, this script does not get to leave processes behind either.
    foreach (array_keys($survivors) as $pid) {
        @posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $ignoredStatus);
    }

    echo 'self-test: deliberate leftovers cleaned up', PHP_EOL;
}

$leftoverCount = count($survivors) + count($reaped);

echo PHP_EOL, 'SOAK no-leftover-children: ', $leftoverCount === 0 ? 'PASS' : 'FAIL',
$leftoverCount                                              === 0 ? '' : sprintf(' — %d leftover process(es)', $leftoverCount), PHP_EOL;

exit($leftoverCount === 0 ? 0 : 1);
