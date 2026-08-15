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

namespace Lisachenko\NativePhpCoroutines\Internal;

use Lisachenko\NativePhpCoroutines\ChannelInterface;

/**
 * One arm of a `select`: a channel, a direction, and what to run if it wins.
 *
 * @internal
 */
final class SelectCase
{
    /**
     * @param ChannelInterface<mixed> $channel   Any channel, local or shared — `select` deliberately
     *                                           knows nothing beyond the interface.
     * @param bool                    $isSend    Direction; a receive case ignores $value.
     * @param mixed                   $value     The value a send case will hand over.
     * @param \Closure                $handler   `\Closure(mixed, bool): mixed` for a receive case,
     *                                           `\Closure(): mixed` for a send case.
     */
    public function __construct(
        public readonly ChannelInterface $channel,
        public readonly bool $isSend,
        public readonly mixed $value,
        public readonly \Closure $handler,
    ) {}

    /** Whether this case would complete right now without parking. */
    public function isReady(): bool
    {
        return $this->isSend ? $this->channel->canSend() : $this->channel->canRecv();
    }
}
