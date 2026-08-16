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
 * Fixtures for the shared-arena suite: shareable classes and the tasks that exercise them.
 *
 * Every class in here is loaded by the `include` that pulls this file in, which happens **before**
 * the runtime forks its pool. That is not tidiness: a shared clone carries one `zend_class_entry`
 * for the whole family, and a class first autoloaded inside one worker lands at an address no
 * sibling can follow.
 *
 * The tasks are ordinary classes rather than closures because a closure may only cross a worker
 * boundary if it was registered before the fork barrier — and because a `Task` is data, which is
 * exactly what the value contract accepts.
 */
declare(strict_types=1);

namespace Lisachenko\NativePhpCoroutines\Tests\Support;

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Parallel\SharedArena;
use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\StreamPoller;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

/**
 * A shared object with scalar and string properties — the shape the arena can hold.
 *
 * No plain-array property anywhere: the engine grows a HashTable into the private heap of whichever
 * process filled it and writes that private pointer into the shared struct before it aborts, so a
 * shared plain array is silent corruption rather than a crash.
 */
final class SharedCounter
{
    public int $value = 0;

    public string $label = '';

    public int $touchedBy = 0;
}

/** Hands back whatever it was built with — one task per value, published before the fork. */
final class EchoTask implements Task
{
    public function __construct(private readonly mixed $value) {}

    public function run(TaskRuntime $runtime): mixed
    {
        return $this->value;
    }
}

/** Resolves a named shared root inside the worker and returns it. */
final class SharedRootTask implements Task
{
    public function __construct(private readonly string $name) {}

    public function run(TaskRuntime $runtime): mixed
    {
        return $runtime->shared($this->name);
    }
}

/**
 * Mutates a shared object through the synchronized write path and hands the same instance back.
 *
 * `mutableHandle()` rather than `$object->prop = …`: a direct property write is legal and visible
 * for scalars, but it is **unsynchronized** — the object is rewired to `std_object_handlers`, so
 * there is no write hook to take the stripe lock. A string written that way would store a
 * request-heap pointer no sibling can follow. The handle comes straight off the task surface:
 * mutation is what tasks do, so no downcast to the concrete runtime is needed to reach it.
 */
final class MutateSharedTask implements Task
{
    public function __construct(
        private readonly string $root,
        private readonly int $value,
        private readonly string $label,
    ) {}

    public function run(TaskRuntime $runtime): mixed
    {
        $counter = $runtime->shared($this->root);

        if (!is_object($counter)) {
            throw new \LogicException('the shared counter root is not available in this worker');
        }

        $handle = $runtime->mutableHandle($counter);
        $handle->writeScalar('value', $this->value);
        $handle->writeString('label', $this->label);
        $handle->writeScalar('touchedBy', posix_getpid());

        return $counter;
    }
}

/**
 * Calls a closure the parent registered before the fork barrier, inside a worker.
 *
 * The closure travels as the address of its **provenance record**, never as code and never as
 * bytes: a pre-fork closure already exists at the same address in every process of the family, so
 * there is nothing to copy. Registration is the entire acceptance test — no inspection of the
 * object could tell a legitimate address from a stale one holding a different, perfectly valid
 * `Closure`.
 */
final class SharedClosureTask implements Task
{
    public function __construct(private readonly string $name, private readonly int $argument) {}

    public function run(TaskRuntime $runtime): mixed
    {
        return ($runtime->sharedClosure($this->name))($this->argument);
    }
}

/**
 * Settles a run of result slots with address-shaped values, as fast as the worker can.
 *
 * The point is contention: while this runs, another process is reading the same slots over and over.
 * A record in shared memory is not read atomically — roughly 1.3 % of *unlocked* 16-byte reads in the
 * substrate spikes saw a tag and a payload from different generations — so a reader that ever sees a
 * `STR` tag with the previous generation's pointer would hand back the wrong string, or dereference
 * something that is not a `zend_string` at all.
 */
final class SettleSlotsTask implements Task
{
    /** @param list<int> $slotIds */
    public function __construct(private readonly array $slotIds, private readonly string $prefix) {}

    public function run(TaskRuntime $runtime): mixed
    {
        // A deliberate downcast: this rig settles slots by hand, which is machinery the task
        // surface intentionally does not carry. Real tasks never need the concrete runtime.
        $arena = $runtime instanceof \Lisachenko\NativePhpCoroutines\Runtime ? $runtime->arena() : null;

        if ($arena === null) {
            throw new \LogicException('this worker has no arena');
        }

        foreach ($this->slotIds as $index => $slotId) {
            $arena->slotTable()->complete($slotId, $this->prefix . $index);
        }

        return count($this->slotIds);
    }
}

