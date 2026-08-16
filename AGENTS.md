# Working on native-php-coroutines

This package gives PHP Go-style concurrency on native `Fiber` and, above it, true parallelism across
forked workers that exchange **real PHP values** — never encoded bytes. Layer 1 (scheduler, poller,
channels, `select`, sync primitives, context) is plain PHP. Layer 2 (preemption) and the parallel
layer reach into the engine through [z-engine](https://github.com/lisachenko/z-engine) and a
fork-shared `mmap` arena, where a wrong assumption does not throw — it corrupts memory in every
sibling process at once.

Everything below is a rule with its reason attached. The reasons are experimental results, not
preferences: the preemption ones come from `spikes/` (see [`spikes/README.md`](spikes/README.md) for
how to run them and [`spikes/VERDICTS.md`](spikes/VERDICTS.md) for the measured results, with the raw
output of every run under `spikes/raw/`), the shared-memory ones from the substrate spikes in
`php-shared-data-extension#15`, four of whose nine corrections bind this package and are already
encoded in the docblocks of `src/Parallel/Protocol/Tag.php`,
`src/Parallel/Protocol/TaggedRecord.php` and `src/Exception/NotShareableValueException.php`. **Keep
this file and those docblocks saying the same thing.** These rules apply to human contributors and
automated agents alike.

## The one rule that is non-negotiable: the Never-Serialize Rule

> No value crossing a worker boundary may pass through `serialize()`, igbinary, JSON, or any
> byte-encoding of PHP value graphs. Cross-worker data is exchanged as real PHP values: scalars
> inline, strings as arena-copied `zend_string` addresses, arrays/objects as addresses of shared
> objects in a fork-shared mmap arena. Sockets carry only wake bytes and fixed-size event records
> (opcode + tag + address/slot id) — signaling, not serialization.

That wording is shared verbatim with the sibling extension repository. Do not paraphrase it here and
do not add an "escape hatch for hard cases": there is no fallback path. A value that cannot be shared
throws `NotShareableValueException`, and the exception's job is to **name the remedy** — `persist()`,
`SharedArray`, or a `Task` — so nobody has to read the internals to get unstuck.

## The tag table is the channel value contract

`src/Parallel/Protocol/Tag.php` is not an implementation detail; it *is* the set of things that may
cross a boundary. Anything not in it throws.

| Tag                | Payload                 | Cost                                         |
|--------------------|-------------------------|----------------------------------------------|
| NIL / TRUE / FALSE | none                    | zero — the tag is the value                  |
| INT / FLOAT        | the value, inline       | zero                                         |
| STR                | arena `zend_string*`    | one structural memcpy into the arena at send |
| OBJ                | arena `zend_object*`    | zero-copy: the address is the value          |
| ARR                | arena `SharedArray*`    | zero-copy                                    |
| CLOSE              | none                    | ring / protocol control                      |
| CLOSURE            | arena provenance record | zero-copy; pre-fork registrations only       |

**The tag numbers are the substrate's.** `Tag` and `Lisachenko\SharedData\Ipc\ValueTag` are one
table with two spellings — `NIL = 0 … CLOSE = 8`, `CLOSURE = 9` — and the substrate states that tag
numbers are this runtime's wire contract and are never renumbered. Compare them **numerically**, not
by name: `tests/Functional/testTheValueContractMatchesTheSubstrateNumerically.phpt` does, because a
drifted number does not fail, it reinterprets every record in flight.

**Sockets carry signals and event records, never value bytes.** A control frame is an opcode, a tag
and an address or slot id — fixed size, no user data beyond a scalar, never a graph. If a change
starts wanting to put "just a small payload" on the socket, the design has gone wrong: put the value
in the arena and send its address.

**There is exactly one record shape in this package, and it is the substrate's.** `ControlRecord` is
16 bytes — `uint8 opcode | uint8 tag | uint16 pad | uint32 id | uint64 address` — byte for byte the
substrate's `Ipc\WakeEvent`, which is what its channels, result slots and wake registry write onto
their inherited sockets. The parent ↔ worker control socket uses the same shape, so there are not two
decoders and no way to read one socket with the other's reader. `WAKE`, `RESULT`, `PANIC` and `CLOSE`
carry the same opcode byte as `Ipc\WakeOpcode`; `SPAWN` and `SHUTDOWN` are ours alone and sit at 16
and 17, clear of anything the substrate may append.

A plain `zend_array` is deliberately absent. The engine grows a HashTable's storage through
`pemalloc` into process-local heap with no hook to redirect it, and — this is the sharpened version
from the spikes — the growing table writes the private-heap `arData` pointer into the shared struct
**before** it aborts, so siblings go on reading plausible garbage with no signal at all. Silent
corruption, not a crash. `SharedArray` exists for exactly that.

### "Zero-copy" is not "free of rules"

`OBJ` being zero-copy means the address *is* the value. It does not mean touching it is free:

- **Never `var_dump()`, `json_encode()`, `get_object_vars()` or `(array)` a shared object** unless
  the extension's `get_properties_for` interception is active. Those read-shaped operations make
  engine C code *write* a per-process `properties` pointer into the shared struct, which segfaults
  every sibling that reads it afterwards. **This bites diagnostics hardest**: a panic handler, a
  deadlock dump, a debug trace or a test helper is exactly the code that reaches for `var_dump()` on
  the value it is reporting, and it will be running at the worst possible moment. Format shared
  values by reading named properties, never by dumping the object.
- **Object handles collide by construction after fork.** Children inherit the same object-store free
  list and hand out identical handle numbers, so `spl_object_id()` and `SplObjectStorage` are
  unusable for shared objects. **Arena address is the only cross-process identity.** (Layer 1's local
  `Context` does key children by `spl_object_id()` — that is correct precisely because those objects
  never leave the process.)
- **Reject closures on provenance, never on shape.** Only a closure compiled *before* the fork
  barrier can ever be shared. A post-fork closure cannot be recognised by inspection: the spikes
  found a stale address holding a different, perfectly valid `Closure`, which on PHP 8.5 executed the
  **wrong function** instead of failing. Any check that looks at bound variables, scope or arity will
  pass that case and hand the caller a silent wrong answer.

## In shared memory, the tag is the publication flag

A 16-byte record is **not** read atomically: roughly 1.3 % of unlocked reads in the spikes saw the
payload and the tag from different generations. A record living in a shared ring slot or result slot
is therefore only safe under one of two disciplines:

1. the whole access — write and read — happens under the slot's mutex; or
2. the writer stores the **payload first and the tag last**, and the reader loads the **tag first and
   the payload second**. A reader that sees the new tag is guaranteed to see the payload written
   before it.

**Never mix the two.** Writing the tag before the payload publishes a slot whose payload is still the
previous generation's, and no amount of re-reading detects it.

This does **not** apply to the control socket. There the 16 bytes are one frame of an ordered byte
stream, which cannot tear — `TaggedRecord::encode()`/`decode()` are for that path.

**Do not over-lock reads.** An aligned 8-byte pointer read *is* atomic: old-or-new, never a mix. A
single-slot address read whose tag cannot change may skip the lock, and taking one there costs
throughput for no safety.

## Preemption (Layer 2)

The mechanism was selected by experiment, and the negative result is the load-bearing one.

- **`Fiber::suspend()` from a pcntl signal handler is impossible** on both minors — "Cannot switch
  fibers in current execution context" — and the block **survives synchronous
  `pcntl_signal_dispatch()`**, so no PHP signal handler on any dispatch path can suspend a fiber.
  That is what makes preemption require z-engine while Layer 1 stays FFI-free.
- A signal handler may therefore only **set a flag and raise `EG(vm_interrupt)`**. The actual suspend
  happens inside the z-engine `InterruptHook` callback.
- **The whole callback body must be `try`/`catch`-wrapped.** A throwable escaping an FFI callback is
  an uncatchable "Throwing from FFI callbacks is not allowed" fatal — no `catch` upstream ever runs.
- **Always chain `proceed()`** so a previously installed handler still runs.
- **No autoloading inside the callback.** Preload every class the callback touches; triggering the
  autoloader from an engine hook re-enters the compiler.
- **Only ever `resume(null)` a preempt-suspended fiber. Never `Fiber::throw()` into one** — the spike
  found the exception either silently swallowed or the process killed. A fiber that was suspended by
  preemption did not ask to be interrupted and has no handler expecting it.
- **The scheduler must hold a strong reference to every preempted fiber and drain them before
  shutdown.** Dropping one is fatal, and uninstalling the hook first does not help.
- **Each forked worker re-arms its own timer** — `setitimer` intervals are cleared in the child.
- **10 ms is a target, not a guarantee.** A single internal opcode is not interruptible: `sort()`
  over 4M ints delayed preemption by 1.6–2.0 s. Never document or assume a bounded slice; document
  the caveat with it.

## Fork, layers and locks

- **Every participant must see the arena at the same virtual address, and must agree on the engine
  pointers baked into shared structs** — class entries, `std_object_handlers`. Under the
  Never-Serialize Rule an address *is* the value, so a shared object only means the same thing in
  another process when both hold. This is the requirement; it is not a statement about how you get
  there. The implementation gets both for free by forking — a child inherits its parent's mappings
  and its loaded classes — but `shm_open` plus `mmap(MAP_FIXED)`, `userfaultfd` and other mechanisms
  can put the same region at the same address in a process that was never forked from this one. A
  design that establishes the same two guarantees another way is legitimate. What may not be given
  up is the guarantees.
- **Prefork ordering is load-bearing**: the arena and the shared roots are created **before** the
  fork; fibers are created **after** it. A fiber that exists across the fork barrier is a stack the
  child now owns a copy of, and a shared root created after it is not shared at all.
- **Lock discipline — while holding a native lock:** never `Fiber::suspend()`, never call a user
  callback, never make an allocating engine call. A critical section is a memcpy and a pointer swap.
  A coroutine that suspends under a lock hands the CPU to a peer that will block on the same lock,
  and an allocation can re-enter the engine under a lock the engine knows nothing about.
- **Preemption is masked inside critical sections.** A time slice that expires mid-memcpy must not
  suspend the fiber holding a native lock.
- **Layer 1 makes no FFI calls — that is a property of the design, not of the installation.**
  `ext-ffi` is a hard requirement of this package regardless, because z-engine requires it and
  z-engine is a hard dependency. Say it that way; the opposite claim ("no FFI needed") has already
  been wrong here once.

## The parallel layer: what it composes, and in which order

`Runtime` builds the shared state in its **constructor** and forks in `run()`, which is what makes
the ordering above enforceable rather than aspirational:

1. arena, object store, wake registry, result slots, closure register — `new Runtime(workers: N)`;
2. shared roots and shareable closures — `declareShared()`, `registerSharedClosure()`,
   `publishTask()`, between construction and `run()`;
3. `SharedArena::sealBeforeFork()` and the fork — the first thing `run()` does;
4. fibers, in each process for itself.

Declaring a root or registering a closure after step 3 is **refused with a message saying why**, not
deferred: neither would reach a process that already exists.

That before/after line is expressed in the type system, and keep it there. **`TaskRuntime` is the
surface of code executing inside a run** — the main closure and every `Task::run()` receive it, and
they receive the same type because main runs after the fork, in exactly the regime a task runs in.
Configuration (`declareShared()`, `registerSharedClosure()`, `publishTask()`), lifecycle (`run()`)
and diagnostics (`arena()`, `supervisor()`, `workers()`) live only on the concrete `Runtime`, which
only the code that constructed it holds. Do not add a method to `TaskRuntime` unless calling it
mid-run, from any process of the family, is legitimate — and do not "fix" a task that wants
configuration by handing it the concrete class; `testTheTaskSurfaceCarriesNoConfigurationOrLifecycle`
pins the split. There are deliberately **no factory shortcuts on the runtime** (`channel()` and
friends): a local primitive is constructed on `$runtime->scheduler()`, a shared one arrives through
`$runtime->shared()`, and both reach calling code as plain values — that substitution is the reason
primitives take their scheduler explicitly. `preemptor()` *is* on the task surface, and it is
answered from the scheduler, not from constructor state: a worker's preemptor is built after the
fork against the child's own scheduler, so the scheduler is the only place the binding is truthful
in every process.

- **Classes whose instances travel must be loaded before the fork.** A shared clone carries one
  `zend_class_entry` for the whole family. `declareShared()` and `persist()` force the class; a class
  first autoloaded inside a worker is fine there and meaningless to its siblings.
- **A wakeup is a hint, never a delivery.** The substrate's wake sockets are level-triggered, so the
  poller **drains the pipe first** and re-checks shared state second. Draining after re-checking, or
  not at all, leaves the descriptor readable forever and spins the poller — a failure that looks like
  a busy runtime, which is why
  `tests/Functional/testTheWakePipeIsDrainedSoThePollerDoesNotSpin.phpt` asserts a *wakeup count* and
  not just that the values arrived.
- **The substrate's blocking helpers are spin loops and this runtime never calls them.** Parking a
  Fiber is the consumer's job, and it belongs on the poller. Because we are therefore not in the
  substrate's waiter tables, a state change made through this runtime is announced by a **family
  broadcast** — one fixed-size event per attached process, carrying an opcode, a tag and an address,
  never a value.
- **Preemption crosses the fork in two seams, and collapsing them is the trap.**
  `ProcessWorker::fork($id, $tasks, $afterFork, $afterScheduler)`: `$afterFork` runs before any
  scheduler exists and re-arms the process-global `ItimerClock`; `$afterScheduler` runs inside
  `WorkerChild::main()` once the child's own scheduler exists and builds a `Preemptor` **against that
  scheduler**. Re-arming the inherited parent `Preemptor` instead arms the timer correctly and leaves
  `shouldPreempt()` asking a scheduler that never runs anything, so the worker is never preempted and
  nothing reports it. A test that only asserts "the timer is armed" passes under that wiring; the one
  that counts is `testAWorkerInAPreemptivePoolIsActuallyPreempted.phpt`, which measures a ticker
  running *inside the worker* while a call-free loop is still going.
- **A result that can never arrive must become a throw.** A worker killed while holding an arena lock
  hands that lock on as `EOWNERDEAD`; recovering it is the substrate's job, surfacing it is ours. The
  supervisor re-reads shared memory before declaring anything lost — a worker killed *after* settling
  its slot did leave a real answer — and fails only what is genuinely unsettled.

### Known limits of the parallel surface, stated rather than discovered

- **A shared channel needs capacity ≥ 1.** The substrate's cross-process rendezvous only accepts a
  send while a sibling is parked inside *its* blocking `recv()`, which this runtime never calls.
  Capacity 0 is refused at declaration instead of delivered as a channel that usually does nothing.
- **Graphs are keyed per instance, and each unpublished spawn keeps its memory until teardown.**
  The substrate registers a persisted graph under a name minted from its own root address
  (`persistInstance()`), so any number of tasks of one class are in flight at once and none
  supersedes a graph a worker is still reading; shared *roots* are filed under the name they were
  declared with, so one class serves many roots. The cost sits where the arena's economics already
  are: every `spawnParallel()` of an unpublished task clones its graph into the arena and that
  memory lives until the family tears down — a steady-state workload publishes its tasks before
  the fork, which allocates nothing per spawn.
- **One `SharedError` per panic.** Each capture is its own instance graph, so two workers failing
  near-simultaneously each leave an error their waiter can still attach by the address its own
  slot carries.
- **Result slots are bump-allocated from a pre-sized table and never given back.** They are a bounded
  supply for the life of the arena, which is what `soak-arena-watermark.php` reports rather than
  assumes.

## Environment

### z-engine's line must match the running PHP minor

z-engine reads engine structures by byte offset and those offsets change on every PHP minor, so the
constraint is `8.4.x-dev || 8.5.x-dev` — Composer resolves the line matching the running PHP.
`ZEngine\Core::init()` enforces the exact match and refuses to boot on a mismatch. **Never loosen the
constraint, skip `Core::init()`, or defeat the guard to make a failure go away.** A refusal is the
guard working.

**Local-dev gotcha:** a single shared `vendor/` resolves to exactly one line (ours resolves to the
`8.4` branch), so `Core::init()` *correctly* refuses on PHP 8.5 with that tree. Testing engine-level
code on 8.5 locally needs a **second vendor tree resolved by 8.5** — the arrangement
`spikes/README.md` describes (`ze84/vendor`, `ze85/vendor`). CI is unaffected: each matrix runner
resolves its own dependencies.

Layer 1 code runs on both minors from either tree because it never enters z-engine. Do not read that
as permission to run Layer 2 or parallel code against a mismatched tree.

### INI settings

- `ffi.enable=1` — cannot be turned on at runtime.
- `opcache.jit=off` — the JIT rewrites the very executor internals the engine hooks depend on.
- `error_reporting=E_ALL & ~E_DEPRECATED` — the z-engine dev lines may report deprecations from
  dependency code, and PHPUnit's `.phpt` runner forces `display_errors=1`, so an unsuppressed
  deprecation is prepended to a test's captured output and fails an `--EXPECT--` block over noise.

```bash
php8.4 -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit
php8.5 -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit
```

### Coding standards are a local step, not a CI job

```bash
composer cs:fix      # run this before proposing a change
composer cs:check    # the same, without writing
composer phpstan     # level max, must be clean
```

CI runs **`tests` and `static-analysis` only**, both across PHP 8.4 and 8.5. The coding-standards job
was deliberately removed: php-cs-fixer is a development tool here, and re-checking on a runner only
turns a fixable formatting nit into a red build. **Do not re-add it.**

### A segfault is an engine-level bug, never a flaky test

A segfault or bus error means a hook or an engine structure is being used incorrectly. **Do not retry
the run hoping it passes, and do not mark the test skipped.** Capture the exact command, the PHP
version and a minimal reproducer, and report it.

## Tests

The suite is PHPUnit 12 driving `.phpt` files in `tests/Functional/`, one behaviour per file.

- **`--INI--` is mandatory and carries all three lines.** The child processes the runner spawns
  inherit nothing by luck. `tests/Functional/testEveryTestDeclaresTheThreeRequiredIniLines.phpt`
  scans the whole suite — itself included — and fails naming the file and the missing setting, so a
  new test cannot quietly omit one.
- **`test<WhatItDoes>.phpt`**, and the `--TEST--` line says the behaviour, not the class.
- **Prefer `--EXPECT--`** (exact match). Tests about errors `echo` the caught message rather than
  `var_dump()`ing it, so no string lengths need maintaining.
- **One behaviour per file.** Failure cases get their own file.
- **Bound everything.** A test that fills a buffer, waits for a wakeup or drains a socket needs a
  loop bound or a deadline: a hanging test costs far more than a failing one. Never let a test wait
  on a condition only a real deadline can satisfy.
- Layer 2 and parallel tests will need `ext-pcntl`; skip on its absence rather than assuming it.

**A `Coroutine::yield()` busy-loop is not a wait.** The scheduler fires timers and polls the kernel
only when the run queue is *empty*, so a coroutine that stays runnable starves every timer and every
IO wakeup in the process — measured: 200 000 spins and a 10 ms sleeper still had not fired. Wait on
the primitive (channel, `WaitGroup`, `select`, `Context`), never on a flag.

## Soak tooling

Not part of `composer test` — these run for a long time and are run deliberately.

```bash
php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-memory-flatness.php
php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-no-leftover-children.php
php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-arena-watermark.php
```

| Tool | Asserts | Exit codes |
| --- | --- | --- |
| `tools/soak-memory-flatness.php` | RSS and `memory_get_usage(true)` stay flat over sustained spawn/park/resume cycles; a monotonic climb or growth past the tolerance fails | 0 flat, 1 climbing, 2 inconclusive |
| `tools/soak-no-leftover-children.php` | no live child and no zombie survives a run | 0 clean, 1 leftovers, 2 inconclusive |
| `tools/soak-arena-watermark.php` | the arena watermark **plateaus** under a steady state, process memory stays flat, and no child survives. Leak-until-teardown is the design, so the criterion is a plateau, not zero growth | 0 plateau, 1 climbing or a leftover child, 2 inconclusive |

Every one of them can produce its own failure on demand (`--inject-leak`, `--self-test`). Use that
after changing the detection logic: a detector nobody has seen fail is a detector nobody knows works.
`soak-arena-watermark.php --self-test` rewrites a shared string property every round, which is the
substrate's documented per-write arena cost, and must come back FAIL.

## Repository map

```
src/Runtime.php            composition root: scheduler, preemptor, shared arena, worker pool
src/TaskRuntime.php        the execution surface: what main and every parallel task is handed
src/Scheduler.php          run queue, timer heap, the idle turn, deadlock detection
src/Coroutine.php          a Fiber plus park/unpark bookkeeping; unpark does not schedule
src/StreamPoller.php       the one stream_select() of the process; EINTR is routine, not an error
src/Channel.php            local channel: rendezvous handoff, buffered edges, close-as-broadcast
src/Select.php             multi-way wait; fairness shuffle, loser unlinking
src/Context.php            cancellation modelled as a channel that closes
src/Sync/                  WaitGroup, Once, Mutex — cooperative, FIFO, non-reentrant
src/Io.php, src/Timer.php  the static surfaces onto the active scheduler
src/Internal/              wait queues, wait nodes, deliveries, select cases
src/Parallel/SharedArena.php        the family's shared memory: pre-fork composition, the
                           per-process attach, the wake-socket bridge into the poller
src/Parallel/SharedChannel.php      a substrate ring behind ChannelInterface, so it drops into Select
src/Parallel/ArenaTaskDirectory.php task <-> arena address: pre-fork publication, or persist()
src/Parallel/Protocol/     Tag, TaggedRecord, Opcode, ControlRecord — the value contract
src/Exception/             the catchable failures, each naming its remedy
tests/Functional/*.phpt    the suite, one behaviour per file
tests/Support/             fakes for the unit-shaped tests
tools/                     soak tooling (memory flatness, process hygiene, arena watermark)
spikes/                    the preemption experiments and their verdicts; not run by composer test
```

## Conventions

[Conventional Commits](https://www.conventionalcommits.org/):

```
feat(scheduler): add the Layer 1 cooperative runtime
feat(channels): add local channels with direct rendezvous handoff
fix(poller): retry stream_select() with the remaining timeout after EINTR
test(tests): cover awaitWritable waking when the far end drains
docs: document the substrate spike corrections in the value contract
ci: run the suite on PHP 8.4 and 8.5 with ffi.enable=1
```

Scopes in use: `scheduler`, `channels`, `select`, `sync`, `context`, `poller`, `preemption`,
`parallel`, `contracts`, `tests`, `tools`, `spikes`, `ci`, `docs`.

Code style is PER-CS2.0, applied by php-cs-fixer. Run `composer cs:fix` rather than hand-formatting.

## Breaking changes are allowed — prefer the correct shape

This package and `lisachenko/php-shared-data-extension` ride development lines and have **no
external consumers**. Backwards compatibility is therefore not a constraint on either of them:
rename a method, narrow an interface, change a record layout, resize an id — whatever makes the
design right. Do not carry a deprecation cycle, do not keep a wrong method alive because something
might implement it, and do not invent an adapter to avoid touching a published shape. `LAYOUT_VERSION`
already exists to hard-fail a mismatched reader, which is the only compatibility mechanism this
family needs while it is being built.

Two things this does **not** license.

**`lisachenko/z-engine` is different.** It has consumers of its own, so a change there gets the
ordinary care — and, as ever, the fix for a missing capability is a named public method upstream
rather than a reach-through from here.

**None of this applies to the invariants.** The Never-Serialize Rule, the same-address requirement, the prefork
ordering, the `arData` law, the publication order, lock discipline, `EOWNERDEAD` handling and the
preemption obligations are not API contracts — they are the conditions under which this code is
correct at all. "Breaking changes are allowed" means the *shape* is negotiable. The rules above it in
this file are not.
