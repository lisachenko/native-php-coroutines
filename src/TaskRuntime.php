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

namespace Lisachenko\NativePhpCoroutines;

use Lisachenko\NativePhpCoroutines\Exception\NotShareableValueException;
use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Preemption\Preemptor;
use Lisachenko\SharedData\SharedMutationException;
use Lisachenko\SharedData\SharedObjectHandle;

/**
 * The runtime as seen from inside it: what executing code may do.
 *
 * Two kinds of code receive this type, and they are the same kind. A parallel {@see Task} gets it in
 * `run()`, in the worker that executes it. The main closure gets it from {@see Runtime::run()} — and
 * the main coroutine runs *after* the fork, in exactly the regime a task runs in, so it has exactly
 * the same legitimate surface. Everything absent from this interface is absent because calling it
 * from executing code is a bug the type system can now catch: `Runtime::run()` from inside a
 * coroutine would start a second runtime inside the first, and `Runtime::declareShared()` after the
 * fork would create a root only one process can see. Configuration and lifecycle — declaring roots,
 * registering closures, publishing tasks, running — live on the concrete {@see Runtime} and happen
 * before the fork, where only the code that constructed the runtime can reach them.
 *
 * The primitives are deliberately not manufactured here. A {@see Channel}, {@see Select},
 * {@see Context}, {@see Sync\WaitGroup}, {@see Sync\Once} or {@see Sync\Mutex} is constructed on
 * {@see self::scheduler()} — one rule for every primitive, present and future, and the wiring stays
 * visible at the construction site. That is also why a shared channel substitutes for a local one
 * without touching calling code: both reach the caller as a value, never as something only the
 * runtime can make.
 */
interface TaskRuntime
{
    /**
     * Look up a shared root by name.
     *
     * Resolution is by address through the per-process side table, so the value that comes back is
     * the same object every other process sees — not a copy of it.
     */
    public function shared(string $name): mixed;

    /**
     * Clone an object graph into the shared arena and return the shared instance.
     *
     * @template TObject of object
     * @param TObject $object
     * @return TObject
     * @throws NotShareableValueException When some part of the graph cannot live in the arena.
     */
    public function persist(object $object): object;

    /**
     * The synchronized write path to one shared object, by instance or by arena address.
     *
     * A direct property write on a shared object is legal for scalars but **unsynchronized** — the
     * object runs on `std_object_handlers`, so no write hook takes the stripe lock, and a string
     * written that way stores a request-heap pointer no sibling can follow. Every cross-process
     * mutation goes through the handle this returns.
     *
     * Accepting an address matters: the arena address is the only cross-process identity a shared
     * object has, so an address received over a channel is enough to mutate the object it names.
     *
     * @param object|int $shared The shared instance, or its arena address.
     * @throws SharedMutationException When the target is not a shared mutable object of this family.
     */
    public function mutableHandle(object|int $shared): SharedObjectHandle;

    /**
     * The closure registered under this name before the fork, resolved in whichever process asks.
     *
     * The record lives in the arena and the closure object lives wherever it was compiled — which,
     * for a pre-fork registration, is the same address in every process of the family. Registration
     * itself is configuration and stays on {@see Runtime::registerSharedClosure()}: it only means
     * anything before the fork, and resolution is the only half a task legitimately does.
     */
    public function sharedClosure(string $name): \Closure;

    /**
     * Hand a task to a worker and get a handle on its eventual result.
     *
     * The task is persisted into the arena and only a `SPAWN` record crosses the socket. Nothing is
     * serialized, here or on the way back.
     *
     * @param int|null $worker Worker to pin the task to; null distributes round-robin.
     */
    public function spawnParallel(Task $task, ?int $worker = null): JoinHandleInterface;

    /**
     * Take a handle on a result slot this process did not open.
     *
     * A slot id is the whole handle. The state lives in shared memory, so any process of the family
     * can read it, park on it and take its value — including a worker awaiting a task the parent
     * spawned somewhere else, and including a process that only learned the id afterwards.
     */
    public function attachResult(int $slotId): JoinHandleInterface;

    /** This process's scheduler — what every local primitive is constructed on. */
    public function scheduler(): SchedulerInterface;

    /**
     * The preemptor interrupting this process's coroutines, or null when it runs cooperatively.
     *
     * This is the handle for {@see Preemptor::enterCriticalSection()} /
     * {@see Preemptor::leaveCriticalSection()} — the only way to mark a stretch of code that must
     * not lose the CPU halfway through. The answer is per process, not per family: a worker in a
     * preemptive pool gets the preemptor built against its *own* scheduler after the fork, which is
     * why this lives on the execution surface and not only on the composition root.
     */
    public function preemptor(): ?Preemptor;
}
