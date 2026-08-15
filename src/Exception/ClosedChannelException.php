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

namespace Lisachenko\NativePhpCoroutines\Exception;

/**
 * Raised on an illegal operation against a closed channel.
 *
 * Receiving from a closed channel is *not* an error — it drains and then reports `[null, false]`.
 * Sending to one is, and so is closing one twice: both mean a producer believes it still owns a
 * channel that somebody else has already retired.
 */
final class ClosedChannelException extends \RuntimeException implements CoroutineException
{
    public static function onSend(): self
    {
        return new self('send on closed channel');
    }

    public static function onClose(): self
    {
        return new self('close of closed channel');
    }

    /** The channel was closed by someone else while this coroutine was parked in send. */
    public static function whileParked(): self
    {
        return new self('send on channel closed while waiting');
    }
}
