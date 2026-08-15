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
 * # The one-graph-per-class limit of route 2, said out loud
 *
 * The substrate's registry is keyed by **class name**, so persisting a second instance of a class is
 * an upsert that supersedes the first — and superseding a task another worker is still running would
 * release the graph under its feet. This class therefore tracks what is in flight per class and
 * refuses the second concurrent task of one class with the remedy named: publish tasks before the
 * fork, or give the two tasks distinct classes. It is a refusal rather than a silent replacement
 * because the silent version corrupts memory in a process that is not the one making the mistake.
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
     * Class name => arena address of the task graph currently in flight under that key.
     *
     * @var array<class-string, int>
     */
    private array $inFlight = [];

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

        $class = $task::class;

        if (isset($this->inFlight[$class])) {
            throw new \LogicException(sprintf(
                '%s is already running in a worker, and the shared registry is keyed by class: '
                . 'persisting a second instance of it would release the graph the running worker '
                . 'is reading. Publish tasks before the fork with register(), or give the two '
                . 'tasks distinct classes',
                $class,
            ));
        }

        try {
            $shared = $this->arena->persist($task);
        } catch (SubstrateRefusal | NotPersistableException $refused) {
            throw new NotShareableValueException(sprintf(
                'the task %s cannot be cloned into the shared arena: %s. A task carries only values '
                . 'that can cross a worker boundary — scalars, shared objects, SharedArray — and '
                . 'never a plain array property, a resource or a post-fork closure',
                $class,
                $refused->getMessage(),
            ), 0, $refused);
        }

        $address = $this->arena->addressOf($shared);

        $this->inFlight[$class] = $address;

        return $address;
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

    /**
     * Report that the task under this class has finished, so the key is free again.
     *
     * Called by the supervisor when a slot settles. Without it the second spawn of a class would be
     * refused forever, which is a leak of the refusal rather than of memory.
     */
    public function releaseInFlight(int $address): void
    {
        foreach ($this->inFlight as $class => $registered) {
            if ($registered === $address) {
                unset($this->inFlight[$class]);
            }
        }
    }
}
