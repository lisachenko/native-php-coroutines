--TEST--
A runtime without workers maps no arena and refuses the shared surface with the remedy named
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

include __DIR__ . '/../../vendor/autoload.php';

// workers: 0 is a promise, not a placeholder — no mmap, no FFI for the arena, no wake sockets. The
// shared surface is refused rather than half-composed, and every refusal says how to get it.
$runtime = new Runtime();

echo 'arena mapped: ', $runtime->arena() === null ? 'no' : 'yes', PHP_EOL;

$task = new class implements Task {
    public function run(TaskRuntime $runtime): mixed
    {
        return null;
    }
};

foreach ([
    static fn (): mixed => $runtime->declareShared('jobs', 'SharedChannel', 8),
    static fn (): mixed => $runtime->shared('jobs'),
    static fn (): mixed => $runtime->persist(new stdClass()),
    static fn (): mixed => $runtime->attachResult(1),
    static fn (): mixed => $runtime->spawnParallel($task),
] as $callable) {
    try {
        $callable();
    } catch (LogicException $refusal) {
        echo $refusal->getMessage(), PHP_EOL;
    }
}

// Layer 1 itself is fully available on the same runtime.
$runtime->run(static function (TaskRuntime $self): void {
    echo 'the local scheduler is live: ', $self->scheduler()->current() !== null ? 'yes' : 'no', PHP_EOL;
});
?>
--EXPECT--
arena mapped: no
shared roots need the shared arena, which a runtime only maps when it has workers; construct it with workers: N
shared roots need the shared arena, which a runtime only maps when it has workers; construct it with workers: N
persisting objects into the shared arena needs the shared arena, which a runtime only maps when it has workers; construct it with workers: N
result slots live in the shared arena; construct the runtime with workers: N
this runtime has no workers; construct it with workers: N to run tasks in parallel
the local scheduler is live: yes
