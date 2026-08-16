--TEST--
Two panicking workers each keep their own error, and each waiter reads its own
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
use Lisachenko\NativePhpCoroutines\Tests\Support\PanicWithMessageTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// A captured panic used to be persisted under SharedError's class name, so the second worker's
// capture superseded the first worker's registry entry — a waiter attaching the first error's
// address after that found nothing. Each capture is now its own instance graph: two workers
// failing near-simultaneously each leave an error, and each waiter reads exactly its own.
$runtime = new Runtime(workers: 2, arenaSize: 32 << 20);

$runtime->run(static function (TaskRuntime $self): void {
    Timer::after(30.0, static function (): void {
        throw new RuntimeException('deadline: the two panics never reached their waiters');
    });

    $one = $self->spawnParallel(new PanicWithMessageTask('the first worker exploded'), 0);
    $two = $self->spawnParallel(new PanicWithMessageTask('the second worker exploded'), 1);

    foreach (['first' => $one, 'second' => $two] as $which => $handle) {
        try {
            $handle->await();

            echo $which, ': the await returned, which it must not', PHP_EOL;
        } catch (ParallelTaskException $panic) {
            echo $which, ' waiter got its own panic: ',
                str_contains($panic->getMessage(), "the {$which} worker exploded") ? 'yes' : 'no', PHP_EOL;
        }
    }
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
first waiter got its own panic: yes
second waiter got its own panic: yes
children left: none
