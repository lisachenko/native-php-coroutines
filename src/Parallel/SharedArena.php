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

use Lisachenko\NativePhpCoroutines\PollerInterface;
use Lisachenko\NativePhpCoroutines\SchedulerInterface;
use Lisachenko\SharedData\Ipc\ClosureProvenance;
use Lisachenko\SharedData\Ipc\ResultSlotTable;
use Lisachenko\SharedData\Ipc\SharedArray;
use Lisachenko\SharedData\Ipc\SharedChannel as SubstrateChannel;
use Lisachenko\SharedData\Ipc\SharedError;
use Lisachenko\SharedData\Ipc\ValueCodec as SubstrateCodec;
use Lisachenko\SharedData\Ipc\ValueTag;
use Lisachenko\SharedData\Ipc\WakeEvent;
use Lisachenko\SharedData\Ipc\WakeOpcode;
use Lisachenko\SharedData\Ipc\WakeRegistry;
use Lisachenko\SharedData\PersistentStore;
use Lisachenko\SharedData\Registry;
use Lisachenko\SharedData\Shm\Arena;
use Lisachenko\SharedData\Shm\ArenaAllocator;

/**
 * Everything the worker family shares, composed **before the fork** and inherited by address.
 *
 * This class writes no shared-memory machinery of its own: the arena, the object store, the value
 * codec, the wake sockets, the result slots and the closure register all come from
 * `lisachenko/php-shared-data-extension`. What lives here is the *ordering* the substrate demands,
 * the per-process attach that must happen on the far side of a `fork()`, and the bridge from a
 * level-triggered wake socket to this process's one `stream_select()`.
 *
 * # The pre-fork order, and why every step of it is load-bearing
 *
 * 1. **the arena** — one `mmap(MAP_SHARED|MAP_ANONYMOUS)` region. Children inherit the mapping at
 *    the same virtual address, which is the entire reason eight bytes are a portable value.
 * 2. **the object store** — `PersistentStore::bootShared()`, which writes the module globals that
 *    anchor the arena. Only the creating process ever writes them; a child's write would go to a
 *    copy-on-write page and desynchronize the family with no error anywhere.
 * 3. **the wake registry** — its socket pairs are *inherited*, never handed over, so a registry
 *    created after a fork can never reach the processes that already exist.
 * 4. **the classes** — a shared clone carries one `zend_class_entry` for the whole family, so every
 *    class whose instances travel must be loaded here. {@see self::declareShared()} and
 *    {@see self::persist()} both force the class, and a class first autoloaded inside one worker is
 *    meaningless to its siblings.
 * 5. **the roots** — named channels, arrays and objects, published in the arena roots directory.
 * 6. **the fork barrier** — {@see self::sealBeforeFork()}, after which no closure may be registered
 *    and no root declared, because neither would reach a process that already exists.
 *
 * # How a wake socket becomes a coroutine wakeup
 *
 * The substrate's blocking helpers are spin loops, and it says outright that parking a Fiber belongs
 * to the consumer runtime. So this class registers **one** watch on the wake registry's stream with
 * the process's poller, and on readiness it **drains the pipe** and re-checks every registered
 * primitive. The pokes are level-triggered: an undrained pipe reports readable forever and spins the
 * poller, which is why {@see self::onWakeReadable()} drains before it re-checks and never after.
 *
 * # Notification is a family broadcast
 *
 * The substrate's primitives notify the wake slots parked in *their* waiter tables, and those tables
 * are filled by the substrate's blocking helpers — the ones this runtime deliberately does not use.
 * So a state change made through this runtime is announced to every *other* process that has
 * attached, through {@see self::notifyFamily()}: one fixed-size event per process per change,
 * carrying an opcode, a tag and an address, and never a value. This process is left out of its own
 * broadcast because it re-checks its own primitives synchronously; that keeps the wakeup count of a
 * bounded number of sends bounded. A spurious wakeup costs a re-check, a lost one would cost a
 * hang, so where the two trade off the broadcast is deliberately the wider.
 *
 * # Identity, and the operations that must never touch a shared object
 *
 * The identity of anything in here is its **arena address** ({@see PersistentStore::sharedIdOf()}).
 * `spl_object_id()` reads a deliberate sentinel out of the shared struct and an `SplObjectStorage`
 * keyed by a shared object collides by construction across a fork. Nothing in this class — nor in
 * the diagnostics it feeds — may `var_dump()`, `json_encode()`, `get_object_vars()` or `(array)` a
 * shared object either: engine C code answers those by *writing* a per-process `properties` pointer
 * into shared memory, and every sibling that reads it afterwards segfaults.
 */
