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
 * Lifecycle state of a coroutine.
 *
 * The legal transitions are READY -> RUNNING -> {READY | BLOCKED | DONE} and BLOCKED -> READY.
 * There is no path out of DONE.
 */
enum CoroutineStatus
{
    /** On the run queue, waiting for its turn. */
    case READY;

    /** Currently executing; at most one coroutine per process is in this state. */
    case RUNNING;

    /** Parked on a primitive, a timer or the poller, and owned by it. */
    case BLOCKED;

    /** Finished, by return or by throw. Terminal. */
    case DONE;
}
