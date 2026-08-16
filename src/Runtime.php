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
use Lisachenko\NativePhpCoroutines\Parallel\ArenaTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\JoinHandle;
use Lisachenko\NativePhpCoroutines\Parallel\SharedArena;
use Lisachenko\NativePhpCoroutines\Parallel\SlotTable;
use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor;
use Lisachenko\NativePhpCoroutines\Preemption\Preemptor;
use Lisachenko\SharedData\Ipc\NotShareableValueException as SubstrateRefusal;
use Lisachenko\SharedData\NotPersistableException;

/**
 * The composition root of a process: Layer 1, Layer 2 and the parallel layer.
 *
 * A scheduler and its poller always; the preemptor only when asked for; the shared arena and a
 * worker pool only when `workers > 0`. A cooperative single-process runtime composes exactly what it
 * did before — no FFI binding, no engine hook, no shared mapping.
 *
 * # The order everything is created in is the design
 *
 * Workers must see the arena at the same address as the parent, and a fork only copies what already
 * exists — so anything shared has to be built before it:
 *
 * 1. **the arena, the wake registry and the result slots** — in this constructor, so they exist
 *    before anything else and every worker inherits them at the same address;
 * 2. **the shared roots and any shareable closure** — {@see self::declareShared()} and
 *    {@see self::registerSharedClosure()}, between construction and {@see self::run()};
 * 3. **the fork** — the first thing `run()` does, before the main coroutine exists;
 * 4. **the fibers** — after the fork, in each process for itself. A fiber owns a C stack, and
 *    forking a process that already has live fibers hands every child a copy of those stacks in a
 *    state no child can resume.
 *
 * Declaring a root after step 3 is refused with a message saying so. It is not a late binding: a
 * root created then exists only in the process that created it.
 *
 * # Preemption crosses the fork in two halves
 *
 * `setitimer` intervals are cleared by `fork()`, so each child re-arms its own clock — that is a
 * process-global concern and it happens immediately after the fork, before any fiber exists. The
 * *policy* is not process-global: a {@see Preemptor} answers `shouldPreempt()` by asking
 * `$scheduler->current()`, so each child gets its own, built against its own scheduler once that
 * scheduler exists. Handing a child the inherited parent preemptor arms the timer and leaves the
 * binding pointing at a scheduler that never runs anything — the child is then never preempted and
 * nothing anywhere says so.
 *
 * # What `run()` guarantees
 *
 * Go semantics, deliberately: when the main coroutine returns, the run is over. Coroutines still
 * queued, sleeping or parked on the poller are **discarded**, not awaited — a program that wants to
 * wait for its workers says so with a WaitGroup or a channel. An uncaught Throwable anywhere is a
 * panic: it ends the run and comes back out of `run()`.
 */
final class Runtime implements RuntimeInterface
{
    private readonly Scheduler $scheduler;

    private readonly ?Preemptor $preemptor;

    private readonly ?SharedArena $arena;

    private readonly ?WorkerSupervisor $supervisor;

    private readonly ?ArenaTaskDirectory $tasks;

    /** This process's view of the family's result slots; shared with the pool when there is one. */
    private readonly ?SlotTable $slots;

    private bool $forked = false;