final class SharedArena
{
    /**
     * The substrate layout this package is built against.
     *
     * Version 4 added the arena tables and 5 the per-object role. A reader of one layout against
     * another's bytes does not fail loudly: it reads the wrong fields out of the right addresses.
     * The gate is therefore a hard refusal naming both versions, never a compatibility shim.
     */
    public const int REQUIRED_LAYOUT_VERSION = 5;

    /** Arena size the runtime asks for by default: 64 MiB, the substrate's own default. */
    public const int DEFAULT_SIZE = 64 * 1024 * 1024;

    /** Wake slots reserved by default — one per process of the family, parent included. */
    public const int DEFAULT_WAKE_SLOTS = 32;

    /** Result slots reserved for the life of the arena. Pre-sized, never grown. */
    public const int DEFAULT_SLOT_COUNT = 4096;

    /** Closure provenance records reserved for the life of the arena. */
    public const int DEFAULT_CLOSURE_COUNT = 64;

    /** Longest root name the arena's roots directory accepts. */
    public const int MAX_ROOT_NAME = 39;

    private const string MODULE = 'coroutines_arena';

    private const string FAMILY_ROOT = 'coroutines.family';

    private const string WAKE_ROOT = 'coroutines.wake';

    private const string SLOTS_ROOT = 'coroutines.results';

    private const string CLOSURES_ROOT = 'coroutines.closures';

    private const string KIND_CHANNEL = 'channel';

    private const string KIND_ARRAY = 'array';

    private const string KIND_OBJECT = 'object';

    private readonly Arena $arena;

    private readonly PersistentStore $store;

    private readonly ArenaAllocator $allocator;

    private readonly ClosureProvenance $closures;

    private readonly SubstrateCodec $codec;

    private readonly WakeRegistry $wake;

    private readonly ResultSlotTable $slotTable;

    /** One entry per attached process, holding its wake slot; the broadcast list. */
    private readonly SharedArray $family;

    /**
     * Roots declared before the fork: name => descriptor. Every child inherits this table with the
     * rest of the parent's heap, so a worker resolving a root needs no lookup protocol at all.
     *
     * @var array<string, array{kind: string, class: class-string, address: int}>
     */
    private array $roots = [];

    /**
     * Roots materialized for *this* process. Cleared on attach, because the parent's instances name
     * the parent's object store and a child must bind its own.
     *
     * @var array<string, mixed>
     */
    private array $resolved = [];

    /** @var list<SharedChannel> Channels of this process to re-check when the wake pipe fires. */
    private array $channels = [];

    /** @var list<\Closure(): void> Anything else that re-checks on a wakeup — the slot table. */
    private array $listeners = [];

    private bool $sealed = false;

    private int $attachedPid = 0;

    /** @var resource|null The stream this process registered with its poller. */
    private mixed $watched = null;

    private ?PollerInterface $watchedBy = null;

    private ?SchedulerInterface $scheduler = null;

    private int $wakeups = 0;

