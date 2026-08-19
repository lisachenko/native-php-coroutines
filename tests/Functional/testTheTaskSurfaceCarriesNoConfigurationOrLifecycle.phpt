--TEST--
The type a task is handed carries no configuration or lifecycle method
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

include __DIR__ . '/../../vendor/autoload.php';

// Issue #21: a task used to receive the whole runtime, run() and declareShared() included, with
// only documentation stopping it from starting a second runtime inside its worker. This pins the
// split as a property of the types, so a method drifting back onto the task surface fails a test
// rather than a code review.
$surface = new ReflectionClass(TaskRuntime::class);

foreach (['run', 'declareShared', 'registerSharedClosure', 'publishTask'] as $forbidden) {
    echo $forbidden, ' is reachable from a task: ', $surface->hasMethod($forbidden) ? 'yes' : 'no', PHP_EOL;
}

$parameter = new ReflectionMethod(Task::class, 'run')->getParameters()[0]->getType();

echo 'a task is handed exactly the narrow surface: ',
    $parameter instanceof ReflectionNamedType && $parameter->getName() === TaskRuntime::class ? 'yes' : 'no',
    PHP_EOL;
echo 'the concrete runtime provides it: ', is_a(Runtime::class, TaskRuntime::class, true) ? 'yes' : 'no', PHP_EOL;
?>
--EXPECT--
run is reachable from a task: no
declareShared is reachable from a task: no
registerSharedClosure is reachable from a task: no
publishTask is reachable from a task: no
a task is handed exactly the narrow surface: yes
the concrete runtime provides it: yes
