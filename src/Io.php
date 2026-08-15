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
 * Park a coroutine on stream readiness.
 *
 * These are the calls that turn a blocking read into a concurrent one: instead of the process
 * sitting in `fread()`, the coroutine is handed to the poller and every other coroutine keeps
 * running until the kernel says the descriptor is ready.
 *
 * The stream should be in non-blocking mode (`stream_set_blocking($stream, false)`); readiness only
 * promises that *a* read will not block, and a blocking descriptor can still stall on a short one.
 */
final class Io
{
    /**
     * Park until $stream has something to read (or has hit EOF).
     *
     * @param resource $stream
     */
    public static function awaitReadable($stream): void
    {
        Scheduler::active()->poller()->awaitReadable($stream);
    }

    /**
     * Park until $stream can accept a write.
     *
     * @param resource $stream
     */
    public static function awaitWritable($stream): void
    {
        Scheduler::active()->poller()->awaitWritable($stream);
    }
}
