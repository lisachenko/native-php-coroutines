<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace Lisachenko\NativePhpCoroutines\Parallel;

use Lisachenko\NativePhpCoroutines\TaskRuntime;

/**
 * A unit of work that can run in another process.
 *
 * Deliberately an object rather than a closure. A closure carries bound variables, a scope and an
 * op_array that cannot be shared across a fork boundary in the general case, so closures are
 * rejected until the closure track lands; an object's state is ordinary properties, which the
 * arena can hold.
 *
 * Implementations must therefore keep their constructor state to what can cross a boundary:
 * scalars, strings, and references to values that are already shared. Anything else throws
 * {@see \Lisachenko\NativePhpCoroutines\Exception\NotShareableValueException} at spawn time, naming
 * the remedy.
 *
 * The task object itself is persisted into the arena; only a `SPAWN {slot id, task address}` record
 * crosses the control socket, and the worker attaches the task by address and runs it as an
 * ordinary local coroutine.
 */
interface Task
{
    /**
     * Do the work and return its result.
     *
     * The runtime passed in belongs to the *executing* process, so `shared()` and `persist()` here
     * operate on that worker's view of the arena — which is the same memory the spawner sees. It is
     * the narrow {@see TaskRuntime} surface on purpose: a task holds no way to start a second
     * runtime inside its worker or to declare a shared root only its own process would see.
     *
     * The return value travels back through a result slot per the tag contract: scalars inline,
     * strings arena-copied, shared objects by address. Returning something unshareable throws.
     */
    public function run(TaskRuntime $runtime): mixed;
}
