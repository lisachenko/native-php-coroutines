--TEST--
Once makes later callers wait for the initializer instead of letting them past it
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
$resource  = new Channel($scheduler);

// A half-built value, exactly as an initializer that suspends part-way through would leave it.
$state = ['ready' => false];

$scheduler->spawn(function () use ($once, $resource, &$state): void {
    $result = $once->do(function () use ($resource, &$state): string {
        echo "initializer: starting\n";

        // Suspends in the middle of building the thing — the moment where a Once that let other
        // callers straight through would hand them the half-built version.
        $resource->recv();

        $state['ready'] = true;
        echo "initializer: finished\n";

        return 'ready resource';
    });

    echo "first caller: {$result}\n";
});

foreach ([2, 3] as $number) {
    $scheduler->spawn(function () use ($once, &$state, $number): void {
        $result = $once->do(fn (): string => 'a second initializer must never run');
        echo "caller {$number} observed ready = ", var_export($state['ready'], true), " and got {$result}\n";
    });
}

$scheduler->spawn(function () use ($resource): void {
    echo "unblocking the initializer\n";
    $resource->send(null);
});

$scheduler->loop();
?>
--EXPECT--
initializer: starting
unblocking the initializer
initializer: finished
first caller: ready resource
caller 2 observed ready = true and got ready resource
caller 3 observed ready = true and got ready resource
