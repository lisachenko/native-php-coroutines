--TEST--
Context cancellation is selectable and cancels children under the real scheduler
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Context;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Select;

include __DIR__ . '/../../vendor/autoload.php';

// Cancellation is a closing channel, which is the whole reason it composes with
// select for free: the worker below selects over real work and its own done()
// channel without either side knowing about the other.
(new Runtime())->run(function (TaskRuntime $runtime): void {
    $scheduler = $runtime->scheduler();

    $parent = Context::withCancel($scheduler);
    $child  = Context::withCancel($parent);
    $work   = new Channel($scheduler, capacity: 0);

    Coroutine::spawn(function () use ($scheduler, $child, $work): void {
        $outcome = Select::on($scheduler)
            ->recv($work, static fn(mixed $value): string => 'worked: ' . (string) $value)
            ->recv($child->done(), static fn(): string => 'cancelled')
            ->run();

        echo $outcome, PHP_EOL;
    });

    Coroutine::spawn(function () use ($parent): void {
        Coroutine::sleep(0.01);
        echo 'cancelling parent', PHP_EOL;
        $parent->cancel();
    });

    // Park the main coroutine until the worker has reported.
    Coroutine::sleep(0.03);

    echo 'parent cancelled: ', $parent->isCancelled() ? 'yes' : 'no', PHP_EOL;
    echo 'child cancelled: ', $child->isCancelled() ? 'yes' : 'no', PHP_EOL;
});
?>
--EXPECT--
cancelling parent
cancelled
parent cancelled: yes
child cancelled: yes
