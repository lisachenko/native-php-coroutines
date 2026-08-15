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
 * How a task turns into an address, and an address back into a task.
 *
 * This is the seam the shared arena plugs into. A `SPAWN` record carries a slot id and an *address*
 * — never the task's bytes — so something has to answer two questions:
 *
 * - in the spawning process: at what address can every other process find this task?
 * - in the executing process: what task lives at this address?
 *
 * Ticket #7 answers both with the arena: {@see self::addressOf()} persists the task's object graph
 * into shared memory and returns its real address, and {@see self::taskAt()} attaches the object at
 * that address. Until then {@see PreforkTaskDirectory} answers them with fork inheritance, which is
 * the same discipline one layer down — publish before the fork, resolve by address afterwards.
 *
 * Whatever implements this, the rule it exists to protect is unchanged: **no implementation may
 * serialize the task.** An implementation that encodes the task into the address, or writes it to a
 * side channel, has moved the value onto the wire and defeated the whole design.
 */
interface TaskDirectory
{
    /**
     * The address under which every process in the tree can find this task.
     *
     * @throws \LogicException When the task is not reachable by address in the other processes —
     *                         which, without the arena, means it was not published before the fork.
     */
    public function addressOf(Task $task): int;

    /**
     * The task published at this address.
     *
     * @throws \OutOfBoundsException When nothing is published there.
     */
    public function taskAt(int $address): Task;
}
