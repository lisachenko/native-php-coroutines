--TEST--
A select in a loop spreads its choice across cases that are always ready
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

const ROUNDS = 400;

$scheduler = new FakeScheduler();
$left      = new Channel($scheduler, 1);
$right     = new Channel($scheduler, 1);

$scheduler->spawn(function () use ($scheduler, $left, $right): void {
    $taken = ['left' => 0, 'right' => 0];

    for ($round = 0; $round < ROUNDS; $round++) {
        // Both channels hold a value on every single iteration, so both cases are always ready and
        // the choice is entirely the select's own.
        $left->send('l');
        $right->send('r');

        $winner = Select::on($scheduler)
            ->recv($left, fn (): string => 'left')
            ->recv($right, fn (): string => 'right')
            ->run();

        $taken[$winner]++;

        // Clear whichever value was not taken, so the next round starts from the same state.
        if ($left->count() > 0) {
            $left->recv();
        }

        if ($right->count() > 0) {
            $right->recv();
        }
    }

    // A select that polled its cases in declaration order would score ROUNDS to 0 here: the
    // starvation is total, not statistical, which is why the shuffle is not a nicety.
    printf("both cases were taken: %s\n", var_export($taken['left'] > 0 && $taken['right'] > 0, true));

    // Fair within a very wide band, so the assertion is about fairness rather than about a
    // particular random sequence: a balanced choice sits four standard deviations inside this.
    $lowest  = min($taken['left'], $taken['right']);
    $highest = max($taken['left'], $taken['right']);
    printf(
        "neither case dominates: %s\n",
        var_export($lowest > ROUNDS * 0.25 && $highest < ROUNDS * 0.75, true),
    );
    printf("every round was accounted for: %s\n", var_export($lowest + $highest === ROUNDS, true));
});

$scheduler->loop();
?>
--EXPECT--
both cases were taken: true
neither case dominates: true
every round was accounted for: true