    /**
     * @param int              $workers    Forked workers to run tasks on; 0 keeps this process alone.
     * @param bool             $preemptive Whether to force time slices on coroutines that do not yield.
     * @param float            $slice      Target slice length in seconds when $preemptive; a target,
     *                                     not a bound.
     * @param SharedArena|null $arena      Shared memory to adopt instead of creating one. This is how
     *                                     a forked worker's runtime binds the mapping it inherited;
     *                                     application code leaves it null.
     * @param int              $arenaSize  Bytes the whole family shares, when this runtime creates it.
     */
    public function __construct(
        private readonly int $workers = 0,
        private readonly bool $preemptive = false,
        private readonly float $slice = Preemptor::DEFAULT_SLICE_SECONDS,
        ?SharedArena $arena = null,
        int $arenaSize = SharedArena::DEFAULT_SIZE,
    ) {
        if ($workers < 0) {
            throw new \InvalidArgumentException(
                sprintf('a worker count cannot be negative, got %d', $workers),
            );
        }

        $this->scheduler = new Scheduler();
        $this->preemptor = $preemptive ? new Preemptor($this->scheduler, $slice) : null;

        $this->scheduler->attachPreemptor($this->preemptor);

        // Created here and nowhere later: everything the family shares has to exist before the first
        // fork, and run() forks before it spawns anything.
        $this->arena = $arena ?? ($workers > 0 ? new SharedArena($arenaSize, max(32, $workers + 8)) : null);

        if ($this->arena === null) {
            $this->supervisor = null;
            $this->tasks      = null;
            $this->slots      = null;

            return;
        }

        $this->arena->watchWith($this->scheduler);

        $this->tasks      = new ArenaTaskDirectory($this->arena);
        $this->slots      = new SlotTable($this->scheduler, $this->arena);
        $this->supervisor = $workers > 0
            ? new WorkerSupervisor($this->scheduler, $this->tasks, $this->arena, $this->slots)
            : null;
    }

    public function declareShared(string $name, string $class, int $capacity = 0): void
    {
        $this->requireArena('shared roots need')->declareShared($name, $class, $capacity);
    }

    public function shared(string $name): mixed
    {
        return $this->requireArena('shared roots need')->shared($name);
    }

    public function persist(object $object): object
    {
        $arena = $this->requireArena('persisting objects into the shared arena needs');

        try {
            return $arena->persist($object);
        } catch (SubstrateRefusal | NotPersistableException $refused) {
            throw new NotShareableValueException(sprintf(
                'an instance of %s cannot be cloned into the shared arena: %s. Its properties must '
                . 'be scalars, arena strings, other shared objects or a SharedArray — a plain array '
                . 'property, a resource or a post-fork closure has no address-shaped form',
                $object::class,
                $refused->getMessage(),
            ), 0, $refused);
        }
    }

    /**
     * Make a closure shareable, by provenance — it must be compiled before the fork.
     *
     * The only closures a worker may ever be handed. Acceptance is the registration itself and never
     * an inspection of the object: a post-fork closure at a stale address is indistinguishable by
     * shape from a legitimate one, and on PHP 8.5 the substrate spikes watched such an address
     * execute the *wrong function* instead of failing.
     */
    public function registerSharedClosure(string $name, \Closure $closure): void
    {
        $this->requireArena('shared closures need')->registerSharedClosure($name, $closure);
    }

    /**
     * The closure registered under this name, resolved in whichever process asks.
     *
     * The record lives in the arena and the closure object lives wherever it was compiled — which,
     * for a pre-fork registration, is the same address in every worker of the family.
     */
    public function sharedClosure(string $name): \Closure
    {
        return $this->requireArena('shared closures need')->closures()->closure($name);
    }

    public function spawnParallel(Task $task, ?int $worker = null): JoinHandleInterface
    {
        $supervisor = $this->supervisor ?? throw new \LogicException(
            'this runtime has no workers; construct it with workers: N to run tasks in parallel',
        );

        if (!$this->forked) {
            throw new \LogicException(
                'the worker pool is forked by run(), so a task can only be spawned from inside it',
            );
        }

        return $supervisor->spawn($task, $worker);
    }

    /**
     * Publish a task so every worker can resolve it by address, **before** the fork.
     *
     * The cheapest route there is: a published task reaches the workers through fork inheritance,
     * so nothing is cloned and two tasks of one class never collide. Spawning a task that was not
     * published clones its graph into the arena instead, where the substrate's registry is keyed by
     * class and only one graph per class can be live at a time.
     */
    public function publishTask(Task $task): void
    {
        if ($this->forked) {
            throw new \LogicException(
                'a task can only be published before the workers fork; after that it is resolved by '
                . 'arena address instead, which spawnParallel() does on its own',
            );
        }

        $this->tasks?->register($task) ?? throw new \LogicException(
            'this runtime has no workers; construct it with workers: N to publish tasks',
        );
    }

