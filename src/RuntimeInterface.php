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

/**
 * The composition root: configuration, the process's scheduler, and the parallel surface.
 *
 * This is an interface rather than the concrete `Runtime` because {@see Task::run()} receives it,
 * and a task written against a concrete class could not be type-checked against a test double or a
 * future runtime variant. The shipped implementation is `Runtime`.
 */
interface RuntimeInterface
{
    /**
     * Declare a named shared root, to be created **before** the workers fork.
     *
     * A root is addressed, not named, once it is in the arena, so it is only usable by a process
     * that sees it at the same virtual address. Forking is how this runtime arranges that today: a
     * child inherits the parent's mappings, so a root created before the fork is at the same address
     * everywhere, and one created afterwards exists in a single process. Declaring a root after
     * {@see self::run()} has forked is therefore an error, not a late binding.
     *
     * @param class-string $class Shared type to instantiate, e.g. SharedArray or SharedChannel.
     */
    public function declareShared(string $name, string $class, int $capacity = 0): void;

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

    /**
     * Run the main coroutine to completion.
     *
     * Go semantics: when main returns, coroutines that are still pending are **discarded**, not
     * awaited. An uncaught Throwable anywhere is a panic — it terminates the run and is rethrown
     * out of this call.
     *
     * @param \Closure(RuntimeInterface): mixed $main
     */
    public function run(\Closure $main): void;

    /** This process's scheduler. */
    public function scheduler(): SchedulerInterface;
}
