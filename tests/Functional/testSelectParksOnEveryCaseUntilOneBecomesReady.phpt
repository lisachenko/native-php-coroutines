--TEST--
A select with nothing ready parks on every case, and any one of them can wake it
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

$scheduler = new FakeScheduler();
$jobs      = new Channel($scheduler);
$quit      = new Channel($scheduler);
$results   = new Channel($scheduler);

$worker = $scheduler->spawn(function () use ($scheduler, $jobs, $quit, $results): void {
    while (true) {
        $keepGoing = Select::on($scheduler)
            ->recv($jobs, function (mixed $job, bool $ok) use ($results): bool {
                echo "worker: handling {$job}\n";
                $results->send(strtoupper((string) $job));

                return true;
            })
            ->recv($quit, function (): bool {
                echo "worker: quitting\n";

                return false;
            })
            ->run();

        if (!$keepGoing) {
            return;
        }
    }
});

$scheduler->spawn(function () use ($jobs, $quit, $results, $worker): void {
    // Every case is a rendezvous with nobody on the other end, so the select has no choice but to
    // park — on all three registrations at once.
    echo 'worker: ', $worker->status()->name, "\n";
    echo 'jobs waiters: ', $jobs->pendingReceivers(), "\n";
    echo 'quit waiters: ', $quit->pendingReceivers(), "\n";
    echo 'results waiters: ', $results->pendingSenders(), "\n";

    // Assigned before echoing, so that the printed order is the order things happened rather than
    // the order the arguments of a single echo were evaluated.
    $jobs->send('alpha');
    $result = $results->recv();
    echo "result: {$result}\n";

    $jobs->send('beta');
    $result = $results->recv();
    echo "result: {$result}\n";

    // A different case wakes it this time, and the loop has not accumulated waiters on the way.
    echo 'jobs waiters after two rounds: ', $jobs->pendingReceivers(), "\n";
    $quit->send(null);
});

$scheduler->loop();

echo 'worker: ', $worker->status()->name, PHP_EOL;
?>
--EXPECT--
worker: BLOCKED
jobs waiters: 1
quit waiters: 1
results waiters: 0
worker: handling alpha
result: ALPHA
worker: handling beta
result: BETA
jobs waiters after two rounds: 1
worker: quitting
worker: DONE