    /**
     * Map the arena and build everything the family shares. **Call this before any fork.**
     *
     * @param int $size      Arena size in bytes; the whole family shares exactly this much.
     * @param int $wakeSlots Processes the wake registry can serve — the pool plus the parent.
     * @param int $slotCount Result slots reserved for the life of the arena.
     */
    public function __construct(
        int $size = self::DEFAULT_SIZE,
        int $wakeSlots = self::DEFAULT_WAKE_SLOTS,
        int $slotCount = self::DEFAULT_SLOT_COUNT,
    ) {
        self::assertLayoutVersion();

        $this->arena     = Arena::create($size);
        $this->store     = PersistentStore::bootShared($this->arena, null, self::MODULE);
        $this->allocator = new ArenaAllocator($this->arena);
        $this->closures  = ClosureProvenance::create(
            $this->allocator,
            $this->store,
            self::DEFAULT_CLOSURE_COUNT,
            self::CLOSURES_ROOT,
        );
        $this->codec     = new SubstrateCodec($this->allocator, $this->store, $this->closures);
        $this->wake      = WakeRegistry::create($this->arena, $wakeSlots, self::WAKE_ROOT);
        $this->slotTable = ResultSlotTable::create(
            $this->allocator,
            $this->codec,
            $this->wake,
            $slotCount,
            self::SLOTS_ROOT,
        );
        $this->family = SharedArray::create($this->allocator, $this->codec, $wakeSlots, self::FAMILY_ROOT);

        // The panic path persists one of these in whichever worker died, and the waiter attaches it
        // by address. Loaded here so the whole family agrees on its class entry.
        class_exists(SharedError::class);
    }

    /** The mapping itself; the soak tooling reads its watermark through this. */
    public function arena(): Arena
    {
        return $this->arena;
    }

    public function store(): PersistentStore
    {
        return $this->store;
    }

    public function allocator(): ArenaAllocator
    {
        return $this->allocator;
    }

    public function codec(): SubstrateCodec
    {
        return $this->codec;
    }

    public function slotTable(): ResultSlotTable
    {
        return $this->slotTable;
    }

    public function closures(): ClosureProvenance
    {
        return $this->closures;
    }

    /** How many times this process's poller has been woken by the wake pipe. */
    public function wakeups(): int
    {
        return $this->wakeups;
    }

    /**
     * Whether any lock this process took was inherited from an owner that died holding it.
     *
     * `EOWNERDEAD` recovery itself belongs to the substrate — it declares the mutex consistent and
     * carries on. Surfacing it is this package's job: a waiter on a slot the dead worker owed must
     * get a {@see \Lisachenko\NativePhpCoroutines\Exception\WorkerCrashedException}, never a
     * recovered-but-inconsistent value read as if it were an answer.
     */
    public function lockRecovered(): bool
    {
        return $this->slotTable->wasLockRecovered() || $this->wake->wasLockRecovered();
    }

    /**
     * The descriptor every shared primitive of this process signals through.
     *
     * @return resource
     */
    public function readinessFd()
    {
        $this->attach();

        return $this->wake->stream();
    }

