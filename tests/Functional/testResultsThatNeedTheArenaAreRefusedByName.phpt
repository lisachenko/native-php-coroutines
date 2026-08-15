--TEST--
Scalar results cross a worker boundary today; string, array and object results name ticket #7
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\PreforkTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\Tests\Support\ConstantTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;
use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelDeadline;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

$scheduler = new Scheduler();

$tasks = new PreforkTaskDirectory();

// The five tags that are complete inside the sixteen bytes of a tagged record, and the three that
// are an arena address. Nothing in between, and no fallback: a serialize() here would be the
// Never-Serialize Rule broken for every user of the runtime at once.
$inline = [
    'null'  => new ConstantTask(null),
    'true'  => new ConstantTask(true),
    'false' => new ConstantTask(false),
    'int'   => new ConstantTask(-7),
    'float' => new ConstantTask(1.5),
];

$arena = [
    'string' => new ConstantTask('hello'),
    'array'  => new ConstantTask([1, 2, 3]),
    'object' => new ConstantTask(new stdClass()),
];

foreach ([...array_values($inline), ...array_values($arena)] as $task) {
    $tasks->register($task);
}

$supervisor = new WorkerSupervisor($scheduler, $tasks);
$supervisor->start(1);

$main = $scheduler->spawn(function () use ($supervisor, $inline, $arena): void {
    parallelDeadline(10.0, 'every tag being answered');

    foreach ($inline as $name => $task) {
        echo $name, ': ';
        var_dump($supervisor->spawn($task, 0)->await());
    }

    foreach ($arena as $name => $task) {
        try {
            $supervisor->spawn($task, 0)->await();

            echo $name, ": returned, which it must not\n";
        } catch (LogicException $refused) {
            echo $name, ': ', $refused->getMessage(), "\n";
        }
    }
});

$scheduler->runUntil($main);

$supervisor->shutdown();

echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
null: NULL
true: bool(true)
false: bool(false)
int: int(-7)
float: float(1.5)
string: a value tagged STR travels by arena address, and the shared arena is not implemented yet (see #7); results tagged NIL, TRUE, FALSE, INT or FLOAT cross a worker boundary today
array: a value tagged ARR travels by arena address, and the shared arena is not implemented yet (see #7); results tagged NIL, TRUE, FALSE, INT or FLOAT cross a worker boundary today
object: a value tagged OBJ travels by arena address, and the shared arena is not implemented yet (see #7); results tagged NIL, TRUE, FALSE, INT or FLOAT cross a worker boundary today
children left: none
