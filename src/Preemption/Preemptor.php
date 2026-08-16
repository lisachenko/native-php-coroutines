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

namespace Lisachenko\NativePhpCoroutines\Preemption;

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Exception\UndrainableCoroutineException;
use Lisachenko\NativePhpCoroutines\Scheduler;

/**
 * Layer 2: the policy that decides when a coroutine loses the CPU without asking for it.
 *
 * The mechanism is split in three on purpose. {@see ItimerClock} is the clock and knows nothing
 * about coroutines; {@see InterruptBridge} is the one legal suspension point and knows nothing
 * about policy; this class is the policy and performs no engine work of its own. Everything below
 * is a decision, not a mechanism.
 *
 * # The path a preemption takes
 *
 * 1. `setitimer(ITIMER_REAL)` raises `SIGALRM` every slice.
 * 2. ext-pcntl's C handler raises `EG(vm_interrupt)`; {@see self::onTick()} — the PHP-level handler
 *    — records that a preemption is *wanted* and raises the flag again, so the callback is reached
 *    outside pcntl's own dispatch frame.
 * 3. The engine calls the interrupt callback at the next check (a loop back-edge or a function
 *    entry), the callback asks {@see self::shouldPreempt()}, and suspends if the answer is yes.
 *
 * # What makes the answer "no"
 *
 * - **Control is not in a coroutine of this scheduler.** A tick that lands in the scheduler, in a
 *   shutdown function, or in a fiber somebody else owns has nothing this class may suspend. The
 *   request is *not* consumed: it stays pending and the first tick inside a coroutine takes it.
 * - **A critical section is open.** {@see self::enterCriticalSection()} /
 *   {@see self::leaveCriticalSection()} bracket the code that must not be interrupted — the
 *   native arena lock above all, where being suspended while holding it would deadlock every other
 *   worker. Leaving the outermost section re-raises the interrupt so the deferred preemption is
 *   taken immediately rather than up to a slice later.
 *
 * # What preemption does not promise
 *
 * A time slice is a target, not a bound. The interrupt check sits between opcodes, so a single
 * long-running opcode is not interruptible at all: `sort()` over four million integers defers a
 * preemption by 1.6–2.0 s and a 400 MB `str_repeat()` by ~1.2 s. Every *loop* shape is interrupted
 * within a few hundred microseconds of the tick, including an empty `while (true) {}` — there is no
 * loop a program can write that starves the scheduler — but no watchdog built on this may assume a
 * hard upper bound on a slice.
 *
 * # The obligation that comes with it
 *
 * A preempted fiber is suspended inside an FFI callback frame. It may only be resumed with
 * `resume(null)`; throwing into it either loses the exception silently or kills the process, and
 * destroying it while it is suspended there is a fatal error the engine gives no way to catch. The
 * scheduler therefore keeps a strong reference to every preempted coroutine and drains it
 * ({@see Scheduler::drainPreempted()}), and this class registers a shutdown drain as the backstop
 * for a run that ends by panic or by `exit()`.
 *
 * # And the coroutine that will not be drained
 *
 * The drain resumes a coroutine until it returns or parks. One that does neither —
 * `while (true) { $x++; }` — used to be resumed forever, which is a hang at shutdown with nothing
 * to read. The drain is bounded instead, and this class owns what happens next: the straggler is
 * still held, never released, and the process is ended deliberately with the diagnosis on STDERR
 * ({@see self::endTheProcessWithADiagnosis()}). Being held and being drained are separate
 * obligations; only the second one has a budget.
 */
final class Preemptor
{
    /** The slice Layer 2 aims for, matching Go's own preemption interval. */
    public const float DEFAULT_SLICE_SECONDS = 0.01;

    /**
     * The budget for a drain that is not allowed to give up, in seconds.
     *
     * A day, which no request shutdown outlives — "unbounded" written as a number, because the
     * budget is compared against a monotonic clock and infinity does not survive the conversion.
     * Only the ext-posix-less fallback uses it, where there is no way to end the process safely and
     * spinning is the least-bad ending left.
     */
    private const float UNBOUNDED_DRAIN_SECONDS = 86_400.0;

    private readonly ItimerClock $clock;

    private readonly InterruptBridge $bridge;

    private bool $armed = false;

    /**
     * A tick has fired and its preemption has not been taken yet.
     *
     * Deliberately *not* cleared by a tick that finds no coroutine to preempt: dropping it there
     * would make preemption depend on where the free-running tick grid happens to land.
     */
    private bool $requested = false;

    private int $criticalDepth = 0;

    private int $preemptions = 0;

    private bool $shutdownDrainRegistered = false;

