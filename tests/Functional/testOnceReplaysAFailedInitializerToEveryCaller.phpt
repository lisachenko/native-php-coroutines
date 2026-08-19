--TEST--
A Once whose initializer threw stays spent and re-throws that failure to everyone
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Sync\Once;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$once      = new Once($scheduler);
$gate      = new Channel($scheduler);
$attempts  = 0;

// The chosen behaviour: the failure is recorded, the Once stays spent, and the same exception is
// re-thrown to the caller that was waiting and to every later call. Retrying instead would re-run
// an initializer that has usually already performed half of its side effects; returning null would
// be the half-initialised result this primitive exists to prevent.
$scheduler->spawn(function () use ($once, $gate, &$attempts): void {
    try {
        $once->do(function () use ($gate, &$attempts): string {
            $attempts++;
            $gate->recv();

            throw new RuntimeException('the resource could not be opened');
        });
    } catch (RuntimeException $failure) {
        echo 'first caller: ', $failure->getMessage(), "\n";
    }
});

$scheduler->spawn(function () use ($once, &$attempts): void {
    try {
        $once->do(function () use (&$attempts): string {
            $attempts++;

            return 'a retry must never happen';
        });
    } catch (RuntimeException $failure) {
        echo 'waiting caller: ', $failure->getMessage(), "\n";
    }
});

$scheduler->spawn(function () use ($gate): void {
    $gate->send(null);
});

$scheduler->loop();

// A call that arrives long after the failure gets the same answer, not a fresh attempt.
$scheduler->spawn(function () use ($once, &$attempts): void {
    try {
        $once->do(function () use (&$attempts): string {
            $attempts++;

            return 'a retry must never happen';
        });
    } catch (RuntimeException $failure) {
        echo 'later caller: ', $failure->getMessage(), "\n";
    }
});

$scheduler->loop();

echo 'initializer attempts: ', $attempts, PHP_EOL;
echo 'hasRun: ', var_export($once->hasRun(), true), PHP_EOL;
echo 'hasFailed: ', var_export($once->hasFailed(), true), PHP_EOL;
?>
--EXPECT--
first caller: the resource could not be opened
waiting caller: the resource could not be opened
later caller: the resource could not be opened
initializer attempts: 1
hasRun: true
hasFailed: true
