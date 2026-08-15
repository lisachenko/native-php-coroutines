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

use Lisachenko\NativePhpCoroutines\Exception\ParallelTaskException;
use Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException;
use Lisachenko\NativePhpCoroutines\JoinHandleInterface;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;

/**
 * The pool: forks it, places work on it, hears when it speaks, and buries it.
 *
 * # Prefork, eagerly, in this order
 *
 * {@see self::start()} forks every worker at once, before any work exists, and the order around it
 * is load-bearing:
 *
 * 1. **arena and shared roots** — created before the fork so every child inherits them at the same
 *    address. This is the seam ticket #7 fills; see {@see self::start()}.
 * 2. **fork** — {@see ProcessWorker::fork()}, once per worker.
 * 3. **fibers** — created only afterwards, in each process for itself. Forking a process that
 *    already owns live fibers duplicates their C stacks into a state no child can resume.
 *
 * Forking lazily, on first use, would put step 2 after step 3 and break both invariants at once.
 *
 * # Two independent ways to learn a worker is gone
 *
 * `SIGCHLD` with `waitpid(WNOHANG)` keeps zombies from piling up: the handler reaps, and reaping is
 * what turns a defunct entry in the process table into a recorded exit status.
 *
 * The **control socket's EOF** is what drives the reaction. A dead worker's end of the pair closes,
 * the descriptor becomes permanently readable, and the poller calls back — inside the parent's
 * ordinary event loop, not inside a signal handler. That is where {@see self::bury()} decides
 * whether this was an orderly exit or a death, and where a death is turned into a
 * {@see WorkerCrashedException} that fails every slot the worker owed. **A result that can never
 * arrive must become a throw, never a permanent park.**
 */
final class WorkerSupervisor
{
    /** How long {@see self::bury()} will wait for a child that has closed its socket to be reaped. */
    private const float BURY_REAP_SECONDS = 0.5;

    /** @var array<int, ProcessWorker> */
    private array $workers = [];

    /** @var array<int, true> Workers already unwatched, closed and accounted for. */
    private array $buried = [];

    /** @var list<WorkerCrashedException> */
    private array $crashes = [];

    /**
     * Slot id => the arena address of the task dispatched under it, while it is still in flight.
     *
     * @var array<int, int>
     */
    private array $dispatched = [];

    private readonly SlotTable $slots;

    private int $cursor = 0;

    private bool $started = false;

    private bool $signalsInstalled = false;

    private int $ownerPid = 0;

    public function __construct(
        private readonly SchedulerInterface $scheduler,
        private readonly TaskDirectory $tasks = new PreforkTaskDirectory(),
        private readonly ?SharedArena $arena = null,
        ?SlotTable $slots = null,
    ) {
        // The table is shared with the runtime that owns it, because a process without a pool — a
        // worker — still has to be able to read and await slots the family allocated.
        $this->slots = $slots ?? new SlotTable($scheduler, $arena);
    }

