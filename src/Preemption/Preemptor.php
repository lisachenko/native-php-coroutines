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
 */
final class Preemptor
{
    /** The slice Layer 2 aims for, matching Go's own preemption interval. */
    public const float DEFAULT_SLICE_SECONDS = 0.01;

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
        });
    }
}
