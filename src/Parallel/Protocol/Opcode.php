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
 */
enum Opcode: int
{
    /** Parent -> worker: run the task at this arena address, complete this slot. */
    case SPAWN = 1;

    /** Worker -> waiter: the slot is complete; the accompanying tagged record says where to look. */
    case RESULT = 2;

    /** Worker -> waiter: the slot ended in an uncaught Throwable; payload addresses the error info. */
    case PANIC = 3;

    /** Parent -> worker: finish in-flight work and exit. The first rung of the shutdown ladder. */
    case SHUTDOWN = 4;

    /** Either direction: a shared channel changed state; drain the pipe and re-check readiness. */
    case WAKE = 5;

    /** Either direction: a shared channel was closed. */
    case CLOSE = 6;
}
