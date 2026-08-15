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

namespace Lisachenko\NativePhpCoroutines\Parallel\Protocol;

/**
 * The complete vocabulary of the parent/worker control socket.
 *
 * There is nothing else on that socket. Per the Never-Serialize Rule it carries **signals, not
 * values**: fixed-size records naming a slot and an address, plus single wake bytes. A payload of
 * PHP data on this channel would be a bug, not an optimisation.
 *
 * # The four shared numbers are the substrate's
 *
 * `WAKE`, `RESULT`, `PANIC` and `CLOSE` carry the same byte values as
 * `Lisachenko\SharedData\Ipc\WakeOpcode` (`Wake = 1`, `Result = 2`, `Panic = 3`, `Close = 4`),
 * because a {@see ControlRecord} and the substrate's `WakeEvent` are now the same 16 bytes and a
 * reader of either must read the same opcode out of them. `SPAWN` and `SHUTDOWN` have no substrate
 * counterpart — they are this package's parent → worker verbs — so they sit at 16 and 17, well
 * clear of any number the substrate may append.
 */
enum Opcode: int
{
    /** Either direction: a shared primitive changed state; drain the pipe and re-check readiness. */
    case WAKE = 1;

    /** Worker -> waiter: the slot is complete; the accompanying tag says where to look. */
    case RESULT = 2;

    /** Worker -> waiter: the slot ended in an uncaught Throwable; payload addresses the error info. */
    case PANIC = 3;

    /** Either direction: a shared channel was closed. */
    case CLOSE = 4;

    /** Parent -> worker: run the task at this arena address, complete this slot. */
    case SPAWN = 16;

    /** Parent -> worker: finish in-flight work and exit. The first rung of the shutdown ladder. */
    case SHUTDOWN = 17;
}
