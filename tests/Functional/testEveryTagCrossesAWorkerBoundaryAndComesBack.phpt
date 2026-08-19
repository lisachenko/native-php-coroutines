--TEST--
spawnParallel and await carry every tag of the value contract, addresses included
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\EchoTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedCounter;
use Lisachenko\NativePhpCoroutines\Tests\Support\SharedRootTask;
use Lisachenko\NativePhpCoroutines\Timer;
use Lisachenko\SharedData\Ipc\SharedArray;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

$runtime = new Runtime(workers: 2, arenaSize: 32 << 20);
$runtime->declareShared('numbers', SharedArray::class, 8);

$shared = $runtime->persist(new SharedCounter());
$shared->value = 7;

// The eight tags of the contract. NIL/TRUE/FALSE/INT/FLOAT are complete inside the record; STR is
// an arena string, OBJ a shared object and ARR a SharedArray, and all three travel as addresses.
$tasks = [
    'NIL'   => new EchoTask(null),
    'TRUE'  => new EchoTask(true),
    'FALSE' => new EchoTask(false),
    'INT'   => new EchoTask(-7),
    'FLOAT' => new EchoTask(1.5),
    'STR'   => new EchoTask('a string that lives in the arena'),
    'OBJ'   => new EchoTask($shared),
    'ARR'   => new SharedRootTask('numbers'),
];

foreach ($tasks as $task) {
    $runtime->publishTask($task);
}

$runtime->run(static function (TaskRuntime $self) use ($tasks, $shared): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: a tag never came back');
    });

    foreach ($tasks as $tag => $task) {
        $value = $self->spawnParallel($task)->await();

        printf(
            "%-5s %-12s %s\n",
            $tag,
            get_debug_type($value),
            match (true) {
                // Formatted by reading a named property, never by dumping the object: a dump makes
                // engine C code write a per-process pointer into shared memory.
                $value instanceof SharedCounter => 'value=' . $value->value . ' same=' . ($value === $shared ? 'yes' : 'no'),
                $value instanceof SharedArray   => 'slots=' . count($value),
                is_string($value)               => '"' . $value . '"',
                is_float($value)                => (string) $value,
                is_int($value)                  => (string) $value,
                is_bool($value)                 => $value ? 'true' : 'false',
                default                         => 'null',
            },
        );
    }
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
NIL   null         null
TRUE  bool         true
FALSE bool         false
INT   int          -7
FLOAT float        1.5
STR   string       "a string that lives in the arena"
OBJ   Lisachenko\NativePhpCoroutines\Tests\Support\SharedCounter value=7 same=yes
ARR   Lisachenko\SharedData\Ipc\SharedArray slots=8
children left: none