    /**
     * Fork $count workers, right now.
     *
     * @param int                        $count     Workers to fork; at least one.
     * @param (\Closure(int): void)|null $afterFork Runs in each child straight after the fork,
     *                                              **before any scheduler or fiber exists**. This
     *                                              is where a preemptive runtime re-arms the child's
     *                                              interval timer (#5) — `setitimer` intervals are
     *                                              not inherited across `fork()`.
     * @param (\Closure(int, SchedulerInterface): void)|null $afterScheduler Runs in each child once
     *                                              its own scheduler exists and before the first
     *                                              coroutine is spawned on it. This is where a
     *                                              preemptive runtime binds a `Preemptor` to *that*
     *                                              scheduler; binding the parent's instead leaves
     *                                              the child silently unpreemptable.
     */
    public function start(int $count, ?\Closure $afterFork = null, ?\Closure $afterScheduler = null): void
    {
        if ($this->started) {
            throw new \LogicException('the worker pool has already been forked; start() is once per pool');
        }

        if ($count < 1) {
            throw new \InvalidArgumentException(sprintf('a pool needs at least one worker, got %d', $count));
        }

        // ---------------------------------------------------------------------------------
        // STEP 1 — the shared arena, the wake registry and every declared shared root exist by now:
        // the runtime builds them in its constructor, BEFORE this call. Children inherit them by
        // address, which is what makes a pointer written by one process meaningful in another.
        // Sealing the arena here closes registration: anything declared after this moment would be
        // private to whichever process declared it, so it is refused rather than half-created.
        // ---------------------------------------------------------------------------------

        $this->arena?->watchWith($this->scheduler);
        $this->arena?->sealBeforeFork();

        $this->ownerPid = posix_getpid();
        $this->installSignalHandling();

        // Registered before the first child exists, so even a fork that fails halfway leaves nothing
        // behind. It no-ops in the children, which inherit it along with everything else.
        $this->installSafetyNet();

        $this->started = true;

        // STEP 2 — fork.
        for ($id = 0; $id < $count; ++$id) {
            $worker = ProcessWorker::fork($id, $this->tasks, $afterFork, $afterScheduler, $this->arena);

            $this->workers[$id] = $worker;
        }

        // STEP 3 — fibers, from here on. The parent's scheduler may now spawn coroutines; each child
        // built its own inside WorkerChild::main().
        $this->watchAll();
    }

    /** @return array<int, ProcessWorker> */
    public function workers(): array
    {
        return $this->workers;
    }

    public function worker(int $id): ProcessWorker
    {
        return $this->workers[$id] ?? throw new \InvalidArgumentException(
            sprintf('there is no worker #%d in a pool of %d', $id, count($this->workers)),
        );
    }

    /** The result slots of this process; the supervisor completes them, join handles read them. */
    public function slots(): SlotTable
    {
        return $this->slots;
    }

    /**
     * Every worker death seen so far, oldest first.
     *
     * A waiter is failed with the crash directly; this is for the caller that had no waiter parked
     * and would otherwise never hear about it.
     *
     * @return list<WorkerCrashedException>
     */
    public function crashes(): array
    {
        return $this->crashes;
    }

    /**
     * Register every live worker's control socket with the poller.
     *
     * Called by {@see self::start()}, and again by any caller that has run a scheduler to completion
     * — a finished run discards the poller's registrations along with everything else pending.
     */
    public function watchAll(): void
    {
        $poller = $this->scheduler->poller();

        foreach ($this->workers as $worker) {
            $stream = $worker->readinessFd();

            if ($stream === null) {
                continue;
            }

            $poller->watchReadable($stream, function () use ($worker): void {
                $this->onWorkerReadable($worker);
            });
        }
    }

    /**
     * Place a task on a worker and return a handle on its result.
     *
     * @param int|null $worker Pin to this worker, or null to take the next one round-robin.
     */
    public function spawn(Task $task, ?int $worker = null): JoinHandleInterface
    {
        if (!$this->started) {
            throw new \LogicException('start() the pool before placing work on it');
        }

        $target = $this->place($worker);

        // With an arena this persists the task's object graph into shared memory and hands back a
        // real pointer; without one it resolves a task published before the fork. Either way the
        // task itself never travels — only this integer does.
        $address = $this->tasks->addressOf($task);

        $slot = $this->slots->open($target->id());

        $this->dispatched[$slot->id] = $address;

        try {
            $target->dispatch($slot->id, $address);
        } catch (\Throwable $failure) {
            // The worker went away between the placement and the write. The slot exists, so it must
            // be completed with the failure rather than left for a waiter to park on forever.
            $this->slots->completeWithError($slot->id, $failure);

            throw $failure;
        }

        return new JoinHandle($this->slots, $this->scheduler, $slot->id, $target->id());
    }

