--TEST--
A Mutex serializes critical sections and hands the lock over in arrival order
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Sync\Mutex;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$mutex     = new Mutex($scheduler);
$slow      = new Channel($scheduler);

// What the lock is actually for in cooperative code: a section that suspends half-way through, so
// another coroutine can walk into it and find the state inconsistent.
$account = ['balance' => 100];

foreach (['first', 'second', 'third'] as $name) {
    $scheduler->spawn(function () use ($mutex, $slow, $name, &$account): void {
        $mutex->lock();

        try {
            $seen = $account['balance'];
            echo "{$name} read {$seen}\n";

            // The suspension point that makes the lock necessary.
            $slow->recv();

            $account['balance'] = $seen + 10;
            echo "{$name} wrote {$account['balance']}\n";
        } finally {
            $mutex->unlock();
        }
    });
}

$scheduler->spawn(function () use ($mutex, $slow): void {
    echo 'locked: ', var_export($mutex->isLocked(), true), "\n";
    echo 'waiting for the lock: ', $mutex->pendingWaiters(), "\n";

    foreach ([1, 2, 3] as $ignored) {
        $slow->send(null);
    }
});

$scheduler->loop();

echo 'final balance: ', $account['balance'], PHP_EOL;
echo 'still locked: ', var_export($mutex->isLocked(), true), PHP_EOL;
?>
--EXPECT--
first read 100
locked: true
waiting for the lock: 2
first wrote 110
second read 110
second wrote 120
third read 120
third wrote 130
final balance: 130
still locked: false