    /**
     * Close registration and mark the fork barrier. Called once, immediately before the first fork.
     *
     * After this, a registered closure would be one the existing processes have never seen and a
     * declared root would be a root no worker can find, so both are refused rather than half-done.
     */
    public function sealBeforeFork(): void
    {
        if ($this->sealed) {
            return;
        }

        $this->sealed = true;
        $this->closures->markForkBarrier();
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    /**
     * Bind this process to the shared state. Idempotent per process, and re-run after a `fork()`.
     *
     * The pid guard is the whole point: a child inherits the parent's claimed wake slot, its
     * materialized root instances and its registered poller watch, and every one of those names the
     * parent rather than the child. Claiming a slot of its own also drains whatever the parent had
     * queued, so the child starts level instead of replaying the parent's backlog.
     */
    public function attach(): void
    {
        $pid = (int) getmypid();

        if ($this->attachedPid === $pid) {
            return;
        }

        $this->attachedPid = $pid;
        $this->resolved    = [];
        $this->channels    = [];
        $this->listeners   = [];
        $this->watched     = null;
        $this->watchedBy   = null;

        $this->registerInFamily($this->wake->slot());
    }

    /**
     * Register the wake socket with a scheduler's poller, once per process.
     *
     * From here on a coroutine parked on a shared primitive is parked in the same `stream_select()`
     * as one parked on a timer or a socket — there is no second event loop anywhere.
     */
    public function watchWith(SchedulerInterface $scheduler): void
    {
        $this->attach();

        $this->scheduler = $scheduler;

        $poller = $scheduler->poller();
        $stream = $this->wake->stream();

        if ($this->watched === $stream && $this->watchedBy === $poller) {
            return;
        }

        $poller->watchReadable($stream, function (): void {
            $this->onWakeReadable();
        });

        $this->watched   = $stream;
        $this->watchedBy = $poller;
    }

    /** The scheduler this process's shared primitives park coroutines on. */
    public function scheduler(): SchedulerInterface
    {
        return $this->scheduler ?? throw new \LogicException(
            'the shared arena has no scheduler yet; call watchWith() before using a shared primitive',
        );
    }

    /** @param SharedChannel $channel Re-checked whenever this process is woken. */
    public function registerChannel(SharedChannel $channel): void
    {
        $this->channels[] = $channel;
    }

    /** @param \Closure(): void $listener Re-checked whenever this process is woken. */
    public function registerListener(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Tell every *other* attached process that something it may be waiting on has changed.
     *
     * @param int $id      Slot or channel id the change belongs to.
     * @param int $payload Arena address, or 0 when the tag carries no address.
     */
    public function notifyFamily(WakeOpcode $opcode, int $id, ValueTag $tag, int $payload = 0): void
    {
        $this->attach();

        $mine  = $this->wake->slot();
        $event = new WakeEvent($opcode, $id, $tag, $payload);

        foreach ($this->familySlots() as $slot) {
            if ($slot !== $mine) {
                $this->wake->notify($slot, $event);
            }
        }
    }

    /** Re-check every shared primitive of this process and wake whoever can now make progress. */
    public function recheck(): void
    {
        foreach ($this->channels as $channel) {
            $channel->recheck();
        }

        foreach ($this->listeners as $listener) {
            $listener();
        }
    }

    /**
     * Clone an object graph into the arena and hand back the shared instance.
     *
     * Storage is keyed per instance: the substrate registers the graph under a name minted from
     * its own root address, so persisting a second instance of the same class is a second entry —
     * never an upsert — and any number of graphs of one class are live at once.
     *
     * The graph is persisted `mutable: true`, so a worker's writes are visible to the family
     * instead of being rolled back at request end. A bare `$object->prop = …` on the result is
     * legal and **unsynchronized** — visible for scalars, racy under contention; the synchronized
     * path is `$arena->store()->mutableHandle($object)`.
     *
     * @template TObject of object
     * @param TObject $object
     * @return TObject
     */
    public function persist(object $object): object
    {
        $this->attach();

        // Forces the class to exist in this process before its instance travels. In the parent this
        // happens before the fork, which is what gives the whole family one class entry for it.
        class_exists($object::class);

        // Per instance, not per class: each graph's registry entry is named by its own root
        // address, so persisting a second instance of one class never supersedes the first —
        // which is what lets two tasks of one class be in flight at once.
        return $this->store->persistInstance($object, true);
    }

    /** The arena address of a shared instance — the only identity that means anything across a fork. */
    public function addressOf(object $object): int
    {
        return $this->store->sharedIdOf($object);
    }

    /** Attach the object living at an arena address, in whichever process asks. */
    public function objectAt(int $address): object
    {
        $this->attach();

        return $this->store->attachObject($address);
    }

    /**
     * Make a closure shareable, by provenance.
     *
     * The closure must have been compiled by this process **before** the fork barrier. That is the
     * entire acceptance test, and it is not negotiable: a post-fork closure cannot be recognised by
     * inspection — the substrate spikes found a stale address holding a different, perfectly valid
     * `Closure` that on PHP 8.5 executed the *wrong function* rather than failing.
     *
     * @return int Address of the provenance record — what a `CLOSURE`-tagged value carries.
     */
    public function registerSharedClosure(string $name, \Closure $closure): int
    {
        if ($this->sealed) {
            throw new \LogicException(sprintf(
                'closure "%s" cannot be shared: the fork barrier has already been passed, and only '
                . 'a closure registered before it exists at the same address in every worker',
                $name,
            ));
        }

        return $this->closures->registerSharedClosure($name, $closure);
    }

    /**
     * Declare a named shared root, **before** the workers fork.
     *
     * @param class-string $class    Shared type to create: this package's {@see SharedChannel},
     *                               the substrate's `SharedArray`, or any class whose instance is
     *                               persisted into the arena and published under $name.
     * @param int          $capacity Slots for a channel or an array; ignored for an object root.
     */
    public function declareShared(string $name, string $class, int $capacity = 0): void
    {
        if ($this->sealed) {
            throw new \LogicException(sprintf(
                'shared root "%s" cannot be declared after the workers have forked: a root is '
                . 'inherited by address, so one created now exists only in this process. Declare '
                . 'every root before run() forks the pool',
                $name,
            ));
        }

        if ($name === '' || strlen($name) > self::MAX_ROOT_NAME) {
            throw new \InvalidArgumentException(sprintf(
                'a shared root name is 1 to %d bytes, got %d',
                self::MAX_ROOT_NAME,
                strlen($name),
            ));
        }

        if (isset($this->roots[$name])) {
            throw new \LogicException(sprintf('a shared root named "%s" has already been declared', $name));
        }

        if (!class_exists($class) && !interface_exists($class)) {
            throw new \InvalidArgumentException(sprintf(
                'shared root "%s" names %s, which does not exist; a class whose instances travel '
                . 'through the arena must be loaded before the fork',
                $name,
                $class,
            ));
        }

        [$kind, $address] = $this->createRoot($name, $class, $capacity);

        $this->roots[$name] = ['kind' => $kind, 'class' => $class, 'address' => $address];
    }

    /** Whether a root of this name was declared before the fork. */
    public function hasRoot(string $name): bool
    {
        return isset($this->roots[$name]);
    }

    /**
     * The shared root of this name, bound for *this* process.
     *
     * Resolution is by address through the per-process side table, so what comes back is the same
     * object every sibling sees rather than a copy of it — and the binding is rebuilt per process,
     * never cached across a fork.
     */
    public function shared(string $name): mixed
    {
        $this->attach();

        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        $root = $this->roots[$name] ?? throw new \OutOfBoundsException(sprintf(
            'no shared root named "%s" was declared; roots are declared before the workers fork, '
            . 'and the declared ones are: %s',
            $name,
            $this->roots === [] ? '(none)' : implode(', ', array_keys($this->roots)),
        ));

        return $this->resolved[$name] = $this->bindRoot($root);
    }

    /**
     * @param class-string $class
     * @return array{0: string, 1: int}
     */
    private function createRoot(string $name, string $class, int $capacity): array
    {
        if (is_a($class, SharedChannel::class, true) || is_a($class, SubstrateChannel::class, true)) {
            if ($capacity < 1) {
                throw new \InvalidArgumentException(sprintf(
                    'shared channel "%s" needs a capacity of at least 1: a cross-process rendezvous '
                    . 'accepts a send only while a sibling is parked inside the substrate\'s own '
                    . 'blocking recv(), and this runtime parks Fibers on its poller instead',
                    $name,
                ));
            }

            $channel = SubstrateChannel::create(
                $this->allocator,
                $this->codec,
                $this->wake,
                $capacity,
                SubstrateChannel::DEFAULT_WAITERS,
                $name,
            );

            return [self::KIND_CHANNEL, $channel->address()];
        }

        if (is_a($class, SharedArray::class, true)) {
            $array = SharedArray::create($this->allocator, $this->codec, max(1, $capacity), $name);

            return [self::KIND_ARRAY, $array->address()];
        }

        // Keyed by the ROOT NAME, not the class: two roots of one class are two entries, and the
        // name the application declared is exactly the name the registry files the graph under.
        $instance = new $class();
        $shared   = $this->store->persist($name, $instance, true);
        $address  = $this->store->sharedIdOf($shared);

        $this->arena->putRoot($name, $address);

        return [self::KIND_OBJECT, $address];
    }

    /**
     * @param array{kind: string, class: class-string, address: int} $root
     */
    private function bindRoot(array $root): mixed
    {
        return match ($root['kind']) {
            self::KIND_CHANNEL => new SharedChannel(
                $this,
                SubstrateChannel::attach($this->allocator, $this->codec, $this->wake, $root['address']),
            ),
            self::KIND_ARRAY => SharedArray::attach($this->allocator, $this->codec, $root['address']),
            default          => $this->store->attachObject($root['address']),
        };
    }

    /**
     * Drain the wake pipe, then re-check.
     *
     * The order is the whole contract of a level-triggered poke. A pipe that is re-checked but not
     * drained stays readable, so the poller returns immediately, forever — a spin that looks like a
     * busy runtime rather than like a bug. The events themselves are deliberately discarded: they
     * say "something changed somewhere", and the authoritative answer is in shared memory.
     */
    private function onWakeReadable(): void
    {
        ++$this->wakeups;

        $this->wake->drain();
        $this->recheck();
    }

    /**
     * Put this process's wake slot in the family table, if it is not there already.
     *
     * The table is a substrate `SharedArray` of fixed capacity — never a plain array, which the
     * engine would grow into whichever process filled it and leave its siblings reading garbage.
     */
    private function registerInFamily(int $slot): void
    {
        $capacity = count($this->family);

        for ($index = 0; $index < $capacity; ++$index) {
            $entry = $this->family[$index];

            if ($entry === $slot) {
                return;
            }

            if ($entry === null) {
                $this->family[$index] = $slot;

                return;
            }
        }

        throw new \RuntimeException(sprintf(
            'the wake registry is full at %d processes; size the runtime for the pool it forks',
            $capacity,
        ));
    }

    /** @return list<int> */
    private function familySlots(): array
    {
        $slots    = [];
        $capacity = count($this->family);

        for ($index = 0; $index < $capacity; ++$index) {
            $entry = $this->family[$index];

            if (is_int($entry)) {
                $slots[] = $entry;
            }
        }

        return $slots;
    }

    /**
     * The layout version of the substrate that is actually installed.
     *
     * Read through `constant()` on purpose. `Registry::LAYOUT_VERSION` is a compile-time constant of
     * whichever substrate release is deployed, so folding it at analysis time would turn the gate
     * below into a tautology and optimize away the very check that protects a deployment where the
     * two disagree. The version has to be read the way a running process reads it.
     */
    public static function substrateLayoutVersion(): int
    {
        $version = constant(Registry::class . '::LAYOUT_VERSION');

        return is_int($version) ? $version : throw new \RuntimeException(
            'the shared-data substrate does not expose an integer Registry::LAYOUT_VERSION',
        );
    }

    /**
     * Refuse a substrate whose shared layout is not the one this package reads.
     *
     * Not a warning and not a shim. Every table in the arena is addressed by offset, so a layout
     * this package was not built against is read at the right addresses with the wrong meanings —
     * silently, in every process at once.
     */
    private static function assertLayoutVersion(): void
    {
        $version = self::substrateLayoutVersion();

        if ($version === self::REQUIRED_LAYOUT_VERSION) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'the shared-data substrate is at layout version %d and this runtime is built against '
            . '%d; the arena tables are addressed by offset, so a mismatched layout is read at the '
            . 'right addresses with the wrong meanings. Install a matching '
            . 'lisachenko/php-shared-data-extension rather than relaxing this check',
            $version,
            self::REQUIRED_LAYOUT_VERSION,
        ));
    }
}