    /**
     * Whatever SIGALRM was pointed at before {@see self::arm()} took it over.
     *
     * Preemption borrows the signal; it does not own it. Handing it back on disarm is what keeps a
     * runtime that armed and disarmed indistinguishable, from the signal's point of view, from one
     * that never ran at all.
     *
     * `pcntl_signal_get_handler()` answers with either one of the `SIG_*` constants or the callable
     * that was registered, so the captured value is only narrowed where it is handed back.
     */
    private mixed $previousSignalHandler = null;

    public function __construct(
        private readonly Scheduler $scheduler,
        float $slice = self::DEFAULT_SLICE_SECONDS,
    ) {
        $this->clock  = new ItimerClock($slice);
        $this->bridge = new InterruptBridge($this->shouldPreempt(...));
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    /** How many times a coroutine was actually suspended by the interrupt callback. */
    public function preemptions(): int
    {
        return $this->preemptions;
    }

    public function clock(): ItimerClock
    {
        return $this->clock;
    }

    public function bridge(): InterruptBridge
    {
        return $this->bridge;
    }

    /**
     * Install the interrupt callback, register the shutdown drain, and start the clock.
     *
     * The order is load-bearing. The shutdown drain is registered *before* `Core::init()` gets a
     * chance to register z-engine's own, so the drain runs first and no preempt-suspended fiber is
     * still parked inside the FFI callback when the hooks are restored.
     */
    public function arm(): void
    {
        if ($this->armed) {
            return;
        }

        if (!extension_loaded('pcntl')) {
            throw new \RuntimeException(
                'preemptive scheduling needs ext-pcntl to receive the slice timer\'s SIGALRM',
            );
        }

        $this->registerShutdownDrain();
        $this->bridge->install();

        $this->previousSignalHandler = pcntl_signal_get_handler(SIGALRM);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, $this->onTick(...));

        $this->clock->arm();
        $this->armed = true;
    }

    /**
     * Stop preempting, and leave nothing suspended inside the engine callback behind.
     *
     * The drain happens *first*, while the clock is still running: a coroutine resumed here may be
     * in a loop that never yields, and only a live timer guarantees that resuming it returns.
     *
     * It is bounded, so this returns even for a coroutine that never cooperates. What it could not
     * get out of the callback is still owned by the scheduler and reported by
     * {@see Scheduler::undrainableCoroutines()}; disarming does not release anything.
     */
    public function disarm(): void
    {
        if (!$this->armed) {
            return;
        }

        $this->scheduler->drainPreempted();

        $this->clock->disarm();
        $this->armed     = false;
        $this->requested = false;

        if (extension_loaded('pcntl')) {
            $previous = $this->previousSignalHandler;

            pcntl_signal(SIGALRM, is_int($previous) || is_callable($previous) ? $previous : SIG_DFL);
        }

        $this->previousSignalHandler = null;

        $this->bridge->uninstall();
    }

    /**
     * Re-arm in a freshly forked worker.
     *
     * `fork()` clears the child's interval timers and hands it a copy of the parent's signal
     * dispositions, so the child keeps the handler but never receives a tick. A worker that skips
     * this simply runs without preemption, with nothing to indicate it.
     */
    public function rearmAfterFork(): void
    {
        if (!$this->armed) {
            return;
        }

        $this->requested = false;

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, $this->onTick(...));

