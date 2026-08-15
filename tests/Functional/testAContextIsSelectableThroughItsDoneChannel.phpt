--TEST--
A context takes part in a select through its done channel
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
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$request   = Context::withCancel($scheduler);
$attempt   = Context::withCancel($request);
$jobs      = new Channel($scheduler);

$worker = $scheduler->spawn(function () use ($scheduler, $attempt, $jobs): void {
    while (true) {
        $keepGoing = Select::on($scheduler)
            ->recv($jobs, function (mixed $job): bool {
                echo "worker: handling {$job}\n";

                return true;
            })
            ->recv($attempt->done(), function (mixed $value, bool $ok): bool {
                // Cancellation arrives as an exhausted channel — closed and with nothing in it —
                // which is exactly how any other finished producer would announce itself.
                printf("worker: cancelled (value %s, ok %s)\n", var_export($value, true), var_export($ok, true));

                return false;
            })
            ->run();

        if (!$keepGoing) {
            return;
        }
    }
});

$scheduler->spawn(function () use ($request, $jobs, $worker): void {
    $jobs->send('alpha');
    $jobs->send('beta');

    echo 'worker before the cancellation: ', $worker->status()->name, "\n";

    // Cancels the parent; the worker is watching the child, and the signal travels down.
    $request->cancel();
});

$scheduler->loop();

echo 'worker: ', $worker->status()->name, PHP_EOL;

// A watcher that arrives after the fact still learns what happened: a closed channel keeps
// answering, unlike a signal that has to be caught while it is being sent.
$late = $attempt->done()->recvOk();
printf("a late watcher: value %s, ok %s\n", var_export($late[0], true), var_export($late[1], true));
?>
--EXPECT--
worker: handling alpha
worker: handling beta
worker before the cancellation: BLOCKED
worker: cancelled (value NULL, ok false)
worker: DONE
a late watcher: value NULL, ok false