    /**
     * Walk the whole ladder: `SHUTDOWN` record, grace, `SIGTERM`, grace, `SIGKILL`.
     *
     * Every worker is pushed a rung at a time and the pool waits *once* per rung, so the total cost
     * is bounded by the three periods however many workers there are.
     *
     * @return array<int, ShutdownRung> Worker id => the rung that ended it.
     */
    public function shutdown(
        float $graceSeconds = 0.5,
        float $termSeconds = 0.5,
        float $killSeconds = 1.0,
    ): array {
        $rungs = [];

        foreach ($this->workers as $id => $worker) {
            $rungs[$id] = $worker->isAlive() ? ShutdownRung::SHUTDOWN : ShutdownRung::ALREADY_GONE;
        }

        // Rung 1 — ask.
        foreach ($this->workers as $worker) {
            if ($worker->isAlive()) {
                $worker->requestShutdown();
            }
        }

        $this->waitForAll($graceSeconds);

        // Rung 2 — SIGTERM the ones that did not listen.
        foreach ($this->workers as $id => $worker) {
            if ($worker->isAlive()) {
                $rungs[$id] = ShutdownRung::SIGTERM;
                $worker->signal(SIGTERM);
            }
        }

        $this->waitForAll($termSeconds);

        // Rung 3 — SIGKILL, which nothing can catch, block or ignore.
        foreach ($this->workers as $id => $worker) {
            if ($worker->isAlive()) {
                $rungs[$id] = ShutdownRung::SIGKILL;
                $worker->signal(SIGKILL);
            }
        }

        $this->waitForAll($killSeconds);

        foreach ($this->workers as $worker) {
            $this->bury($worker);
        }

        $this->uninstallSignalHandling();

        return $rungs;
    }

