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

use Lisachenko\NativePhpCoroutines\Exception\DeadlockException;
use Lisachenko\NativePhpCoroutines\Preemption\Preemptor;

/**
 * The cooperative scheduler of one process: a FIFO run queue, a timer heap, and one poller.
 *
 * # The idle turn
 *
 * Everything interesting happens when the run queue empties. The scheduler then, in order:
 *
 * 1. fires every timer whose deadline has passed — if any fired, somebody is runnable again;
 * 2. computes the earliest remaining deadline and hands it to {@see PollerInterface::poll()} as a
 *    timeout, so a program that is only sleeping blocks in the kernel instead of spinning;
 * 3. and if there is neither a deadline nor a registered descriptor, concludes that nothing can
 *    ever make a coroutine runnable again — a deadlock.
 *
 * A coroutine parked as *externally wakeable* is excluded from that conclusion: its wakeup was
 * never the scheduler's to produce. That exclusion is what keeps an idle server from reporting
 * itself deadlocked every time it has nothing to do.
 */
final class Scheduler implements SchedulerInterface
{
    /**
     * Wall clock one drain attempt may spend before it starts reporting stragglers.
     *
     * A ceiling on resumes alone cannot bound the wait, because a single resume is not itself
     * time-bounded: preemption happens between opcodes, and one `sort()` over four million integers
     * defers the next slice by around two seconds (spike S4). So the drain carries a clock as well.
     */
    public const float DEFAULT_DRAIN_BUDGET_SECONDS = 1.0;

    /**
     * Resumes one coroutine may be given, per drain attempt, before it is reported.
     *
     * A coroutine that is going to cooperate does it on the first resume, or within the handful it
     * takes to finish the loop it was interrupted in — the drain of a 2M-iteration loop at a 2 ms
     * slice takes six (spike S7's control run). Sixty-four is an order of magnitude above that, and
     * it is the floor of the budget as well as its ceiling: **every coroutine is resumed at least
     * once**, so a machine so loaded that the wall clock expires before anything ran cannot produce
     * a straggler report about a coroutine that was never given a chance.
     *
     * A coroutine that needs more CPU than this to reach its *first* safe point is reported too,
     * and that is deliberate: from here there is nothing to tell it apart from one that will never
     * get there, and the answer to both is the same line of code — give it a safe point.
     */
    public const int DEFAULT_DRAIN_RESUMES = 64;

    /** {@see TimerQueue::now()} counts in nanoseconds; the drain budget is stated in seconds. */
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    /** The scheduler the static surfaces (`Coroutine::spawn()`, `Io::…`, `Timer::…`) talk to. */
    private static ?self $active = null;

    /** @var \SplQueue<Coroutine> */
    private readonly \SplQueue $runQueue;

    private readonly TimerQueue $timers;

    private readonly PollerInterface $poller;

    /**
     * Every coroutine that has not finished, in spawn order — the deadlock dump reads this.
     *
     * @var array<int, Coroutine>
     */
    private array $live = [];

    /**
     * Every coroutine currently parked inside the preemption callback, by id.
     *
     * This is an ownership set, not a statistic. A preempted coroutine's fiber is suspended inside
     * an FFI callback frame, and the engine destroys a dying fiber *from its suspension point*:
     * dropping the last reference to one, or leaving one alive at request shutdown, is a fatal
     * error that no `catch` sees. Holding it here is what guarantees it is drained first.
     *
     * @var array<int, Coroutine>
     */
    private array $preemptSuspended = [];

    /**
     * Every coroutine that spent a whole drain budget without leaving the callback, by id.
     *
     * The same ownership set as {@see self::$preemptSuspended}, for the coroutines the drain has
     * given up on. They are held here **forever**: the drain stopping is a decision about how long
     * to keep resuming, never a decision to let go — letting go is the fatal error. What ends the
     * process is {@see Preemptor} terminating it deliberately, not the engine reaching these.
     *
     * @var array<int, Coroutine>
     */
    private array $undrainable = [];