/** Ends in an uncaught throwable, which the worker turns into a shared error-info object. */
final class SharedPanicTask implements Task
{
    public function run(TaskRuntime $runtime): mixed
    {
        throw new \DomainException('the parallel task exploded');
    }
}

/**
 * Parks briefly on the worker's scheduler, then hands back the string it was built with.
 *
 * The payload is the graph: it lives in this task's own arena clone as an arena string, so the
 * value coming back intact is direct evidence the graph was neither superseded nor released while
 * a second instance of this same class was persisted and run concurrently. The properties are
 * public so the spawner can read them back through shared memory while the task is still running.
 */
final class NapThenEchoTask implements Task
{
    public function __construct(
        public readonly float $seconds,
        public readonly string $payload,
    ) {}

    public function run(TaskRuntime $runtime): mixed
    {
        Coroutine::sleep($this->seconds);

        return $this->payload;
    }
}

/** Panics with the message it was built with, so each instance's panic is distinguishable. */
final class PanicWithMessageTask implements Task
{
    public function __construct(public readonly string $message) {}

    public function run(TaskRuntime $runtime): mixed
    {
        throw new \RuntimeException($this->message);
    }
}

/**
 * Panics with a DomainException, so a concurrent panic differs from {@see PanicWithMessageTask}'s
 * by class and by the task frame in its trace — not only by message.
 */
final class PanicWithDomainErrorTask implements Task
{
    public function __construct(public readonly string $message) {}

    public function run(TaskRuntime $runtime): mixed
    {
        throw new \DomainException($this->message);
    }
}

/** Pushes a bounded number of values onto a named shared channel. */
final class SharedSendTask implements Task
{
    public function __construct(
        private readonly string $root,
        private readonly int $count,
        private readonly string $prefix = 'v',
    ) {}

    public function run(TaskRuntime $runtime): mixed
    {
        $channel = $runtime->shared($this->root);

        if (!$channel instanceof \Lisachenko\NativePhpCoroutines\ChannelInterface) {
            throw new \LogicException('the shared channel root is not available in this worker');
        }

        for ($index = 0; $index < $this->count; ++$index) {
            $channel->send($this->prefix . $index);
        }

        return $this->count;
    }
}

/**
 * Awaits a result slot the *parent* opened, from inside a worker.
 *
 * The slot id is the whole handle: the state lives in shared memory, so a process that never heard
 * of the worker that will settle it can still park on it and read its value.
 */
final class AwaitSlotTask implements Task
{
    public function __construct(private readonly int $slotId) {}

    public function run(TaskRuntime $runtime): mixed
    {
        return $runtime->attachResult($this->slotId)->await();
    }
}

/**
 * A call-free loop next to a ticker, inside one worker — the preemption probe.
 *
 * The loop body contains no call, no yield and no sleep, so cooperatively it owns the worker's CPU
 * until its last iteration and the ticker never runs. The value that comes back is how many ticks
 * the ticker managed *while the loop was still running*: 0 means the worker was not preempted, and
 * a test asserting only that the timer is armed would not have noticed.
 */
final class PreemptionProbeTask implements Task
{
    public function __construct(private readonly int $iterations = 4_000_000) {}

    public function run(TaskRuntime $runtime): mixed
    {
        $state                    = new \stdClass();
        $state->ticks             = 0;
        $state->ticksSeenByTheLoop = -1;

        $iterations = $this->iterations;

        Coroutine::spawn(static function () use ($state, $iterations): void {
            $sum = 0;

            for ($index = 0; $index < $iterations; ++$index) {
                $sum += $index % 7;
            }

            $state->ticksSeenByTheLoop = $state->ticks;
        });

        Coroutine::spawn(static function () use ($state): void {
            for ($round = 0; $round < 10_000; ++$round) {
                ++$state->ticks;
                Coroutine::yield();
            }
        });

        // Parks this task on the worker's own scheduler until the loop has finished, without ever
        // becoming runnable itself — a yield loop here would starve the very timer under test.
        for ($round = 0; $round < 200; ++$round) {
            if ($state->ticksSeenByTheLoop >= 0) {
                break;
            }

            Coroutine::sleep(0.02);
        }

        return $state->ticksSeenByTheLoop;
    }
}

/**
 * Reports how many times the executing worker's own poller woke while the task did nothing.
 *
 * A worker waiting for its next inbox record is the state a pool spends most of its life in, and it
 * is a *preemptive* idle whenever the pool is: each child arms its own slice timer after the fork.
 * The count is taken in the worker and returned as an int, because "did the child stop the clock
 * for its own idle poll?" cannot be observed from the parent — the parent's poller is a different
 * poller and the child's timer is a different timer.
 */
final class ReportsIdlePollerWakeupsTask implements Task
{
    public function __construct(private readonly float $seconds = 1.0) {}

