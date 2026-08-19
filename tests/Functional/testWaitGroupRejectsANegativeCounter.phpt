--TEST--
A WaitGroup counter that would go negative throws instead of clamping
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$group     = new WaitGroup($scheduler);

$scheduler->spawn(function () use ($group): void {
    $group->add();
    $group->done();

    // One done() too many — a doubled call in an error path, usually. Clamping to zero would hide
    // it behind a wait() that returns slightly too early, which is far harder to find later.
    try {
        $group->done();
    } catch (LogicException $failure) {
        echo $failure->getMessage(), "\n";
    }

    try {
        $group->add(-2);
    } catch (LogicException $failure) {
        echo $failure->getMessage(), "\n";
    }

    echo 'counter is unchanged: ', $group->count(), "\n";
});

$scheduler->loop();
?>
--EXPECT--
A WaitGroup counter cannot go negative: 0 + -1
A WaitGroup counter cannot go negative: 0 + -2
counter is unchanged: 0