    /**
     * What each of those coroutines is, and how much of the budget it consumed, by id.
     *
     * Kept alongside rather than derived, because the counts accumulate across drain attempts: a
     * run tears down through several of them, and the report should say what the coroutine cost in
     * total rather than what the last attempt happened to spend on it.
     *
     * @var array<int, array{id: int, origin: string, resumes: int, seconds: float}>
     */
    private array $undrainableDiagnostics = [];

    private ?Preemptor $preemptor = null;

    private ?Coroutine $current = null;

    public function __construct(?PollerInterface $poller = null)
    {
        /** @var \SplQueue<Coroutine> $queue */
        $queue          = new \SplQueue();
        $this->runQueue = $queue;
        $this->timers   = new TimerQueue();
        $this->poller   = $poller ?? new StreamPoller($this);

        self::$active = $this;
    }

    /**
     * The scheduler of this process.
     *
     * There is exactly one per process by design; constructing a scheduler makes it the active one,
     * which is how a forked worker takes over from the parent's.
     */
    public static function active(): self
    {
        return self::$active ?? throw new \LogicException(
            'no scheduler is active in this process; create a Runtime (or a Scheduler) before '
            . 'spawning coroutines',
        );
    }

    public function spawn(\Closure $body, mixed ...$arguments): CoroutineInterface
    {
        $coroutine = new Coroutine($body, array_values($arguments));

        $this->live[$coroutine->id()] = $coroutine;
        $this->schedule($coroutine);

        return $coroutine;
    }

    public function current(): ?CoroutineInterface
    {
        return $this->current;
    }

    public function schedule(CoroutineInterface $coroutine): void
    {
        if (!$coroutine instanceof Coroutine) {
            throw new \InvalidArgumentException(sprintf(
                'this scheduler drives %s instances, got %s',
                Coroutine::class,
                $coroutine::class,
            ));
        }

        if ($coroutine->status() !== CoroutineStatus::READY) {
            throw new \LogicException(sprintf(
                'coroutine #%d is %s and cannot be put on the run queue; unpark it first',
                $coroutine->id(),
                $coroutine->status()->name,
            ));
        }

        // Two primitives racing to wake the same coroutine both call schedule() after a successful
        // unpark only in buggy code, but running a coroutine twice is a corruption, not a warning.
        if ($coroutine->isQueued()) {
            return;
        }

        $coroutine->markQueued();
        $this->runQueue->enqueue($coroutine);
    }

    public function suspend(SuspendCommand $command): mixed
    {
        if ($this->current === null) {
            throw new \LogicException('suspend() is only possible inside a running coroutine');
        }

        if (\Fiber::getCurrent() === null) {
            throw new \LogicException('suspend() was called outside the fiber of the running coroutine');
        }

        return \Fiber::suspend($command);
    }

    public function poller(): PollerInterface
    {
        return $this->poller;
    }

    /**
     * Hand this scheduler the preemptor that is allowed to interrupt its coroutines.
     *
     * Layer 2 is opt-in from the composition root: a scheduler with no preemptor never reaches any
     * FFI code at all, which is what keeps the cooperative runtime free of an ext-ffi requirement.
     */
    public function attachPreemptor(?Preemptor $preemptor): void
    {
        $this->preemptor = $preemptor;
    }

    /** The preemptor driving this scheduler, or null when it is purely cooperative. */
    public function preemptor(): ?Preemptor
    {
        return $this->preemptor;
    }