    public function run(TaskRuntime $runtime): mixed
    {
        $poller = $runtime->scheduler()->poller();

        if (!$poller instanceof StreamPoller) {
            return -1;
        }

        $before = $poller->wakeups();

        // Nothing else is runnable in this worker, so the whole sleep is spent in one blocking poll
        // over the control socket and the arena's wake pipe.
        Coroutine::sleep($this->seconds);

        return $poller->wakeups() - $before;
    }
}

/**
 * Reports whether the executing process can reach its own preemptor through the task surface.
 *
 * The wart this pins down: the preemptor of a forked worker is built after the fork, against the
 * child's own scheduler, so a runtime accessor that reads constructor state answers null in every
 * worker of a preemptive pool — and task code then has no way to mark a critical section. The
 * truthful binding lives on the scheduler, and this task proves the surface reads it from there.
 */
final class ReportsPreemptorTask implements Task
{
    public function run(TaskRuntime $runtime): mixed
    {
        return $runtime->preemptor() !== null && $runtime->preemptor()->isArmed();
    }
}

/**
 * Takes an arena lock and never gives it back: the worker is meant to be killed while holding it.
 *
 * Written as a bounded spin rather than a park on purpose — the point is that the process dies with
 * the mutex held, which is what raises `EOWNERDEAD` for the next taker.
 */
final class HoldArenaLockTask implements Task
{
    public function __construct(private readonly string $root) {}

    public function run(TaskRuntime $runtime): mixed
    {
        // A deliberate downcast: taking a raw stripe lock in order to die holding it is machinery
        // the task surface intentionally does not carry. Real tasks never need the concrete runtime.
        $arena = $runtime instanceof \Lisachenko\NativePhpCoroutines\Runtime ? $runtime->arena() : null;

        if ($arena === null) {
            throw new \LogicException('this worker has no arena');
        }

        $counter = $runtime->shared($this->root);

        if (!is_object($counter)) {
            throw new \LogicException('the shared counter root is not available in this worker');
        }

        $address = $arena->addressOf($counter);
        $stripe  = $arena->arena()->stripeFor($address);

        $arena->arena()->lockStripe($stripe);

        // Deliberately never unlocked: the supervisor kills this worker, and the next process to
        // take this stripe inherits it as EOWNERDEAD.
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            usleep(5_000);
        }

        return 0;
    }
}

/**
 * The address of the result-slot table's own dedicated mutex.
 *
 * A stripe lock is the wrong lock to die on if the point is to watch the *supervisor* recover one:
 * nothing the parent does while burying a worker takes a stripe, and stripe recovery is not tracked
 * by anything {@see \Lisachenko\NativePhpCoroutines\Parallel\SharedArena::lockRecovered()} can
 * consult. The lock the supervisor really depends on is the result-slot table's, which every
 * `refresh()` takes — and which is exactly the lock a worker dying mid-`settle()` would hand on.
 *
 * The substrate documents the table header as `capacity | next slot | mutex address | reserved`, so
 * the mutex address is the third word. Reading it here rather than adding an accessor to the runtime
 * keeps a test-only need out of production; the containment check turns a future layout change into
 * a named failure instead of a lock taken on somebody else's bytes.
 */
function resultSlotTableMutex(SharedArena $arena): int
{
    $raw     = $arena->arena();
    $address = $raw->readWord($arena->slotTable()->address() + 2 * 8);

    if (!$raw->contains($address, 8)) {
        throw new \LogicException(
            'the result-slot table header no longer keeps its mutex address in the third word; '
            . 'this helper is reading the substrate layout and has to follow it',
        );
    }

    return $address;
}

/**
 * Takes the result-slot table's lock and never gives it back, so the supervisor recovers it.
 *
 * The worker is meant to be SIGKILLed while inside this critical section. Its death makes the very
 * lock the parent's `refresh()` takes `EOWNERDEAD`, which is the state
 * {@see \Lisachenko\NativePhpCoroutines\Parallel\WorkerSupervisor} turns into a crash that says the
 * lock was recovered rather than one that only names the signal.
 *
 * A bounded spin, not a park: the process has to still be holding the mutex when it dies.
 */
final class HoldResultSlotLockTask implements Task
{
    public function run(TaskRuntime $runtime): mixed
    {
        // A deliberate downcast, as in HoldArenaLockTask: taking a raw lock in order to die holding
        // it is machinery the task surface intentionally does not carry.
        $arena = $runtime instanceof \Lisachenko\NativePhpCoroutines\Runtime ? $runtime->arena() : null;

        if ($arena === null) {
            throw new \LogicException('this worker has no arena');
        }

        $arena->arena()->lockMutexAt(resultSlotTableMutex($arena));

        // Never unlocked. Nothing else may run in this worker either — every result-slot operation
        // would take the lock this fiber is already holding.
        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline) {
            usleep(5_000);
        }

        return 0;
    }
}
