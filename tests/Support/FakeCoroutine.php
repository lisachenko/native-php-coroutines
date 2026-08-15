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

namespace Lisachenko\NativePhpCoroutines\Tests\Support;

use Lisachenko\NativePhpCoroutines\CoroutineInterface;
use Lisachenko\NativePhpCoroutines\CoroutineStatus;
use Lisachenko\NativePhpCoroutines\SuspendCommand;

/**
 * A coroutine that is just enough Fiber to drive the channel and sync primitives.
 *
 * This is a test double for the real coroutine, not a preview of it: no throw-in, no return value
 * plumbing, no join handle. What it does implement is the part the primitives depend on, and it
 * implements it strictly, so a primitive that breaks the documented protocol fails here rather than
 * at integration:
 *
 * - `park()` records the reason and moves to BLOCKED; from then on the primitive owns this
 *   coroutine and the scheduler will not touch it.
 * - `unpark()` performs the BLOCKED -> READY transition and returns true **only** for the caller
 *   that performed it. Every later call is a no-op returning false, which is what lets several
 *   channels race to wake one selecting coroutine.
 * - `unpark()` does **not** enqueue anything. Making the woken coroutine runnable is the waker's
 *   job, via {@see \Lisachenko\NativePhpCoroutines\SchedulerInterface::schedule()}, exactly as that
 *   method's contract describes.
 */
final class FakeCoroutine implements CoroutineInterface
{
    private CoroutineStatus $status = CoroutineStatus::READY;

    private ?\Fiber $fiber = null;

    private ?string $waitDescription = null;

    private bool $externallyWakeable = false;

    /**
     * @param \Closure(mixed...): mixed $body
     * @param list<mixed>               $arguments
     */
    public function __construct(
        private readonly int $id,
        private readonly \Closure $body,
        private readonly array $arguments,
        private readonly string $spawnLocation,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function status(): CoroutineStatus
    {
        return $this->status;
    }

    public function park(string $waitDescription, bool $externallyWakeable = false): void
    {
        $this->status             = CoroutineStatus::BLOCKED;
        $this->waitDescription    = $waitDescription;
        $this->externallyWakeable = $externallyWakeable;
    }

    public function unpark(): bool
    {
        if ($this->status !== CoroutineStatus::BLOCKED) {
            return false;
        }

        $this->status          = CoroutineStatus::READY;
        $this->waitDescription = null;

        return true;
    }

    public function isExternallyWakeable(): bool
    {
        return $this->externallyWakeable;
    }

    public function waitDescription(): ?string
    {
        return $this->waitDescription;
    }

    public function spawnLocation(): string
    {
        return $this->spawnLocation;
    }

    /**
     * Give this coroutine the CPU until it suspends or finishes.
     *
     * Returns the command it suspended with, or null when it finished. A coroutine that parked has
     * already moved itself to BLOCKED by the time this returns, so the status is read afterwards
     * rather than assumed here.
     */
    public function run(): ?SuspendCommand
    {
        $this->status = CoroutineStatus::RUNNING;

        try {
            if ($this->fiber === null) {
                $this->fiber = new \Fiber($this->body);
                $signal      = $this->fiber->start(...$this->arguments);
            } else {
                // Resumed with null on purpose: a primitive must carry its outcome in its own wait
                // node, never in the scheduler's resume value.
                $signal = $this->fiber->resume();
            }
        } catch (\Throwable $failure) {
            $this->status = CoroutineStatus::DONE;

            throw $failure;
        }

        if ($this->fiber->isTerminated()) {
            $this->status = CoroutineStatus::DONE;

            return null;
        }

        return $signal instanceof SuspendCommand ? $signal : SuspendCommand::YIELD;
    }

    /** Move a voluntarily yielding coroutine back to READY so it may be enqueued again. */
    public function markReady(): void
    {
        $this->status = CoroutineStatus::READY;
    }
}