    /**
     * Resume every coroutine parked inside the preemption callback until it leaves, or the budget
     * runs out.
     *
     * A preempted fiber cannot be disposed of: its saved stack contains the FFI trampoline of the
     * interrupt callback, and the engine unwinds a dying fiber from wherever it is suspended, which
     * from there is `Throwing from FFI callbacks is not allowed` — a fatal error, not an exception.
     * Uninstalling the hook first does not help, because it is the *saved stack* that matters. The
     * only way out is forward: resume the coroutine (never throw into it) until it either finishes
     * or suspends somewhere it wrote itself, and only then is it safe to let go of.
     *
     * The drain deliberately keeps whatever the preemptor is doing running. A coroutine resumed
     * here may be in a loop that never yields, and it is the live slice timer that guarantees the
     * resume returns at all rather than running to the end of that loop.
     *
     * # The budget, and what happens when it runs out
     *
     * A coroutine with no cooperative point at all — `while (true) { $x++; }` — never leaves the
     * callback, so an unbounded drain is an unexplained hang at shutdown. Each attempt therefore
     * spends at most $budgetSeconds of wall clock over the whole set and at most $maxResumes
     * resumes on any one coroutine, and every coroutine is resumed at least once whatever the clock
     * says.
     *
     * Nothing is released when the budget runs out. The coroutine moves to a set this scheduler
     * holds for the rest of the process ({@see self::undrainableCoroutines()} reports it), and
     * {@see Preemptor} turns that report into a diagnosis and a deliberate process exit — the one
     * ending that keeps the engine from ever destroying the fiber. A later attempt with its own
     * budget picks the coroutine up again: giving up is about this attempt, not about the coroutine.
     *
     * @param float|null $budgetSeconds Wall clock for this whole attempt; null takes
     *                                  {@see self::DEFAULT_DRAIN_BUDGET_SECONDS}.
     * @param int|null   $maxResumes    Resumes for any one coroutine; null takes
     *                                  {@see self::DEFAULT_DRAIN_RESUMES}.
     * @return int How many coroutines were drained out of the callback.
     */
    public function drainPreempted(?float $budgetSeconds = null, ?int $maxResumes = null): int
    {
        // A resume only comes back because the next slice tick takes the CPU away again, not
        // because the coroutine hands it over: with the timer down, one `step()` into a coroutine
        // that never yields never returns, and the budget below is never even consulted. Nothing is
        // lost by declining — anything still in the callback at this point has already been through
        // a full budget with the timer live.
        if ($this->preemptor?->isArmed() === false) {
            return 0;
        }

        $budget   = ($budgetSeconds ?? self::DEFAULT_DRAIN_BUDGET_SECONDS) * self::NANOSECONDS_PER_SECOND;
        $deadline = TimerQueue::now() + (int) $budget;
        $ceiling  = $maxResumes ?? self::DEFAULT_DRAIN_RESUMES;
        $drained  = 0;

        // Everything an earlier attempt gave up on is a candidate again: this call brings its own
        // budget, and only a coroutine that refuses it again stays in the report.
        $candidates = $this->preemptSuspended + $this->undrainable;
        $spent      = $this->undrainableDiagnostics;

        $this->preemptSuspended       = [];
        $this->undrainable            = [];
        $this->undrainableDiagnostics = [];

        foreach ($candidates as $id => $coroutine) {
            if (!$coroutine->isPreemptSuspended()) {
                continue;
            }

            [$left, $resumes, $seconds] = $this->resumeOutOfTheCallback($coroutine, $deadline, $ceiling);

            if ($left) {
                $drained++;

                continue;
            }

            $this->undrainable[$id]            = $coroutine;
            $this->undrainableDiagnostics[$id] = [
                'id'      => $id,
                'origin'  => $coroutine->spawnLocation(),
                'resumes' => ($spent[$id]['resumes'] ?? 0)   + $resumes,
                'seconds' => ($spent[$id]['seconds'] ?? 0.0) + $seconds,
            ];
        }

        return $drained;
    }

    /**
     * The coroutines the drain has given up on, and what each of them cost.
     *
     * Empty in every run where preemption is off, or where every preempted coroutine eventually
     * returned or parked — which is every correct program. A non-empty answer is a diagnosis
     * waiting to be raised, and the shape matches {@see self::blockedCoroutines()} on purpose:
     * both are dumps keyed to a spawn site, because that is the line the reader has to go and look
     * at.
     *
     * @return list<array{id: int, origin: string, resumes: int, seconds: float}>
     */
    public function undrainableCoroutines(): array
    {
        return array_values($this->undrainableDiagnostics);
    }

