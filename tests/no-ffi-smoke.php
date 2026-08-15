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
 * As the cooperative runtime lands, this script grows into an actual run: spawn a few coroutines,
 * pass values over a channel, select, sleep, and assert the results — all with FFI switched off.
 */
declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\CoroutineStatus;
use Lisachenko\NativePhpCoroutines\Exception\NotShareableValueException;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
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

if ($failures !== []) {
    fwrite(STDERR, "FAIL: Layer 1 smoke checks failed without FFI:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}

echo "OK: Layer 1 contracts load and behave with ffi.enable=0 on PHP " . PHP_VERSION . "\n";
