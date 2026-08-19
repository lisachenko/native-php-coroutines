--TEST--
A WaitGroup joins coroutines that sleep on the real timer heap
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;

include __DIR__ . '/../../vendor/autoload.php';

// The workers are spawned in the order 3, 1, 2 but sleep for proportional
// durations, so finishing in numeric order proves the real timer heap ordered
// them rather than the run queue. wait() must then survive being parked across
// all three wakeups.
(new Runtime())->run(function (TaskRuntime $runtime): void {
    $group = new WaitGroup($runtime->scheduler());

    foreach ([3, 1, 2] as $worker) {
        $group->add(1);
        Coroutine::spawn(function () use ($group, $worker): void {
            Coroutine::sleep($worker * 0.01);
            echo 'worker ', $worker, PHP_EOL;
            $group->done();
        });
    }

    $group->wait();
    echo 'joined', PHP_EOL;
});
?>
--EXPECT--
worker 1
worker 2
worker 3
joined
