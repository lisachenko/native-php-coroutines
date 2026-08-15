--TEST--
A context with a timeout cancels itself once its sleeper returns
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Context;
use Lisachenko\NativePhpCoroutines\Select;
use Lisachenko\NativePhpCoroutines\SuspendCommand;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$slept     = [];

// Stands in for the runtime's sleep: the timeout is armed by parking a coroutine, so the deadline
// costs nothing while it is pending and never blocks the process. Here it hands the CPU back a
// fixed number of times instead of consulting a clock, which keeps the ordering deterministic.
$sleeper = static function (float $seconds) use ($scheduler, &$slept): void {
    $slept[] = $seconds;

    for ($tick = 0; $tick < 2; $tick++) {
        $scheduler->suspend(SuspendCommand::YIELD);
    }
};

$request = Context::withTimeout($scheduler, 0.25, $sleeper);
$jobs    = new Channel($scheduler);

$scheduler->spawn(function () use ($scheduler, $request, $jobs): void {
    $outcome = Select::on($scheduler)
        ->recv($jobs, fn (mixed $job): string => "handled {$job}")
        ->recv($request->done(), fn (): string => 'timed out')
        ->run();

    echo $outcome, "\n";
});

$scheduler->loop();

echo 'requested delay: ', $slept[0], PHP_EOL;
echo 'cancelled: ', var_export($request->isCancelled(), true), PHP_EOL;
echo 'waiters left on the job channel: ', $jobs->pendingReceivers(), PHP_EOL;
?>
--EXPECT--
timed out
requested delay: 0.25
cancelled: true
waiters left on the job channel: 0
