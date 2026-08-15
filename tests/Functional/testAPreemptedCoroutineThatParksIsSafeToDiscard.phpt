--TEST--
A preempted coroutine that goes on to park on a channel is drained out of the callback and then discarded
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;

include __DIR__ . '/../../vendor/autoload.php';

const ITERATIONS = 1_500_000;

$state           = new stdClass();
$state->parked   = false;
$state->finished = false;

register_shutdown_function(static function (): void {
    echo 'the process reached shutdown: yes', PHP_EOL;
});

$runtime = new Runtime(preemptive: true);

$runtime->run(static function (RuntimeInterface $self) use ($state): void {
    $silence = new Channel($self->scheduler());

    // Kept so the assertion below can look at the channel itself once the run is over. Whether
    // the coroutine really parked is only observable from the channel's own wait queue: a flag
    // set by the coroutine can only prove it reached the line *before* parking.
    $state->channel = $silence;

    Coroutine::spawn(static function () use ($state, $silence): void {
        $sum = 0;

        for ($index = 0; $index < ITERATIONS; $index++) {
            $sum += $index % 7;
        }

        $state->parked = true;

        // Nobody ever sends here. The drain resumes this coroutine out of the preemption
        // callback, it parks itself on the channel, and from there it is ordinary debris that
        // main returning is entitled to drop.
        $silence->recv();

        $state->finished = true;
    });

    Coroutine::yield();
});

echo 'the loop was preempted: ',
    ($runtime->preemptor()?->preemptions() ?? 0) >= 1 ? 'yes' : 'no', PHP_EOL;
echo 'it was drained far enough to park itself: ',
    $state->parked && $state->channel->pendingReceivers() === 1 ? 'yes' : 'no', PHP_EOL;
echo 'it was then discarded rather than resumed: ', $state->finished ? 'no' : 'yes', PHP_EOL;
?>
--EXPECT--
the loop was preempted: yes
it was drained far enough to park itself: yes
it was then discarded rather than resumed: yes
the process reached shutdown: yes
