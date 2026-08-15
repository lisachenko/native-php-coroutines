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

use Lisachenko\NativePhpCoroutines\PollerInterface;

/**
 * A poller that never has anything to poll.
 *
 * Local channels report `readinessFd() === null` — their readiness is entirely in-process — so
 * every test in this ticket runs without a descriptor ever being registered. Any call here would
 * therefore mean a primitive reached for the kernel when it had no business doing so, and says so.
 */
final class FakePoller implements PollerInterface
{
    public function awaitReadable($stream): void
    {
        throw new \LogicException('A local channel must never wait on stream readiness');
    }

    public function awaitWritable($stream): void
    {
        throw new \LogicException('A local channel must never wait on stream readiness');
    }

    public function watchReadable($stream, \Closure $onReadable): void
    {
        throw new \LogicException('A local channel has no descriptor to watch');
    }

    public function unwatch($stream): void
    {
        throw new \LogicException('A local channel has no descriptor to watch');
    }

    public function poll(?float $timeout): int
    {
        return 0;
    }

    public function hasWatches(): bool
    {
        return false;
    }
}
