--TEST--
Two panicking workers each keep their own error: class, message and trace all belong to the right task
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\PanicWithDomainErrorTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\PanicWithMessageTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// A captured panic used to be persisted under SharedError's class name, so the second worker's
// capture superseded the first worker's registry entry — a waiter attaching the first error's
// address after that found nothing, or worse, another task's detail. Each capture is now its own
// instance graph whose address rides in the panicking task's own slot, so each waiter reads
// exactly its own failure: the two tasks here panic with different classes and messages, and each
// exception's class, message AND trace must all belong to the task that was awaited.
$runtime = new Runtime(workers: 2, arenaSize: 32 << 20);

$runtime->run(static function (TaskRuntime $self): void {
    Timer::after(30.0, static function (): void {
        throw new RuntimeException('deadline: the two panics never reached their waiters');
    });

    $handles = [
        'first'  => [$self->spawnParallel(new PanicWithMessageTask('the first worker exploded'), 0),
            'RuntimeException', 'PanicWithMessageTask', 'PanicWithDomainErrorTask'],
        'second' => [$self->spawnParallel(new PanicWithDomainErrorTask('the second worker exploded'), 1),
            'DomainException', 'PanicWithDomainErrorTask', 'PanicWithMessageTask'],
    ];

    foreach ($handles as $which => [$handle, $ownClass, $ownFrame, $otherFrame]) {
        try {
            $handle->await();

            echo $which, ': the await returned, which it must not', PHP_EOL;
        } catch (ParallelTaskException $panic) {
            echo $which, ' class is its own: ',
                $panic->originalClass() === $ownClass ? 'yes' : 'no (' . $panic->originalClass() . ')', PHP_EOL;
            echo $which, ' message is its own: ',
                str_contains($panic->getMessage(), "the {$which} worker exploded") ? 'yes' : 'no', PHP_EOL;
            echo $which, ' trace is its own: ',
                str_contains($panic->originalTrace(), $ownFrame)
                && !str_contains($panic->originalTrace(), $otherFrame) ? 'yes' : 'no', PHP_EOL;
        }
    }
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
first class is its own: yes
first message is its own: yes
first trace is its own: yes
second class is its own: yes
second message is its own: yes
second trace is its own: yes
children left: none
