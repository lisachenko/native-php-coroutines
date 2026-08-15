--TEST--
The runtime refuses the parallel surface with a message naming the ticket that ships it
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;

include __DIR__ . '/../../vendor/autoload.php';

try {
    new Runtime(workers: 4);
} catch (LogicException $refusal) {
    echo $refusal->getMessage(), PHP_EOL;
}

$runtime = new Runtime();

$task = new class implements Task {
    public function run(RuntimeInterface $runtime): mixed
    {
        return null;
    }
};

foreach ([
    static fn (): mixed => $runtime->declareShared('jobs', 'SharedChannel', 8),
    static fn (): mixed => $runtime->shared('jobs'),
    static fn (): mixed => $runtime->persist(new stdClass()),
    static fn (): mixed => $runtime->spawnParallel($task),
] as $callable) {
    try {
        $callable();
    } catch (LogicException $refusal) {
        echo $refusal->getMessage(), PHP_EOL;
    }
}

// Layer 1 itself is fully available on the same runtime.
$runtime->run(static function (RuntimeInterface $self): void {
    echo 'the local scheduler is live: ', $self->scheduler()->current() !== null ? 'yes' : 'no', PHP_EOL;
});
?>
--EXPECT--
parallel workers are not implemented yet (see #7); construct the runtime with workers: 0 instead of 4
shared roots are not implemented yet (see #7)
shared roots are not implemented yet (see #7)
persisting objects into the shared arena is not implemented yet (see #7)
parallel workers are not implemented yet (see #7)
the local scheduler is live: yes
