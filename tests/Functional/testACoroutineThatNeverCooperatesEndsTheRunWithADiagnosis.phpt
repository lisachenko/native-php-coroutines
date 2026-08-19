--TEST--
A coroutine that never parks and never returns ends the run with a diagnosis instead of hanging it
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\UndrainableCoroutineException;

use function Lisachenko\NativePhpCoroutines\Tests\Support\superviseChildProcess;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/childProcess.php';

// The behaviour under test is how a process *ends*, and the regression it guards against is an
// endless drain — so it is observed from outside, on a child with a deadline. A test that ran the
// runaway coroutine itself would hang the suite rather than fail, which is the one outcome worth
// more than any assertion here.
$result = superviseChildProcess(__DIR__ . '/../Support/runawayCoroutine.php', 20.0);
$output = $result['stdout'] . $result['stderr'];

echo 'the runaway process ended on its own: ', $result['timedOut'] ? 'no' : 'yes', PHP_EOL;
echo 'run() came back with the typed diagnosis: ',
    str_contains($result['stdout'], 'CHILD: run() threw ' . UndrainableCoroutineException::class)
        ? 'yes' : 'no', PHP_EOL;
echo 'the diagnosis names the coroutine and the line that spawned it: ',
    preg_match(
        '~coroutine #\d+ \[resumed \d+ time\(s\) over [\d.]+s, still inside the preemption '
        . 'callback\], spawned at \S+/runawayCoroutine\.php:\d+~',
        $output,
    ) === 1 ? 'yes' : 'no', PHP_EOL;
echo 'and it names the remedy: ',
    str_contains($output, UndrainableCoroutineException::REMEDY) ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
the runaway process ended on its own: yes
run() came back with the typed diagnosis: yes
the diagnosis names the coroutine and the line that spawned it: yes
and it names the remedy: yes
