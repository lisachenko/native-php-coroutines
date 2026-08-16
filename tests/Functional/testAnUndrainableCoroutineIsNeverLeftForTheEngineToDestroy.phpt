--TEST--
The process holding an undrainable coroutine is ended deliberately, never by the engine destroying its fiber
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use function Lisachenko\NativePhpCoroutines\Tests\Support\superviseChildProcess;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/childProcess.php';

// The other half of bounding the drain. Giving up on a coroutine is not the same as letting go of
// it: its fiber is suspended inside the interrupt callback, and the engine unwinding it there is
// `Throwing from FFI callbacks is not allowed` — an uncatchable fatal, exit 255, measured on both
// minors by spikes S6 and S7. So the runtime ends the process itself, with SIGKILL, once every
// other shutdown function has run.
$result = superviseChildProcess(__DIR__ . '/../Support/runawayCoroutine.php', 20.0);
$output = $result['stdout'] . $result['stderr'];

echo 'the fiber was never destroyed by the engine: ',
    str_contains($output, 'Throwing from FFI callbacks is not allowed') ? 'no' : 'yes', PHP_EOL;
echo 'the process was ended deliberately: ', $result['signal'] === SIGKILL ? 'yes' : 'no', PHP_EOL;
echo 'the diagnosis reached stderr before it: ',
    str_contains($result['stderr'], 'a preempted coroutine never reached a safe point') ? 'yes' : 'no', PHP_EOL;
echo 'a shutdown function registered before the runtime still ran: ',
    str_contains($result['stdout'], 'CHILD: a shutdown function registered before the runtime still ran')
        ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
the fiber was never destroyed by the engine: yes
the process was ended deliberately: yes
the diagnosis reached stderr before it: yes
a shutdown function registered before the runtime still ran: yes
