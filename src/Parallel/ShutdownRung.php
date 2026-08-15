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

/**
 * How far down the shutdown ladder a worker had to be pushed before it went.
 *
 * {@see WorkerSupervisor::shutdown()} reports one of these per worker. Making the rung an explicit
 * outcome rather than an internal detail is what lets a test assert that the polite rung was tried
 * first and that a worker ignoring `SIGTERM` really does reach `SIGKILL` — and what lets an operator
 * see, in a log, that a pool is routinely being killed rather than asked.
 */
enum ShutdownRung: string
{
    /** The worker had already exited before the ladder started. */
    case ALREADY_GONE = 'already-gone';

    /** It obeyed the `SHUTDOWN` record within the grace period. Nothing was signalled. */
    case SHUTDOWN = 'shutdown';

    /** It ignored the record, and `SIGTERM` ended it. */
    case SIGTERM = 'sigterm';

    /** It ignored `SIGTERM` too, and `SIGKILL` ended it. This rung cannot be refused. */
    case SIGKILL = 'sigkill';
}
