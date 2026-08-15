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
 * The arena's stand-in until ticket #7: tasks published before the fork, resolved by address after.
 *
 * A task registered here before {@see WorkerSupervisor::start()} is present in every child, because
 * `fork()` gives the child the parent's whole heap. The integer this class hands out is a *handle*
 * into a table both processes already have, which is exactly the shape of the arena address that
 * replaces it — same call sites, same records on the wire, same rule that no task bytes ever travel.
 *
 * # What it deliberately cannot do
 *
 * Registering a task **after** the workers have forked does not reach them: the parent's later
 * writes are private to the parent. {@see self::addressOf()} therefore refuses an unregistered task
 * with a message naming #7 rather than handing out an address the child cannot resolve. That
 * limitation is the entire reason the arena exists, and this class is honest about being a
 * placeholder for it.
 *
 * Replacing this with the arena implementation means: {@see self::addressOf()} calls
 * `RuntimeInterface::persist()` and returns the shared address; {@see self::taskAt()} attaches the
 * shared object at that address. Nothing else in this package changes.
 */
final class PreforkTaskDirectory implements TaskDirectory
{
    /**
     * Address => task. Holding a strong reference is what keeps the addresses stable for the whole
     * life of the process, in the parent and in every child that inherits the table.
     *
     * @var array<int, Task>
     */
    private array $tasks = [];

    /**
     * Task => address, so registering the same task twice is idempotent.
     *
     * `@local-identity`: an `SplObjectStorage` is keyed by the object-store handle, which is a
     * legitimate identity for these tasks precisely because they never enter the arena — they reach
     * the workers through fork inheritance. A *shared* object may never be kept this way: forked
     * children inherit one free list and are handed identical handles for different objects.
     *
     * @var \SplObjectStorage<Task, int>
     */
    private readonly \SplObjectStorage $addresses;

    /**
     * Deliberately not 0 and deliberately spaced: an address here is opaque, and making it look
     * nothing like an index discourages code from treating it as one before #7 turns it into a real
     * pointer.
     */
    private int $nextAddress = 0x1000;

    public function __construct()
    {
        // @local-identity — see $addresses: these tasks never enter the arena.
        /** @var \SplObjectStorage<Task, int> $addresses */
        $addresses       = new \SplObjectStorage();
        $this->addresses = $addresses;
    }

    /**
     * Publish a task so the workers can find it. Must be called **before** the fork.
     *
     * @return int The address to put in the `SPAWN` record.
     */
    public function register(Task $task): int
    {
        if ($this->addresses->contains($task)) {
            return $this->addresses[$task];
        }

        $address = $this->nextAddress;
        $this->nextAddress += 0x40;

        $this->tasks[$address]  = $task;
        $this->addresses[$task] = $address;

        return $address;
    }

    public function addressOf(Task $task): int
    {
        if (!$this->addresses->contains($task)) {
            throw new \LogicException(sprintf(
                '%s was never published to the workers; without the shared arena (see #7) a task '
                . 'can only reach a worker by being registered before the fork',
                $task::class,
            ));
        }

        return $this->addresses[$task];
    }

    public function taskAt(int $address): Task
    {
        return $this->tasks[$address] ?? throw new \OutOfBoundsException(
            sprintf('no task is published at address 0x%X', $address),
        );
    }
}