    /** The pending deadlines; the timer surface and `sleep()` arm their entries here. */
    public function timers(): TimerQueue
    {
        return $this->timers;
    }

    /**
     * Park the running coroutine until $seconds have passed.
     *
     * A non-positive duration is a plain yield: the coroutine gives up the CPU, but arming a timer
     * for a deadline that has already passed only makes the loop take a detour to the heap.
     */
    public function sleep(float $seconds): void
    {
        if ($this->current === null) {
            throw new \LogicException('sleep() is only possible inside a running coroutine');
        }

        if ($seconds <= 0.0) {
            $this->suspend(SuspendCommand::YIELD);

            return;
        }

        $coroutine = $this->current;
        $coroutine->park(sprintf('sleep for %ss', rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.')));

        $this->timers->arm($seconds, function () use ($coroutine): void {
            if ($coroutine->unpark()) {
                $this->schedule($coroutine);
            }
        });

        $this->suspend(SuspendCommand::SLEEP);
    }

    public function loop(): void
    {
        $this->drive(null);
    }

    /**
     * Run until $coroutine finishes, then stop — whatever else is still pending.
     *
     * This is Go's `main`: the process does not wait for stragglers, it ends. Coroutines still on
     * the run queue, parked on a timer or parked on the poller are dropped, and dropping them is
     * final — they are never resumed, so their `finally` blocks do not run, exactly as a
     * goroutine's deferred calls do not run when main returns.
     */
    public function runUntil(CoroutineInterface $coroutine): void
    {
        try {
            $this->drive($coroutine);
        } finally {
            $this->discardPending();
        }
    }

    /**
     * Forget everything that is still pending: run queue, timer heap and poller registrations.
     *
     * A panic leaves the same debris behind as a returning main, so this runs after either.
     */
    public function discardPending(): void
    {
        // Everything below drops references, and a preempt-suspended coroutine is the one kind of
        // debris that may not simply be dropped. Whatever the drain could not get out of the
        // callback stays owned by $this->undrainable, so clearing $this->live below is still safe.
        $this->drainPreempted();

        while (!$this->runQueue->isEmpty()) {
            $this->runQueue->dequeue()->markDequeued();
        }

        while (!$this->timers->isEmpty()) {
            $this->timers->extract();
        }

        $this->live = [];

        if ($this->poller instanceof StreamPoller) {
            $this->poller->forgetAll();
        }
    }

    /**
     * @throws DeadlockException
     */
    private function drive(?CoroutineInterface $until): void
    {
        self::$active = $this;

        try {
            while (true) {
                if ($until !== null && $until->status() === CoroutineStatus::DONE) {
                    return;
                }

                if (!$this->runQueue->isEmpty()) {
                    $this->runNext();

                    continue;
                }

                $now = TimerQueue::now();

                // A fired timer has just unparked somebody (or spawned something), so the run
                // queue is worth another look before considering the process idle.
                if ($this->timers->fireDue($now) > 0) {
                    continue;
                }

                $timeout    = $this->timers->timeUntilNextDeadline($now);
                $hasWatches = $this->poller->hasWatches();

                if ($timeout === null && !$hasWatches) {
                    $blocked = $this->blockedCoroutines();

                    if ($blocked !== []) {
                        throw new DeadlockException($blocked);
                    }

                    // Nothing runnable, nothing pending, nobody waiting on anything local: the
                    // work is done. Anything still parked here is externally wakeable with no
                    // registered descriptor, which only a cross-process primitive can produce.
                    return;
                }

                $this->poller->poll($timeout);
            }
        } finally {
            $this->current = null;
        }
    }

    private function runNext(): void
    {
        $coroutine = $this->runQueue->dequeue();
        $coroutine->markDequeued();

        // Unparked, scheduled, and then finished or re-parked before its turn came up.
        if ($coroutine->status() !== CoroutineStatus::READY) {
            return;
        }

        $this->current = $coroutine;

        try {
            $command = $coroutine->step();
        } catch (\Throwable $panic) {
            // An uncaught throwable is a panic: it ends the run and surfaces at the caller of
            // run()/loop(), exactly where a synchronous exception would have.
            unset($this->live[$coroutine->id()], $this->preemptSuspended[$coroutine->id()]);

            throw $panic;
        } finally {
            $this->current = null;
        }

        $this->trackPreemption($coroutine, $command);

        if ($command === null) {
            unset($this->live[$coroutine->id()]);

            return;
        }

        if ($command->staysRunnable()) {
            $this->schedule($coroutine);
        }

        // Otherwise the coroutine handed itself to a primitive, a timer or the poller, and only an
        // unpark from that owner may put it back on this queue.
    }

    /**
     * Remember, or forget, that this coroutine is parked inside the preemption callback.
     *
     * Called after every step, including the one that finishes the coroutine: a preempted
     * coroutine that has since run to completion is an ordinary dead coroutine again.
     */
    private function trackPreemption(Coroutine $coroutine, ?SuspendCommand $command): void
    {
        if ($command === SuspendCommand::PREEMPT) {
            $this->preemptSuspended[$coroutine->id()] = $coroutine;

            return;
        }

        // Out of the callback under its own steam, which also clears any earlier verdict about it:
        // a coroutine that has just parked or yielded is plainly not undrainable.
        unset(
            $this->preemptSuspended[$coroutine->id()],
            $this->undrainable[$coroutine->id()],
            $this->undrainableDiagnostics[$coroutine->id()],
        );
    }

    /**
     * Resume one coroutine until it is out of the preemption callback, or its budget is gone.
     *
     * "Out" means terminated, or suspended at a point the coroutine's own code chose — a channel
     * park, a sleep, a yield. Both are safe to hold or to drop. The budget is checked *after* a
     * resume, never before: a coroutine that has not been resumed at all has not refused anything.
     *
     * @param int $deadline   `hrtime(true)` mark shared with the rest of this drain attempt.
     * @param int $maxResumes Resumes this coroutine may have.
     * @return array{0: bool, 1: int, 2: float} Whether it left the callback, resumes spent, seconds
     *                                          spent.
     */
    private function resumeOutOfTheCallback(Coroutine $coroutine, int $deadline, int $maxResumes): array
    {
        $started = TimerQueue::now();
        $resumes = 0;

        while ($coroutine->isPreemptSuspended()) {
            $this->current = $coroutine;

            try {
                $command = $coroutine->step();
            } catch (\Throwable) {
                // The drain runs while a run is being torn down, often already because of a panic.
                // A coroutine that panics on its way out has still left the callback, which is the
                // only thing the drain owes anybody.
                break;
            } finally {
                $this->current = null;
            }

            $resumes++;

            if ($command === null) {
                unset($this->live[$coroutine->id()]);

                break;
            }

            if ($resumes >= $maxResumes || TimerQueue::now() >= $deadline) {
                break;
            }
        }

        $elapsed = (TimerQueue::now() - $started) / self::NANOSECONDS_PER_SECOND;

        return [!$coroutine->isPreemptSuspended(), $resumes, $elapsed];
    }

    /**
     * The coroutines a deadlock report is about: blocked, and waiting on something local.
     *
     * @return list<array{id: int, wait: string, origin: string}>
     */
    private function blockedCoroutines(): array
    {
        $blocked = [];

        foreach ($this->live as $coroutine) {
            if ($coroutine->status() !== CoroutineStatus::BLOCKED || $coroutine->isExternallyWakeable()) {
                continue;
            }

            $blocked[] = [
                'id'     => $coroutine->id(),
                'wait'   => $coroutine->waitDescription() ?? 'an unnamed wait',
                'origin' => $coroutine->spawnLocation(),
            ];
        }

        return $blocked;
    }
}
