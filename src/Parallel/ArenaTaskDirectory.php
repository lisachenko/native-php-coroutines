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

use Lisachenko\NativePhpCoroutines\Exception\NotShareableValueException;
use Lisachenko\SharedData\Ipc\NotShareableValueException as SubstrateRefusal;
use Lisachenko\SharedData\NotPersistableException;

/**
 * How a task turns into an arena address, and an address back into a task.
 *
 * Two routes, and the first one is the one to reach for:
 *
 * 1. **published before the fork** ({@see self::register()}). The child has the object because it
 *    has the parent's whole heap, so the address is a handle into a table both processes already
 *    hold. Nothing is cloned and nothing can collide.
 * 2. **persisted into the arena** ({@see self::addressOf()} for a task the directory has never
 *    seen). The graph is cloned into shared memory and the address that comes back is a real
 *    pointer any process of the family can follow.
 *
 * Either way the task itself never travels: only the integer does, on a fixed-size record.
 *
 * # Route 2 is per instance, and each spawn keeps its memory until teardown
 *
 * A persisted task is an **instance graph** ({@see SharedArena::persist()} rides the substrate's
 * `persistInstance()`): its registry entry is named by its own root address, so a second task of
 * the same class is a second entry, never an upsert — any number of `new RenderJob(...)` are in
 * flight at once, and none supersedes a graph a worker is still reading. What route 2 costs
 * instead is arena memory per spawn: an instance graph lives until the family tears down, which
 * is the arena's ordinary leak-until-teardown economics and is what the watermark soak reports.
 * A steady-state workload spawning the same tasks forever wants route 1, which allocates nothing
 * per spawn.
 */
final class ArenaTaskDirectory implements TaskDirectory
{
    /**
     * Address => task, for tasks published before the fork.
     *
     * Holding a strong reference is what keeps those addresses stable for the whole life of the
     * process, in the parent and in every child that inherits the table.
     *
     * @var array<int, Task>
     */
    private array $published = [];

    /**
     * Task => address for published tasks, so registering the same task twice is idempotent.
     *
     * `@local-identity`: keyed by `spl_object_id()`, which is correct here because a *published*
     * task is an ordinary request-heap object that never enters the arena — it reaches the workers
     * through fork inheritance. A shared object may never be keyed this way: forked children
     * inherit one object-store free list and are handed identical handles for different objects, so
     * the identity of anything in the arena is its address ({@see SharedArena::addressOf()}).
     *
     * @var array<int, int>
     */
    private array $publishedAddresses = [];

    /**
     * Deliberately not 0 and deliberately spaced. A published address is opaque and is never
     * dereferenced; keeping it far from anything the arena hands out makes a confusion of the two
     * a lookup miss rather than a wild pointer.
     */
    private int $nextPublishedAddress = 0x1000;

    public function __construct(private readonly SharedArena $arena) {}

    /**
     * Publish a task so every worker can find it. Must be called **before** the fork.
     *
     * @return int The address to put in the `SPAWN` record.
     */
    public function register(Task $task): int
    {
        // @local-identity — see $publishedAddresses: a published task is request-heap, never arena.
        $key = spl_object_id($task);

        if (isset($this->publishedAddresses[$key])) {
            return $this->publishedAddresses[$key];
        }

        $address = $this->nextPublishedAddress;
        $this->nextPublishedAddress += 0x40;

        $this->published[$address]      = $task;
        $this->publishedAddresses[$key] = $address;

        return $address;
    }

    public function addressOf(Task $task): int
    {
        // @local-identity — see $publishedAddresses: a published task is request-heap, never arena.
        $key = spl_object_id($task);

        if (isset($this->publishedAddresses[$key])) {
            return $this->publishedAddresses[$key];
        }

        try {
            $shared = $this->arena->persist($task);
        } catch (SubstrateRefusal | NotPersistableException $refused) {
            throw new NotShareableValueException(sprintf(
                'the task %s cannot be cloned into the shared arena: %s. A task carries only values '
                . 'that can cross a worker boundary — scalars, shared objects, SharedArray — and '
                . 'never a plain array property, a resource or a post-fork closure',
                $task::class,
                $refused->getMessage(),
            ), 0, $refused);
        }

        return $this->arena->addressOf($shared);
    }

    public function taskAt(int $address): Task
    {
        $published = $this->published[$address] ?? null;

        if ($published !== null) {
            return $published;
        }

        $object = $this->arena->objectAt($address);

        return $object instanceof Task ? $object : throw new \OutOfBoundsException(sprintf(
            'the object at address 0x%X is a %s, which is not a Task',
            $address,
            $object::class,
        ));
    }

}
