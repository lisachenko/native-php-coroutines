<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Proof that Layer 1 needs no FFI.
 *
 * Run directly, never through the .phpt suite:
 *
 *     php -d ffi.enable=0 -d opcache.jit=off tests/no-ffi-smoke.php
 *
 * Every .phpt file carries `ffi.enable=1` in its own --INI-- block (FFI cannot be switched on at
 * runtime, so the engine-level tests have no other way to get it), which means a .phpt child would
 * re-enable FFI and quietly defeat this check. Hence a plain script.
 *
 * It is an actual run, not a load test of the contracts: coroutines are spawned, the scheduler
 * round-robins them, a sleep parks on the timer heap, and a value crosses a socket pair through
 * `Io::awaitReadable()` — all with FFI switched off.
 */
declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\CoroutineStatus;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Exception\NotShareableValueException;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\SelectToken;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

require __DIR__ . '/../vendor/autoload.php';

$failures = [];

/**
 * @param non-empty-string $what
 */
function check(string $what, bool $condition, array &$failures): void
{
    if (!$condition) {
        $failures[] = $what;
    }
}

// The whole point of the job: if FFI is somehow on, the run proves nothing.
if (filter_var(ini_get('ffi.enable'), FILTER_VALIDATE_BOOL)) {
    fwrite(STDERR, "FAIL: ffi.enable is on; run this script with -d ffi.enable=0\n");
    exit(1);
}

// Fiber is what Layer 1 is built on, and it is core PHP, not an extension.
check('Fiber is available', class_exists(Fiber::class), $failures);

// Contracts and value objects must load and work without touching FFI.
check('SuspendCommand::YIELD stays runnable', SuspendCommand::YIELD->staysRunnable(), $failures);
check('SuspendCommand::BLOCKED does not', !SuspendCommand::BLOCKED->staysRunnable(), $failures);
check('a preempted coroutine may not be thrown into', !SuspendCommand::PREEMPT->allowsThrow(), $failures);
check('CoroutineStatus has a terminal state', CoroutineStatus::DONE instanceof CoroutineStatus, $failures);

$token = new SelectToken();
check('the first select case wins', $token->claim(0), $failures);
check('a losing select case is refused', !$token->claim(1), $failures);
check('the winner is recorded', $token->winner() === 0, $failures);

$record = TaggedRecord::decode(TaggedRecord::int(-42)->encode());
check('an int record round-trips', $record->tag === Tag::INT && $record->payload === -42, $failures);

$control = ControlRecord::decode((new ControlRecord(Opcode::RESULT, 7, TaggedRecord::float(0.5)))->encode());
check('a control record round-trips', $control->opcode === Opcode::RESULT && $control->slotId === 7, $failures);

check(
    'the unshareable-value message names its remedy',
    str_contains(NotShareableValueException::forArray()->getMessage(), 'SharedArray'),
    $failures,
);

// ---------------------------------------------------------------------------------------------
// An actual cooperative run, with ffi.enable=0: scheduler, timers and the poller.
// ---------------------------------------------------------------------------------------------

$turns    = [];
$received = null;
$slept    = 0.0;

[$writeEnd, $readEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
stream_set_blocking($readEnd, false);

$runtime = new Runtime();

$runtime->run(function () use (&$turns, &$received, &$slept, $writeEnd, $readEnd): void {
    foreach (['A', 'B'] as $name) {
        Coroutine::spawn(function () use ($name, &$turns): void {
            foreach ([1, 2] as $round) {
                $turns[] = $name . $round;
                Coroutine::yield();
            }
        });
    }

    // Nothing is on this socket yet, so the reader parks on the poller and the process idles in
    // stream_select() until the writer below hands it something.
    Coroutine::spawn(function () use ($readEnd, &$received): void {
        Io::awaitReadable($readEnd);
        $received = fread($readEnd, 5);
    });

    Coroutine::spawn(function () use ($writeEnd): void {
        Coroutine::sleep(0.02);
        fwrite($writeEnd, 'hello');
    });

    $before = hrtime(true);
    Coroutine::sleep(0.05);
    $slept  = (hrtime(true) - $before) / 1_000_000_000;
});

fclose($writeEnd);
fclose($readEnd);

check('coroutines round-robin fairly', $turns === ['A1', 'B1', 'A2', 'B2'], $failures);
check('a value crossed a socket through the poller', $received === 'hello', $failures);
check('sleeping waited out its deadline', $slept >= 0.05, $failures);

// Go semantics: the run ends with main, and a second runtime is perfectly usable afterwards.
$discarded = 'not touched';

(new Runtime())->run(function () use (&$discarded): void {
    Coroutine::spawn(function () use (&$discarded): void {
        Coroutine::sleep(10.0);
        $discarded = 'the straggler ran';
    });

    Coroutine::yield();
});

check('pending coroutines are discarded when main returns', $discarded === 'not touched', $failures);

if ($failures !== []) {
    fwrite(STDERR, "FAIL: Layer 1 smoke checks failed without FFI:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}

echo "OK: Layer 1 contracts load and behave with ffi.enable=0 on PHP " . PHP_VERSION . "\n";