    private function place(?int $worker): ProcessWorker
    {
        if ($worker !== null) {
            $pinned = $this->worker($worker);

            if (!$pinned->isAlive()) {
                throw WorkerCrashedException::notRunning($worker);
            }

            return $pinned;
        }

        $count = count($this->workers);

        // Round-robin from wherever the last placement left off, skipping workers that have died.
        // With a healthy pool this is plain 0, 1, 2, …, 0 — the property the distribution test pins.
        for ($step = 0; $step < $count; ++$step) {
            $candidate = $this->workers[($this->cursor + $step) % $count] ?? null;

            if ($candidate !== null && $candidate->isAlive()) {
                $this->cursor = ($this->cursor + $step + 1) % $count;

                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf(
            'no live worker is left to take the task; all %d have died',
            $count,
        ));
    }

    private function onWorkerReadable(ProcessWorker $worker): void
    {
        foreach ($worker->receive() as $record) {
            $this->apply($worker, $record);
        }

        if ($worker->isControlEof()) {
            $this->bury($worker);
        }
    }

    private function apply(ProcessWorker $worker, ControlRecord $record): void
    {
        if ($record->opcode === Opcode::RESULT || $record->opcode === Opcode::PANIC) {
            // With an arena the record is pure signalling: the answer — a value or the address of a
            // shared error-info object — is already in the slot, and reading it from there is what
            // makes it a real PHP value rather than something rebuilt from bytes on a socket.
            if ($this->arena !== null) {
                $this->slots->refresh();
                $this->releaseTask($record->slotId);

                return;
            }

            if ($record->opcode === Opcode::RESULT) {
                $this->slots->completeWithValue($record->slotId, $record->value ?? TaggedRecord::nil());

                return;
            }

            $this->slots->completeWithError(
                $record->slotId,
                self::panicFor($worker->id(), $record->value->tag ?? Tag::NIL),
            );

            return;
        }

        // SPAWN and SHUTDOWN travel the other way; a WAKE or CLOSE that arrives here is a re-check
        // poke the arena's own wake socket already delivered. None is worth taking the parent down
        // over.
    }

    /** Let the directory reuse a class key once the task under it has finished. */
    private function releaseTask(int $slotId): void
    {
        $address = $this->dispatched[$slotId] ?? null;

        if ($address === null) {
            return;
        }

        unset($this->dispatched[$slotId]);

        if ($this->tasks instanceof ArenaTaskDirectory) {
            $this->tasks->releaseInFlight($address);
        }
    }

    /**
     * Turn a `PANIC` record's tag into the exception the waiter sees.
     *
     * The tag is the only thing that crossed. `NIL` means the task threw — the class, message and
     * trace live in the arena's shared error-info object once #7 lands, and are deliberately absent
     * from the wire until then. `STR`/`OBJ`/`ARR` mean the task *succeeded* but produced a value
     * that needs an arena address to travel.
     */
    private static function panicFor(int $workerId, Tag $tag): \Throwable
    {
        if ($tag->isAddress()) {
            return ValueCodec::needsArena($tag);
        }

        return new ParallelTaskException(
            'Throwable',
            'the class, message and trace of the original throwable travel in the arena\'s shared '
            . 'error-info object, which lands with #7 — they are never serialized onto the control '
            . 'socket',
            '',
            $workerId,
        );
    }

    /**
     * Account for a worker that has stopped talking: unwatch, close, reap, and decide what it was.
     *
     * Idempotent, because both the EOF path and {@see self::shutdown()} reach it.
     */
    private function bury(ProcessWorker $worker): void
    {
        if (isset($this->buried[$worker->id()])) {
            return;
        }

        $this->buried[$worker->id()] = true;

        $stream = $worker->readinessFd();

        if ($stream !== null) {
            $this->scheduler->poller()->unwatch($stream);
        }

        $worker->closeControl();
        $worker->waitForExit(self::BURY_REAP_SECONDS);

        // A worker killed *after* settling a slot still left a real answer behind, so shared memory
        // is read once more before anything is declared lost. What is genuinely unsettled becomes
        // the crash; a slot whose lock had to be recovered from the dead owner is never read as if
        // it were an answer, because refresh() only ever consumes a slot the substrate reports as
        // settled under that same lock.
        $this->slots->refresh();

        $pending = $this->slots->pendingSlotsOf($worker->id());

        // An orderly exit is one that was asked for *and* left nothing owed. A worker that was asked
        // to stop but still had slots open has abandoned them, and that is a crash for every waiter.
        if ($worker->wasShutdownRequested() && $pending === []) {
            return;
        }

        $crash = $this->arena?->lockRecovered() === true
            ? new WorkerCrashedException(
                $worker->id(),
                'it died holding an arena lock (EOWNERDEAD); the lock was recovered, but whatever it '
                . 'was writing is not an answer',
                $pending,
            )
            : $worker->crashException($pending);

        $this->crashes[] = $crash;

        $this->slots->failPendingOf($worker->id(), $crash);
    }

    private function waitForAll(float $seconds): void
    {
        $deadline = microtime(true) + max(0.0, $seconds);

        while (true) {
            $alive = false;

            foreach ($this->workers as $worker) {
                if ($worker->isAlive()) {
                    $alive = true;
                }
            }

            if (!$alive || microtime(true) >= $deadline) {
                return;
            }

            usleep(1000);
        }
    }

    private function installSignalHandling(): void
    {
        if ($this->signalsInstalled) {
            return;
        }

        // Asynchronous delivery: the handler runs at the next safe point rather than needing a
        // pcntl_signal_dispatch() sprinkled through the event loop. A signal arriving inside
        // stream_select() surfaces as EINTR, which the poller already treats as routine.
        pcntl_async_signals(true);

        pcntl_signal(SIGCHLD, function (): void {
            // Reaping only this pool's pids, never waitpid(-1): a supervisor that reaped anything
            // would steal the exit status of a child some other part of the program forked.
            foreach ($this->workers as $worker) {
                $worker->tryReap();
            }
        });

        $this->signalsInstalled = true;
    }

    private function uninstallSignalHandling(): void
    {
        if (!$this->signalsInstalled) {
            return;
        }

        pcntl_signal(SIGCHLD, SIG_DFL);

        $this->signalsInstalled = false;
    }

    /**
     * Last line of defence against orphans: kill anything still running when this process exits.
     *
     * The pid guard matters. A shutdown function registered before the fork is inherited by every
     * child, and a child running it would try to SIGKILL its own siblings using pids it does not
     * own — so it must only ever fire in the process that forked the pool.
     */
    private function installSafetyNet(): void
    {
        $owner = $this->ownerPid;

        register_shutdown_function(function () use ($owner): void {
            if (posix_getpid() !== $owner) {
                return;
            }

            foreach ($this->workers as $worker) {
                $worker->terminate();
            }
        });
    }
}
