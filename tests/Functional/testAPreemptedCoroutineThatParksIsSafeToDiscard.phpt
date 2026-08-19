--TEST--
A preempted coroutine that goes on to park on a channel is drained out of the callback and then discarded
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

include __DIR__ . '/../../vendor/autoload.php';

// Roughly half a 10 ms slice on the machines this has been measured on, so the preemption
// counter is re-read often enough to stop promptly without the loop itself being interrupted
// by the check. The cap bounds a build where preemption never fires: the test must fail, not spin.
const CHUNK           = 250_000;
const MAX_ITERATIONS  = 100_000_000;

$state           = new stdClass();
$state->parked   = false;
$state->finished = false;

register_shutdown_function(static function (): void {
    echo 'the process reached shutdown: yes', PHP_EOL;
});

$runtime = new Runtime(preemptive: true);

$runtime->run(static function (TaskRuntime $self) use ($state): void {
    $silence = new Channel($self->scheduler());

    // Kept so the assertion below can look at the channel itself once the run is over. Whether
    // the coroutine really parked is only observable from the channel's own wait queue: a flag
    // set by the coroutine can only prove it reached the line *before* parking.
    $state->channel = $silence;

    Coroutine::spawn(static function () use ($state, $silence, $self): void {
        $sum   = 0;
        $index = 0;

        // Burn CPU until the scheduler has actually taken a slice back, rather than for a fixed
        // number of iterations. How long a fixed count takes is a property of the machine: 1.5M
        // iterations finished inside the first 10 ms slice on a CI runner, so the coroutine was
        // never preempted and the test failed on a build where preemption worked perfectly.
        while (($self->preemptor()?->preemptions() ?? 0) < 1 && $index < MAX_ITERATIONS) {
            for ($chunk = 0; $chunk < CHUNK; $chunk++) {
                $sum += $index % 7;
                $index++;
            }
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
