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

/**
 * A cancellation signal that a whole tree of coroutines can watch.
 *
 *     $request = Context::withCancel($scheduler);
 *
 *     $scheduler->spawn(function () use ($request, $jobs): void {
 *         while (true) {
 *             $done = Select::on($scheduler)
 *                 ->recv($request->done(), fn(): bool => true)
 *                 ->recv($jobs, function (mixed $job): bool { handle($job); return false; })
 *                 ->run();
 *
 *             if ($done) { return; }
 *         }
 *     });
 *
 *     $request->cancel();
 *
 * # Cancellation is a channel that closes
 *
 * There is no bespoke notification machinery, and that is the design. A closed channel is already
 * something every coroutine knows how to wait on, and — because a closed channel reports
 * `canRecv() === true` forever — it composes with `select` for free: one arm watches the work, the
 * other watches {@see self::done()}, and cancellation simply wins the race. It also means a
 * cancellation that arrives before anybody looks is not lost, unlike a signal that has to be caught
 * while it is being sent.
 *
 * # Cancelling a parent cancels its children
 *
 * A context holds its children and cancels them on the way down, so cancelling a request tears down
 * every sub-operation it started. A child that is cancelled on its own detaches from its parent
 * instead of lingering there, so a long-lived parent handing out short-lived children does not
 * accumulate them.
 *
 * Cancelling is idempotent: an operation that finished normally can call `cancel()` in a `finally`
 * without checking whether it already happened.
 */
final class Context
{
    /** @var Channel<null> */
    private readonly Channel $done;

    /** @var array<int, self> */
    private array $children = [];

    private function __construct(
        private readonly SchedulerInterface $scheduler,
        private ?self $parent = null,
    ) {
        $this->done = new Channel($scheduler);
    }

    /**
     * A cancellable context, rooted at a scheduler or nested under another context.
     *
     * @param self|SchedulerInterface $parent A context to nest under, or the scheduler for a root.
     */
    public static function withCancel(self|SchedulerInterface $parent): self
    {
        if (!$parent instanceof self) {
            return new self($parent);
        }

        $child = new self($parent->scheduler, $parent);

        // A parent that is already cancelled cancels the child immediately rather than handing back
        // a context that looks live and never fires.
        if ($parent->isCancelled()) {
            $child->cancel();

            return $child;
        }

        $parent->children[spl_object_id($child)] = $child;

        return $child;
    }

    /**
     * A context that cancels itself after $seconds.
     *
     * The wait is performed by a coroutine, so the timeout costs nothing while it is pending and
     * cannot block the process. $sleeper is how that coroutine sleeps — `Runtime::sleep(...)` as a
     * first-class callable, or any `\Closure(float): void` that parks the current coroutine for the
     * given number of seconds.
     *
     * It is a parameter rather than something looked up here because a timeout is a timer, timers
     * belong to the scheduler that owns the clock, and {@see SchedulerInterface} does not expose one
     * — inventing a private clock in the cancellation code would be a second source of time in a
     * runtime that must have exactly one.
     *
     * Cancelling the context before the deadline does not abort the sleeping coroutine; it simply
     * makes its eventual `cancel()` a no-op.
     *
     * @param self|SchedulerInterface $parent
     * @param float                   $seconds
     * @param \Closure(float): void   $sleeper
     */
    public static function withTimeout(self|SchedulerInterface $parent, float $seconds, \Closure $sleeper): self
    {
        $context = self::withCancel($parent);

        $context->scheduler->spawn(static function () use ($context, $sleeper, $seconds): void {
            $sleeper($seconds);
            $context->cancel();
        });

        return $context;
    }

    /**
     * The channel that closes when this context is cancelled.
     *
     * Directly selectable, and receiving from it after cancellation keeps reporting
     * `[null, false]` — a late watcher still learns what happened.
     *
     * @return ChannelInterface<null>
     */
    public function done(): ChannelInterface
    {
        return $this->done;
    }

    public function isCancelled(): bool
    {
        return $this->done->isClosed();
    }

    /** Cancel this context and everything below it. Safe to call more than once. */
    public function cancel(): void
    {
        if ($this->done->isClosed()) {
            return;
        }

        $this->done->close();

        $children       = $this->children;
        $this->children = [];
        foreach ($children as $child) {
            $child->cancel();
        }

        $this->parent?->detach($this);
        $this->parent = null;
    }

    /** How many live child contexts this one would cancel; the anti-accumulation invariant. */
    public function childCount(): int
    {
        return count($this->children);
    }

    private function detach(self $child): void
    {
        unset($this->children[spl_object_id($child)]);
    }
}
