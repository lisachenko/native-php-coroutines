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
use Lisachenko\NativePhpCoroutines\CoroutineStatus;
use Lisachenko\NativePhpCoroutines\Scheduler;
use Lisachenko\NativePhpCoroutines\SuspendCommand;
use ZEngine\Core;
use ZEngine\System\Hook\InterruptHook;

/**
 * The one place a coroutine is ever suspended against its will.
 *
 * `SIGALRM` alone cannot do this. A PHP signal handler may not switch fibers — the engine answers
 * every attempt with `FiberError: Cannot switch fibers in current execution context`, whether the
 * handler runs asynchronously or is dispatched by hand with `pcntl_signal_dispatch()`. What a
 * signal handler *may* do is raise `EG(vm_interrupt)`, and the engine then calls
 * `zend_interrupt_function` at the next interrupt check — loop back-edges and function entries.
 * That callback runs as ordinary PHP inside the interrupted frame, and suspending the running
 * fiber from there is legal. This class is that callback.
 *
 * # Four rules, each of them the difference between working and a fatal error
 *
 * 1. **Everything in the callback body is inside `try { … } catch (\Throwable) { }`.** A throwable
 *    escaping an FFI callback is `PHP Fatal error: Throwing from FFI callbacks is not allowed`,
 *    exit 255, and it is not catchable anywhere.
 * 2. **`proceed()` is chained whenever an original handler exists.** ext-pcntl claims
 *    `zend_interrupt_function` when it is loaded, and that is where PHP-level signal dispatch
 *    happens. A hook that does not chain does not "miss an optimisation": every PHP signal handler
 *    in the process silently stops running.
 * 3. **Nothing autoloads from in here.** Entering the autoloader from an engine callback re-enters
 *    the compiler, so {@see self::preloadCallbackClasses()} touches every class the body can reach
 *    before the hook is installed.
 * 4. **Nothing happens when `Fiber::getCurrent()` is null.** A tick that lands in the scheduler has
 *    no coroutine to preempt; the request stays pending and the next tick inside a fiber takes it.
 *
 * # The suspended fiber's resume point is inside this callback
 *
 * That is the fact the whole ownership discipline of {@see Preemptor} and
 * {@see Scheduler::drainPreempted()} follows from: the fiber's saved stack contains this FFI
 * trampoline frame, so it may only ever be resumed with `resume(null)`, never thrown into, and
 * never destroyed while it is still suspended here.
 */
final class InterruptBridge
{
    private ?InterruptHook $hook = null;

    private int $preemptions = 0;

    /** @var \Closure(): bool Consulted from the callback; true means "suspend now". */
    private readonly \Closure $shouldPreempt;

    /**
     * @param \Closure(): bool $shouldPreempt The policy decision, owned by {@see Preemptor}.
     */
    public function __construct(\Closure $shouldPreempt)
    {
        $this->shouldPreempt = $shouldPreempt;
    }

    public function isInstalled(): bool
    {
        return $this->hook !== null;
    }

    /** How many times the callback actually suspended a coroutine. */
    public function preemptions(): int
    {
        return $this->preemptions;
    }

    /**
     * Boot z-engine and install the interrupt callback. Idempotent.
     *
     * `Core::init()` is what enforces that the z-engine line matches the running PHP minor; it is
     * allowed to refuse, and a refusal must be reported rather than routed around — the hook writes
     * engine memory by byte offset, so a mismatched line writes the wrong bytes.
     */
    public function install(): void
    {
        if ($this->hook !== null) {
            return;
        }

        Core::init();
        self::preloadCallbackClasses();

        $this->hook = Core::setInterruptHandler($this->onInterrupt(...));
    }

    /** Restore the previous `zend_interrupt_function`. Idempotent. */
    public function uninstall(): void
    {
        $hook = $this->hook;

        if ($hook === null) {
            return;
        }

        $this->hook = null;
        $hook->uninstall();
    }

    /**
     * Raise `EG(vm_interrupt)` so the callback runs at the next interrupt check.
     *
     * This is the only thing a PHP signal handler is allowed to do towards preemption, and it is
     * also what makes leaving a critical section take effect promptly instead of waiting for the
     * next tick of the interval timer.
     */
    public function requestInterrupt(): void
    {
        if ($this->hook === null) {
            return;
        }

        Core::$executor->requestInterrupt();
    }

    /**
     * The engine callback. Runs inside the interrupted frame, in the interrupted coroutine's fiber.
     *
     * Read the two `try` blocks as one rule each: the first must never let a throwable out, and the
     * second must never let a chained handler be skipped — including when the first one suspended
     * and the coroutine was resumed seconds later, which is exactly where control returns to.
     */
    private function onInterrupt(InterruptHook $hook): void
    {
        try {
            if (\Fiber::getCurrent() !== null && ($this->shouldPreempt)()) {
                $this->preemptions++;

                // Control leaves the process's PHP stack here and comes back in
                // Scheduler::runNext(), which sees SuspendCommand::PREEMPT and re-queues the
                // coroutine. Execution resumes on the next line when it gets the CPU again.
                \Fiber::suspend(SuspendCommand::PREEMPT);
            }
        } catch (\Throwable) {
            // Deliberately swallowed and deliberately not logged: logging is user code, user code
            // can throw, and a throw from here is an uncatchable fatal.
        }

        try {
            if ($hook->hasOriginalHandler()) {
                $hook->proceed();
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Touch every class the callback body can reach, while autoloading is still safe.
     *
     * `Core::init()` has already preloaded z-engine's own classes; this covers ours. Keep the list
     * in sync with the callback and with {@see Preemptor::shouldPreempt()}, which the callback
     * calls into.
     */
    private static function preloadCallbackClasses(): void
    {
        class_exists(\Fiber::class);
        class_exists(\FiberError::class);
        class_exists(InterruptHook::class);
        class_exists(Preemptor::class);
        class_exists(Scheduler::class);
        class_exists(Coroutine::class);
        enum_exists(CoroutineStatus::class);
        enum_exists(SuspendCommand::class);
    }
}
