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

use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\RuntimeInterface;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;

/**
 * What a forked worker does with the rest of its life.
 *
 * The whole loop is one idea: **the inbox is just another readable descriptor**. The control socket
 * is registered with {@see \Lisachenko\NativePhpCoroutines\PollerInterface::watchReadable()}, so a
 * worker waiting for work is parked in the same `stream_select()` as a worker waiting for a timer or
 * a socket. There is no second event loop, no polling thread, and no blocking read anywhere.
 *
 * A `SPAWN` record starts an ordinary local coroutine. That is the point of running a full scheduler
 * in the child rather than a `while (true) { read(); work(); }` loop: a task that sleeps or does IO
 * yields to its siblings and to the inbox instead of stalling the worker.
 *
 * # Exit on EOF is a safety property, not a convenience
 *
 * The socket becoming readable-at-EOF means the parent's end is closed — because it shut down, or
 * because it was killed, or because it crashed. Either way nothing will ever dispatch to this
 * process again, so it stops watching, finishes what it already has, and exits. Without that rung, a
 * parent killed with `SIGKILL` would leave its workers running forever with nobody to notice.
 *
 * # This class runs in the child only
 *
 * It is public because a test — and, later, a differently-shaped supervisor — may want to fork a
 * worker itself and drive this loop directly over a socket pair it owns.
 */
final class WorkerChild
{
    private bool $stopping = false;

    private int $inFlight = 0;

    private bool $watching = false;

    private function __construct(
        private readonly ControlSocket $control,
        private readonly TaskDirectory $tasks,
        private readonly RuntimeInterface $runtime,
        private readonly SchedulerInterface $scheduler,
    ) {}

    /**
     * Serve this worker's inbox until the parent says stop or goes away.
     *
     * @param ControlSocket        $control The child's end of the pair; this call owns and closes it.
     * @param RuntimeInterface|null $runtime The runtime tasks are handed. Created here by default,
     *                                       **after** the fork: constructing it creates this
     *                                       process's scheduler, and a scheduler — and every fiber
     *                                       under it — must be born on this side of the fork.
     * @return int The process exit status; 0 for an orderly end.
     */
    public static function main(
        ControlSocket $control,
        TaskDirectory $tasks,
        ?RuntimeInterface $runtime = null,
    ): int {
        $runtime   = $runtime ?? new Runtime();
        $scheduler = $runtime->scheduler();

        $child = new self($control, $tasks, $runtime, $scheduler);
        $child->listen();

        // Returns once the watch is dropped and nothing is left to run: EOF or a SHUTDOWN record
        // with no work still in flight.
        $scheduler->loop();

        $control->close();

        return 0;
    }

    private function listen(): void
    {
        $this->scheduler->poller()->watchReadable(
            $this->control->stream(),
            function (): void {
                $this->onReadable();
            },
        );

        $this->watching = true;
    }

    private function onReadable(): void
    {
        foreach ($this->control->drain() as $record) {
            $this->apply($record);
        }

        if ($this->control->isEof()) {
            // A closed socket reports readable forever. Dropping the watch here — rather than when
            // the last task finishes — is what stops the poller spinning while in-flight work runs.
            $this->stopping = true;
            $this->unwatch();
        }

        $this->finishIfIdle();
    }

    private function apply(ControlRecord $record): void
    {
        if ($record->opcode === Opcode::SPAWN) {
            $this->startTask($record);

            return;
        }

        if ($record->opcode === Opcode::SHUTDOWN) {
            $this->stopping = true;

            return;
        }

        // WAKE and CLOSE belong to cross-process channels, which do not exist yet; there is nothing
        // to re-check, so ignoring them is the correct behaviour rather than a gap.
        //
        // RESULT and PANIC travel the other way. A parent that sends one is misbehaving, and taking
        // the worker down over a record it can simply not act on would turn a parent-side bug into
        // a lost pool.
    }

    private function startTask(ControlRecord $record): void
    {
        $slotId  = $record->slotId;
        $address = $record->value?->arenaAddress() ?? 0;

        ++$this->inFlight;

        $this->scheduler->spawn(function () use ($slotId, $address): void {
            try {
                $reply = $this->execute($slotId, $address);
            } finally {
                --$this->inFlight;
            }

            try {
                $this->control->send($reply);
            } catch (\Throwable) {
                // The parent is gone. The outcome has nowhere to go, and the EOF path is already
                // unwinding this process — losing the answer is the only thing left to do.
            }

            $this->finishIfIdle();
        });
    }

    /**
     * Run one task and decide what to send back. Total by construction: every path yields a record.
     */
    private function execute(int $slotId, int $address): ControlRecord
    {
        try {
            $value    = $this->tasks->taskAt($address)->run($this->runtime);
            $arenaTag = ValueCodec::arenaTagFor($value);

            // A string, array or object result travels by arena address, and the arena is #7. The
            // worker reports the *shape* it produced — a tag, never the value — and the waiting
            // process turns that tag into a message naming the ticket.
            return $arenaTag !== null
                ? new ControlRecord(Opcode::PANIC, $slotId, TaggedRecord::address($arenaTag, 0))
                : new ControlRecord(Opcode::RESULT, $slotId, ValueCodec::toRecord($value));
        } catch (\Throwable) {
            // A PANIC carrying NIL means "the task threw". The class, message and trace live in the
            // arena's shared error-info object once #7 lands; they are never serialized onto this
            // socket, which is why nothing about the throwable travels here today.
            return new ControlRecord(Opcode::PANIC, $slotId, TaggedRecord::nil());
        }
    }

    private function finishIfIdle(): void
    {
        if (!$this->stopping || $this->inFlight > 0) {
            return;
        }

        $this->unwatch();
    }

    private function unwatch(): void
    {
        if (!$this->watching) {
            return;
        }

        $this->watching = false;

        if ($this->control->isOpen()) {
            $this->scheduler->poller()->unwatch($this->control->stream());
        }
    }
}
