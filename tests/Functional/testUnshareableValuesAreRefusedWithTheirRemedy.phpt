--TEST--
Every value that cannot cross a worker boundary is refused with the remedy named
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\NotShareableValueException;
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Timer;
use Lisachenko\SharedData\Ipc\NotShareableValueException as SubstrateRefusal;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// There is no fallback path: the runtime will not quietly encode a value to get it across. So the
// only thing a refusal owes the caller is the remedy — persist(), SharedArray, a Task, or a
// pre-fork registerSharedClosure().
echo NotShareableValueException::forArray()->getMessage(), PHP_EOL;
echo NotShareableValueException::forClosure()->getMessage(), PHP_EOL;
echo NotShareableValueException::forObject(new stdClass())->getMessage(), PHP_EOL;

$stream = fopen('php://memory', 'rb');
echo NotShareableValueException::forResource($stream)->getMessage(), PHP_EOL;
fclose($stream);

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);
$runtime->declareShared('jobs', SharedChannel::class, 4);

$runtime->run(static function (TaskRuntime $self): void {
    Timer::after(15.0, static function (): void {
        throw new RuntimeException('deadline: the refusals never came back');
    });

    $channel = $self->shared('jobs');

    // The refusals the substrate raises on the wire path, each naming the shape that has no
    // address-shaped form. A plain array is the sharpest one: the engine grows a HashTable into the
    // private heap of whichever process filled it, and writes that private pointer into the shared
    // struct before it aborts.
    foreach ([
        'a plain array'       => [1, 2, 3],
        'a post-fork closure' => static fn (): int => 1,
        'a foreign object'    => new stdClass(),
    ] as $what => $value) {
        try {
            $channel->send($value);

            echo $what, ' was accepted, which it must not be', PHP_EOL;
        } catch (SubstrateRefusal $refusal) {
            echo $what, ' is refused: yes', PHP_EOL;
        }
    }

    // And nothing got onto the ring on the way.
    echo 'nothing was smuggled onto the ring: ', $channel->count() === 0 ? 'yes' : 'no', PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
a plain array cannot cross a worker boundary: the engine grows a HashTable into process-local heap, which siblings cannot see. Use SharedArray instead.
a closure cannot cross a worker boundary unless it was registered before the fork: call $runtime->registerSharedClosure($name, $closure) while the pool is still being configured, and it is shareable for the life of the family. A closure created after the fork can never be shared — no inspection can tell it apart from a valid one at a stale address — so work created then travels as a Task instead.
an instance of stdClass is not in the shared arena, so it cannot cross a worker boundary. Pass it through $runtime->persist($object) first.
a resource (stream) cannot cross a worker boundary: it names process-local kernel state. Open it inside the task instead.
a plain array is refused: yes
a post-fork closure is refused: yes
a foreign object is refused: yes
nothing was smuggled onto the ring: yes
children left: none