        $this->clock->rearmAfterFork();
    }

    /**
     * Open a section that must run to its end without being preempted.
     *
     * Sections nest; only leaving the outermost one makes preemption possible again. This is what
     * makes the lock discipline enforceable: a coroutine that holds a native arena lock is
     * unpreemptable for as long as it holds it, so no other worker can find the lock held by a
     * coroutine that is not running.
     */
    public function enterCriticalSection(): void
    {
        $this->criticalDepth++;
    }

    /**
     * Close the innermost critical section.
     *
     * When the outermost one closes and a preemption was deferred, the interrupt is raised again
     * straight away: the deferred slice is taken at the next opcode boundary instead of waiting up
     * to a full tick for the timer to ask a second time.
     */
    public function leaveCriticalSection(): void
    {
        if ($this->criticalDepth === 0) {
            throw new \LogicException(
                'leaveCriticalSection() was called without a matching enterCriticalSection()',
            );
        }

        $this->criticalDepth--;

        if ($this->criticalDepth === 0 && $this->requested && $this->armed) {
            $this->bridge->requestInterrupt();
        }
    }

    public function criticalSectionDepth(): int
    {
        return $this->criticalDepth;
    }

    /**
     * Run $body with preemption masked, closing the section however the body leaves.
     *
     * @return mixed Whatever $body returned.
     */
    public function withCriticalSection(\Closure $body): mixed
    {
        $this->enterCriticalSection();

        try {
            return $body();
        } finally {
            $this->leaveCriticalSection();
        }
    }

    /**
     * The safe-point decision, called from the interrupt callback and from nowhere else.
     *
     * @internal
     */
    public function shouldPreempt(): bool
    {
        if (!$this->armed || !$this->requested || $this->criticalDepth > 0) {
            return false;
        }

        $current = $this->scheduler->current();

        // Not one of ours: the tick landed in the scheduler itself, in a shutdown function, or in
        // a fiber the application drives on its own. Leave the request pending.
        if (!$current instanceof Coroutine || !$current->ownsCurrentFiber()) {
            return false;
        }

        $this->requested = false;
        $this->preemptions++;

        return true;
    }

    /**
     * The PHP-level SIGALRM handler.
     *
     * It may not suspend anything — the engine refuses fiber switches from signal dispatch, always
     * and on every dispatch path — so it records the request and raises the VM interrupt flag,
     * which gets the interrupt callback invoked outside pcntl's dispatch frame.
     *
     * @param int   $signal  The delivered signal, always SIGALRM here.
     * @param mixed $details ext-pcntl's siginfo payload; unused.
     */
    private function onTick(int $signal, mixed $details = null): void
    {
        $this->requested = true;

        if ($this->criticalDepth === 0) {
            $this->bridge->requestInterrupt();
        }
    }

    /**
     * The backstop drain, for a run that ends by panic or by `exit()`.
     *
     * `Runtime::run()` drains on its own way out, so in an ordinary run this finds nothing to do.
     * It exists for the runs that never reach that code, where leaving one preempt-suspended fiber
     * for the engine to destroy is a fatal error with no catch clause anywhere.
     *
     * This is also the last place anything can be done about a coroutine that spent its budget
     * without cooperating, which is why the straggler check lives here rather than only in
     * `Runtime::run()`: the run may have ended by a panic, by `exit()`, or in a forked worker that
     * never reaches that code at all, and the obligation is the same in every one of them.
     */
    private function registerShutdownDrain(): void
    {
        if ($this->shutdownDrainRegistered) {
            return;
        }

        $this->shutdownDrainRegistered = true;

        register_shutdown_function(function (): void {
            $this->scheduler->drainPreempted();
            $this->clock->disarm();
            $this->armed = false;

            $stragglers = $this->scheduler->undrainableCoroutines();

            if ($stragglers !== []) {
                $this->endTheProcessWithADiagnosis($stragglers);
            }
        });
    }

    /**
     * Say why, then take the process down before the engine can reach the fiber.
     *
     * Every ending available from here was measured (spike S7, `spikes/VERDICTS.md`), and the
     * choice is forced:
     *
     * - letting request shutdown proceed with the fiber alive — `PHP Fatal error: Throwing from
     *   FFI callbacks is not allowed`, exit 255, uncatchable, on both minors;
     * - uninstalling the interrupt hook first — the same fatal, because it is the fiber's *saved
     *   stack* that carries the FFI trampoline frame;
     * - `exit()` — the same fatal, because it still runs request shutdown;
     * - a signal to self — the process ends where it stands, no fiber is destroyed, and everything
     *   already written is kept.
     *
     * So the ending is a signal, and it is `SIGKILL` rather than a politer one because a signal
     * that can be handled can be handled by the application, and this one may not be declined: the
     * only alternative to it is the fatal above. It is sent from a shutdown function registered
     * *from inside* this one, which PHP appends to the queue, so every other shutdown function the
     * application registered still runs first (S7 `--late-shutdown-function`).
     *
     * The diagnosis goes to STDERR because at this point in shutdown output buffering may already
     * be gone and the exception can no longer be thrown anywhere anybody could catch it.
     *
     * Without ext-posix there is nothing to send the signal with, and the drain goes back to being
     * unbounded — a diagnosed wait instead of a silent one. That is the worse ending of the two on
     * purpose: "never release a preempt-suspended fiber" is an invariant, and waiting is the only
     * thing left that keeps it.
     *
     * @param list<array{id: int, origin: string, resumes: int, seconds: float}> $stragglers
     */
    private function endTheProcessWithADiagnosis(array $stragglers): void
    {
        $diagnosis = new UndrainableCoroutineException($stragglers);

        if (!function_exists('posix_kill')) {
            fwrite(STDERR, $diagnosis->getMessage() . PHP_EOL
                . 'ext-posix is not loaded, so this process cannot be ended safely; draining the '
                . 'coroutine instead, which will not return until it cooperates' . PHP_EOL);

            // Re-arming is not optional here: the timer is the only reason a resume ever comes
            // back, and this drain deliberately has no budget to stop it.
            $this->arm();
            $this->scheduler->drainPreempted(self::UNBOUNDED_DRAIN_SECONDS, PHP_INT_MAX);
            $this->disarm();

            return;
        }

        register_shutdown_function(static function () use ($diagnosis): void {
            fwrite(STDERR, $diagnosis->getMessage() . PHP_EOL);
            fflush(STDERR);
            flush();

            posix_kill((int) getmypid(), SIGKILL);
        });
    }
}
