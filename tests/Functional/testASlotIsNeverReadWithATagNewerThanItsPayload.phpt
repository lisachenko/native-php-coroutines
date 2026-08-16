--TEST--
A result slot read under contention never shows a tag from one generation and a payload from another
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Tests\Support\SettleSlotsTask;
use Lisachenko\NativePhpCoroutines\Timer;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';
include __DIR__ . '/../Support/shared.php';

// A 16-byte record is NOT read atomically: the substrate spikes measured ~1.3 % of unlocked reads
// seeing a payload word and a tag word from different writes. A slot is therefore only safe under
// one of two disciplines, never a mix of them — the whole access under the slot's mutex, or
// payload-first-tag-last with the tag as the publication flag.
//
// This runtime takes the first: every read goes through the substrate's readSlot(), which holds the
// slot mutex across {state, tag, payload} and materializes the value only after releasing it. This
// test is that discipline under contention rather than on paper: one process settles a run of slots
// with STR values while this one reads all of them, thousands of times over. A torn read gives a STR
// tag with the previous generation's pointer, which is a wrong string or a dereference of something
// that is not a zend_string at all.
const SLOTS = 24;

$runtime = new Runtime(workers: 1, arenaSize: 32 << 20);

$runtime->run(static function (TaskRuntime $self): void {
    Timer::after(20.0, static function (): void {
        throw new RuntimeException('deadline: the slots were never all settled');
    });

    $arena = $self->arena();
    $table = $arena?->slotTable();

    if ($table === null) {
        throw new RuntimeException('the runtime mapped no arena');
    }

    $ids = [];
    for ($index = 0; $index < SLOTS; ++$index) {
        $ids[] = $table->allocateSlot();
    }

    $self->spawnParallel(new SettleSlotsTask($ids, 'generation-'));

    $torn      = 0;
    $observed  = 0;
    $settled   = [];
    $deadline  = microtime(true) + 10.0;

    // Keeps reading well past the last settlement: the interesting window is *while* the worker is
    // writing, and stopping at the first complete pass would sample it barely at all.
    while ((count($settled) < SLOTS || $observed < SLOTS * 50) && microtime(true) < $deadline) {
        foreach ($ids as $index => $slotId) {
            $result = $table->readSlot($slotId);

            if ($result->isPending()) {
                continue;
            }

            ++$observed;

            // The tag and the payload have to belong to the same write, every single time.
            if ($result->value !== 'generation-' . $index) {
                ++$torn;
            }

            $settled[$slotId] = true;
        }
    }

    echo 'slots settled: ', count($settled), PHP_EOL;
    echo 'reads of settled slots: ', $observed > SLOTS ? 'many' : 'too few to prove anything', PHP_EOL;
    echo 'reads that disagreed with their tag: ', $torn, PHP_EOL;
});

echo 'children left: ', parallelChildrenLeft(), PHP_EOL;
?>
--EXPECT--
slots settled: 24
reads of settled slots: many
reads that disagreed with their tag: 0
children left: none
