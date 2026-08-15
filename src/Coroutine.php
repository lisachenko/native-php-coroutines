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
 * A unit of cooperative execution, backed by a native Fiber.
 *
 * Layer 1 in full: a `\Fiber`, a status, and the park/unpark bookkeeping the scheduler and the
 * blocking primitives share. No FFI, no extension beyond core PHP.
 *
 * # park/unpark, and why unpark does not schedule
 *
 * {@see self::unpark()} performs the BLOCKED -> READY transition and nothing else. Making the
 * coroutine runnable again is the second half, and it belongs to the caller:
 *
 * ```php
 * if ($coroutine->unpark()) {
 *     $scheduler->schedule($coroutine);
 * }
 * ```
 *
 * Splitting it this way is what makes the idempotence useful. Only the caller that won the
 * transition gets true, so only that caller enqueues, and a losing `select` case that unparks a
 * coroutine somebody else already claimed cannot schedule it a second time.
 */
final class Coroutine implements CoroutineInterface
{
    private static int $nextId = 1;

    private readonly int $id;

    /**
     * The whole of Layer 1's execution machinery.
     *
     * Everything is `mixed`: a coroutine body takes and returns whatever the application wants, and
     * although the scheduler always suspends *with* a {@see SuspendCommand}, a body is free to call
     * `Fiber::suspend()` itself and hand back anything at all — which {@see self::step()} treats as
     * a plain yield rather than trusting the value.
     *
     * @var \Fiber<mixed, mixed, mixed, mixed>
     */
    private readonly \Fiber $fiber;

    private readonly string $spawnLocation;

    private CoroutineStatus $status = CoroutineStatus::READY;

    /** Whether a primitive currently owns this coroutine; the flag {@see self::unpark()} claims. */
    private bool $parked = false;

    /** Whether the coroutine is already sitting on the scheduler's run queue. */
    private bool $queued = false;

    private ?string $waitDescription = null;

    private bool $externallyWakeable = false;

    private mixed $returnValue = null;

    /**
     * @param \Closure(mixed...): mixed $body
     * @param list<mixed>               $arguments Passed to the body when the coroutine first runs.
     */
    public function __construct(\Closure $body, private readonly array $arguments = [])
    {
        $this->id            = self::$nextId++;
        $this->fiber         = new \Fiber($body);
        $this->spawnLocation = self::captureSpawnLocation();
    }

    /**
     * Create a coroutine on the active scheduler and put it on the run queue.
     *
     * @param \Closure(mixed...): mixed $body
     */
    public static function spawn(\Closure $body, mixed ...$arguments): CoroutineInterface
    {
        return Scheduler::active()->spawn($body, ...$arguments);
    }

    /**
     * Hand the CPU to the next runnable coroutine.
     *
     * The yielding coroutine goes to the **tail** of the run queue, so it cannot win the CPU back
     * before everybody else has had a turn.
     */
    public static function yield(): void
    {
        Scheduler::active()->suspend(SuspendCommand::YIELD);
    }

    /**
     * Park the current coroutine on a timer deadline.
     *
     * The whole process idles until the earliest deadline: the scheduler hands that deadline to
     * the poller as its timeout, so a program that is only sleeping burns no CPU.
     */
    public static function sleep(float $seconds): void
    {
        Scheduler::active()->sleep($seconds);
    }

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
        if ($this->status === CoroutineStatus::DONE) {
            throw new \LogicException(sprintf('coroutine #%d has finished and cannot park', $this->id));
        }

        $this->parked             = true;
        $this->waitDescription    = $waitDescription;
        $this->externallyWakeable = $externallyWakeable;
    }

    public function unpark(): bool
    {
        if (!$this->parked) {
            return false;
        }

        $this->parked             = false;
        $this->waitDescription    = null;
        $this->externallyWakeable = false;

        if ($this->status === CoroutineStatus::BLOCKED) {
            $this->status = CoroutineStatus::READY;
        }

        return true;
    }

    public function isExternallyWakeable(): bool
    {
        return $this->externallyWakeable;
    }

    public function waitDescription(): ?string
    {
        return $this->parked ? $this->waitDescription : null;
    }

    public function spawnLocation(): string
    {
        return $this->spawnLocation;
    }

    /** What the body returned, or null while it is still running. */
    public function returnValue(): mixed
    {
        return $this->returnValue;
    }

    /**
     * Run the coroutine until it suspends or finishes.
     *
     * @internal Only the scheduler may call this: it is the one place where control crosses into
     *           the fiber, and re-entering from anywhere else would run a coroutine twice.
     * @return SuspendCommand|null The reason it handed control back, or null when it finished.
     */
    public function step(mixed $resumeValue = null): ?SuspendCommand
    {
        $this->status = CoroutineStatus::RUNNING;
        $this->queued = false;

        try {
            $signal = $this->fiber->isStarted()
                ? $this->fiber->resume($resumeValue)
                : $this->fiber->start(...$this->arguments);
        } catch (\Throwable $panic) {
            $this->markDone();

            throw $panic;
        }

        if ($this->fiber->isTerminated()) {
            $this->returnValue = $this->fiber->getReturn();
            $this->markDone();

            return null;
        }

        // Code that suspends its fiber directly, without going through the scheduler, is treated
        // as a plain yield: it is still runnable, and the scheduler is the only thing that could
        // have parked it on something.
        $command = $signal instanceof SuspendCommand ? $signal : SuspendCommand::YIELD;

        $this->status = $command->staysRunnable() ? CoroutineStatus::READY : CoroutineStatus::BLOCKED;

        return $command;
    }

    /** @internal Bookkeeping for the scheduler's run queue. */
    public function isQueued(): bool
    {
        return $this->queued;
    }

    /** @internal Bookkeeping for the scheduler's run queue. */
    public function markQueued(): void
    {
        $this->queued = true;
    }

    /** @internal Bookkeeping for the scheduler's run queue. */
    public function markDequeued(): void
    {
        $this->queued = false;
    }

    private function markDone(): void
    {
        $this->status             = CoroutineStatus::DONE;
        $this->parked             = false;
        $this->queued             = false;
        $this->waitDescription    = null;
        $this->externallyWakeable = false;
    }

    /**
     * The first frame outside this package — where the application asked for a coroutine.
     *
     * That is the anchor a deadlock dump needs: pointing at `Scheduler::spawn()` would name the
     * same line for every coroutine in the process.
     */
    private static function captureSpawnLocation(): string
    {
        $packageDirectory = __DIR__ . DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || str_starts_with($file, $packageDirectory)) {
                continue;
            }

            return $file . ':' . ($frame['line'] ?? 0);
        }

        return 'unknown:0';
    }
}
