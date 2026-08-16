<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * The coroutine from issue #18, in a process of its own.
 *
 * `while (true) { $x++; }` never returns and never parks, so the scheduler can never resume it out
 * of the preemption callback. This script is not a test: it is the process whose *ending* two tests
 * observe from the outside ({@see superviseChildProcess()}), because the ending is the behaviour —
 * it must be prompt, it must say which coroutine and which line, and it must not be the engine
 * destroying a suspended fiber.
 *
 * Every line it prints is an observation the supervising test greps for.
 */
declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Exception\UndrainableCoroutineException;
use Lisachenko\NativePhpCoroutines\Runtime;

require __DIR__ . '/../../vendor/autoload.php';

// Registered before the runtime arms preemption, so the runtime's own shutdown handler is queued
// after this one: if the diagnosis were to terminate the process the moment it is produced, this
// line would go missing.
register_shutdown_function(static function (): void {
    echo 'CHILD: a shutdown function registered before the runtime still ran', PHP_EOL;
});

$runtime = new Runtime(preemptive: true);

try {
    $runtime->run(static function (): void {
        Coroutine::spawn(static function (): void {
            $x = 0;

            while (true) {
                $x++;
            }
        });

        // Hand the CPU over once, so the runaway is running when main returns. From there Go
        // semantics discard everything still pending — except this one, which cannot be.
        Coroutine::yield();
    });

    echo 'CHILD: run() returned with no diagnosis', PHP_EOL;
} catch (UndrainableCoroutineException $diagnosis) {
    echo 'CHILD: run() threw ', $diagnosis::class, PHP_EOL;

    foreach ($diagnosis->stragglers() as $straggler) {
        printf(
            "CHILD: straggler #%d spawned at %s after %d resume(s)\n",
            $straggler['id'],
            $straggler['origin'],
            $straggler['resumes'],
        );
    }

    echo $diagnosis->getMessage(), PHP_EOL;
}

echo 'CHILD: reached the end of the script', PHP_EOL;