    public function attachResult(int $slotId): JoinHandleInterface
    {
        $slots = $this->slots ?? throw new \LogicException(
            'result slots live in the shared arena; construct the runtime with workers: N',
        );

        $slot = $slots->adopt($slotId);

        return new JoinHandle($slots, $this->scheduler, $slot->id, $slot->workerId);
    }

    public function run(\Closure $main): void
    {
        $this->preemptor?->arm();

        try {
            // STEP 3 — the fork, before a single fiber exists in this process. Everything shared was
            // built in the constructor and declared before now.
            $this->supervisor?->start($this->workers, $this->rearmClock(...), $this->attachPreemptor(...));
            $this->forked = $this->supervisor !== null;

            $mainCoroutine = $this->scheduler->spawn(fn(): mixed => $main($this));

            $this->scheduler->runUntil($mainCoroutine);
        } finally {
            // Drains every preempted coroutine out of the interrupt callback before the timer
            // stops, then stops it. Both halves matter even on the panic path: what is left
            // suspended inside that callback is fatal at shutdown, not merely leaked.
            $this->preemptor?->disarm();
            $this->supervisor?->shutdown();
        }
    }

    public function scheduler(): SchedulerInterface
    {
        return $this->scheduler;
    }

    /** How many workers this runtime forks. */
    public function workers(): int
    {
        return $this->workers;
    }

    /** Whether this runtime forces time slices on its coroutines. */
    public function isPreemptive(): bool
    {
        return $this->preemptive;
    }

    /**
     * The preemptor, or null on a cooperative runtime.
     *
     * This is the handle application code needs for
     * {@see Preemptor::enterCriticalSection()}/{@see Preemptor::leaveCriticalSection()} — the only
     * way to mark a stretch of code that must not lose the CPU halfway through.
     */
    public function preemptor(): ?Preemptor
    {
        return $this->preemptor;
    }

    /** The shared memory of this family, or null on a single-process runtime. */
    public function arena(): ?SharedArena
    {
        return $this->arena;
    }

    /** The pool, or null on a single-process runtime. */
    public function supervisor(): ?WorkerSupervisor
    {
        return $this->supervisor;
    }

    /**
     * Seam one, in the child, before any scheduler or fiber exists.
     *
     * Only the clock: `fork()` clears interval timers, and a child of a preemptive parent runs
     * cooperatively — silently — until one is armed again. Nothing here may touch a scheduler,
     * because the child does not have one yet.
     */
    private function rearmClock(int $workerId): void
    {
        $this->preemptor?->clock()->rearmAfterFork();
    }

    /**
     * Seam two, in the child, once its own scheduler exists and before the first coroutine runs.
     *
     * A brand-new {@see Preemptor} bound to *this* scheduler. Re-arming the inherited one instead is
     * the trap this split exists for: it arms the timer correctly and leaves `shouldPreempt()`
     * asking the parent's scheduler, which never has a current coroutine in this process, so the
     * answer is false forever and the worker is never preempted.
     */
    private function attachPreemptor(int $workerId, SchedulerInterface $scheduler): void
    {
        if (!$this->preemptive || !$scheduler instanceof Scheduler) {
            return;
        }

        $preemptor = new Preemptor($scheduler, $this->slice);

        $scheduler->attachPreemptor($preemptor);
        $preemptor->arm();
    }

    /** @param string $subject The refused feature, verb included, e.g. "shared roots need". */
    private function requireArena(string $subject): SharedArena
    {
        return $this->arena ?? throw new \LogicException(sprintf(
            '%s the shared arena, which a runtime only maps when it has workers; construct it '
            . 'with workers: N',
            $subject,
        ));
    }
}
