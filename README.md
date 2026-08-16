# native-php-coroutines

Go-style coroutines for PHP: **concurrency** within a process on native `Fiber`, and **true
parallelism** across forked workers that exchange real shared PHP objects — with **zero
serialization** on the data path.

> **Status: in implementation.** All three layers are merged — the cooperative runtime, preemptive
> time slices, and the parallel layer over a fork-shared arena. `new Runtime()` composes exactly what
> Layer 1 always did: no arena, no FFI, no workers. `workers: N` maps the arena and forks the pool;
> `preemptive: true` adds the engine hook. See the
> [EPIC](https://github.com/lisachenko/native-php-coroutines/issues/1) for the board.

## The model, in one sentence

Coroutines are **concurrent within a worker**; workers are **parallel across processes**.

Every process runs its own Fiber-based scheduler whose single blocking point is one
`stream_select()`. Values that cross a process boundary are not encoded — they live in a
fork-shared `mmap` arena and travel as addresses.

## Installation

```bash
composer require lisachenko/native-php-coroutines
```

z-engine is required as `8.4.x-dev || 8.5.x-dev` — one development line per supported PHP minor,
resolved by Composer to match the running PHP — so a consuming project needs
`"minimum-stability": "dev"` with `"prefer-stable": true` at its root, as Composer only resolves
development stability there.

## Quick start

```php
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;

require __DIR__ . '/vendor/autoload.php';

$runtime = new Runtime();

$runtime->run(function (TaskRuntime $runtime): void {
    $group = new WaitGroup($runtime->scheduler());

    foreach ([3, 1, 2] as $id) {
        $group->add();

        Coroutine::spawn(function () use ($group, $id): void {
            try {
                Coroutine::sleep($id * 0.01);
                echo "worker {$id} finished", PHP_EOL;
            } finally {
                $group->done();
            }
        });
    }

    $group->wait();
    echo 'all workers joined', PHP_EOL;
});
```

```
worker 1 finished
worker 2 finished
worker 3 finished
all workers joined
```

Three things in that snippet are the whole shape of the API:

- **`Runtime::run()` takes the main coroutine** and hands it a `TaskRuntime` — the narrow surface of
  code executing *inside* the runtime, the same type a parallel task receives. Configuration
  (`declareShared()`, `registerSharedClosure()`, `publishTask()`) and `run()` itself stay on the
  concrete `Runtime`, because from inside a run each of them is a bug: main runs after the fork, so
  a root declared there would exist in one process only, and a nested `run()` would start a runtime
  inside a coroutine of the first. When main returns, the run is over — Go semantics, deliberately:
  whatever is still queued, sleeping or parked is **discarded**, not awaited. A program that wants
  to wait says so, with a `WaitGroup` or a channel.
- **`Coroutine::spawn()`, `Coroutine::yield()` and `Coroutine::sleep()` are static**, because there
  is exactly one scheduler per process and they talk to the active one. `sleep()` parks on the timer
  heap, so a program that is only sleeping blocks in the kernel and burns no CPU.
- **The primitives take a scheduler explicitly.** `Channel`, `Select`, `Context`, `WaitGroup`, `Once`
  and `Mutex` are all constructed with `$runtime->scheduler()` (a `SchedulerInterface`). Nothing is
  looked up from a global inside them, which is what will let a shared, cross-process channel be
  substituted for a local one without changing a line of the calling code. This is also why there
  are deliberately no factory shortcuts on the runtime (`$runtime->channel()` and friends): a local
  primitive is constructed on a scheduler, a shared one arrives through `$runtime->shared()`, and
  both reach the calling code as plain values — one rule, visible at the construction site, for
  every primitive present and future.

## Channels

Capacity 0 is a **rendezvous**: the value is handed over directly, from the sender's frame into the
receiver's, without entering storage. Capacity *n* is a **buffer**: a send parks only when the buffer
is full, a receive only when it is empty. `foreach` drains a channel until it is closed *and* empty.

```php
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

require __DIR__ . '/vendor/autoload.php';

(new Runtime())->run(function (TaskRuntime $runtime): void {
    $scheduler = $runtime->scheduler();

    /** @var Channel<string> $jobs */
    $jobs = new Channel($scheduler);          // capacity 0: a rendezvous, the value is handed over
    /** @var Channel<string> $results */
    $results = new Channel($scheduler, 2);    // capacity 2: the producer may run two ahead

    Coroutine::spawn(function () use ($jobs, $results): void {
        foreach ($jobs as $job) {             // ends when the channel is closed and drained
            $results->send(strtoupper($job));
        }

        $results->close();
    });

    Coroutine::spawn(function () use ($jobs): void {
        foreach (['alpha', 'beta', 'gamma'] as $job) {
            $jobs->send($job);
        }

        $jobs->close();
    });

    foreach ($results as $result) {
        echo $result, PHP_EOL;
    }

    [$value, $ok] = $results->recvOk();
    echo 'after close: ', var_export($value, true), ' ok=', var_export($ok, true), PHP_EOL;
});
```

```
ALPHA
BETA
GAMMA
after close: NULL ok=false
```

`recv()` returns `null` on a closed, drained channel — indistinguishable from a legitimately sent
`null`, which is why `recvOk()` exists and returns the value together with a liveness flag. Closing
is a broadcast: receivers keep draining what is buffered (still with `ok = true`) and only then see
`[null, false]`, while a send on a closed channel throws `ClosedChannelException`, including for a
producer that was already parked when somebody else closed it.

## Select and cancellation

`Select` waits on several channel operations and takes the first that can proceed; ready cases are
shuffled, so two permanently ready channels cannot starve each other. A `Context` is cancellation
modelled as a channel that closes — which is what makes it selectable for free, and why a
cancellation that arrives before anybody looks is not lost.

```php
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Channel;
use Lisachenko\NativePhpCoroutines\Context;
use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Select;

require __DIR__ . '/vendor/autoload.php';

(new Runtime())->run(function (TaskRuntime $runtime): void {
    $scheduler = $runtime->scheduler();

    $request = Context::withCancel($scheduler);
    /** @var Channel<int> $jobs */
    $jobs = new Channel($scheduler);

    Coroutine::spawn(function () use ($jobs): void {
        foreach ([1, 2, 3] as $job) {
            Coroutine::sleep(0.01);
            $jobs->send($job);
        }
    });

    Coroutine::spawn(function () use ($request): void {
        Coroutine::sleep(0.025);
        $request->cancel();                   // cancels this context and every child below it
    });

    while (true) {
        $keepGoing = Select::on($scheduler)
            ->recv($request->done(), function (): bool {
                echo 'cancelled', PHP_EOL;

                return false;
            })
            ->recv($jobs, function (mixed $job, bool $ok): bool {
                echo 'job ', var_export($job, true), ' ok=', var_export($ok, true), PHP_EOL;

                return $ok;
            })
            ->run();

        if (!$keepGoing) {
            return;
        }
    }
});
```

```
job 1 ok=true
job 2 ok=true
cancelled
```

`->send($channel, $value, $handler)` adds a send case, and `->default($handler)` makes the whole
statement non-blocking: with a default, `select` never parks. A `select` that parks registers a
waiter on **every** case and unlinks the losers before returning, so one inside a loop leaves nothing
behind.

`Context::withTimeout($parent, $seconds, $sleeper)` is the deadline variant. The sleeper is a
parameter — `Coroutine::sleep(...)` as a first-class callable — because a timeout is a timer, and
timers belong to the scheduler that owns the clock rather than to a private one invented inside the
cancellation code.

## Sync primitives

Cooperative code does not need a lock around a plain critical section: nothing preempts a coroutine
between two statements. It needs one around a section that **suspends**.

```php
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Sync\Mutex;
use Lisachenko\NativePhpCoroutines\Sync\Once;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;

require __DIR__ . '/vendor/autoload.php';

(new Runtime())->run(function (TaskRuntime $runtime): void {
    $scheduler = $runtime->scheduler();

    $once  = new Once($scheduler);
    $mutex = new Mutex($scheduler);
    $group = new WaitGroup($scheduler);

    for ($id = 1; $id <= 3; ++$id) {
        $group->add();

        Coroutine::spawn(function () use ($once, $mutex, $group, $id): void {
            try {
                $connection = $once->do(function (): string {
                    echo 'opening the connection once', PHP_EOL;
                    Coroutine::sleep(0.01);   // the initializer may suspend; later callers park

                    return 'connection';
                });

                $mutex->lock();

                try {
                    // A critical section that suspends is exactly what needs the lock.
                    Coroutine::sleep(0.001);
                    echo "coroutine {$id} used the {$connection}", PHP_EOL;
                } finally {
                    $mutex->unlock();
                }
            } finally {
                $group->done();
            }
        });
    }

    $group->wait();
});
```

```
opening the connection once
coroutine 1 used the connection
coroutine 2 used the connection
coroutine 3 used the connection
```

- **`WaitGroup`** — `add()` **before** spawning, `done()` in a `finally`. A counter that would go
  negative throws instead of clamping, because going negative means a double `done()` and clamping
  would hide it behind a `wait()` that returned slightly too early.
- **`Once`** — later callers **block** until the initializer finishes rather than skipping ahead with
  a half-initialised result. A failed initializer is recorded and replayed to everybody: the `Once`
  stays spent, nothing is retried.
- **`Mutex`** — FIFO with direct handoff, so a coroutine locking in a tight loop cannot barge ahead
  of the queue. It is **non-reentrant and loud about it**: locking a mutex you already hold throws
  `DeadlockException` at the offending `lock()` instead of hanging and blaming somebody else later.

## Non-blocking IO

`Io::awaitReadable()` and `Io::awaitWritable()` hand the descriptor to the poller and park the
coroutine, so the process never sits inside `fread()`. Put the stream in non-blocking mode:
readiness only promises that *a* read will not block.

```php
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Coroutine;
use Lisachenko\NativePhpCoroutines\Io;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;
use Lisachenko\NativePhpCoroutines\Sync\WaitGroup;

require __DIR__ . '/vendor/autoload.php';

[$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
stream_set_blocking($client, false);
stream_set_blocking($server, false);

(new Runtime())->run(function (TaskRuntime $runtime) use ($client, $server): void {
    $group = new WaitGroup($runtime->scheduler());
    $group->add(2);

    Coroutine::spawn(function () use ($server, $group): void {
        try {
            Io::awaitReadable($server);       // parked on the poller; burns no CPU
            echo 'server read: ', fread($server, 64), PHP_EOL;
        } finally {
            $group->done();
        }
    });

    Coroutine::spawn(function () use ($client, $group): void {
        try {
            Io::awaitWritable($client);       // parked until the kernel will take a write
            fwrite($client, 'ping');
        } finally {
            $group->done();
        }
    });

    $group->wait();
});

fclose($client);
fclose($server);
```

```
server read: ping
```

The scheduler blocks in exactly one place: `stream_select()`, with the earliest timer deadline as its
timeout. When there is neither a deadline nor a registered descriptor and coroutines are still
blocked on local primitives, that is a **deadlock**, and it is reported as one —
`DeadlockException` names every blocked coroutine, what it waits on and where it was spawned. A
coroutine parked on IO is excluded from that verdict: its wakeup was never the scheduler's to
produce.

## The Never-Serialize Rule

> No value crossing a worker boundary may pass through `serialize()`, igbinary, JSON, or any
> byte-encoding of PHP value graphs. Cross-worker data is exchanged as real PHP values: scalars
> inline, strings as arena-copied `zend_string` addresses, arrays/objects as addresses of shared
> objects in a fork-shared mmap arena. Sockets carry only wake bytes and fixed-size event records
> (opcode + tag + address/slot id) — signaling, not serialization.

`Parallel\Protocol\Tag` is that rule as code: `NIL`/`TRUE`/`FALSE` and `INT`/`FLOAT` cost nothing,
`STR` is one structural memcpy into the arena, `OBJ` and `ARR` are zero-copy addresses, and anything
else throws `NotShareableValueException` naming the remedy.

## Parallelism across workers

```php
use Lisachenko\NativePhpCoroutines\Parallel\SharedChannel;
use Lisachenko\NativePhpCoroutines\Parallel\Task;
use Lisachenko\NativePhpCoroutines\Runtime;
use Lisachenko\NativePhpCoroutines\TaskRuntime;

final class Report
{
    public int $rows = 0;
    public string $status = 'pending';
}

final class BuildReport implements Task
{
    public function run(TaskRuntime $runtime): mixed
    {
        $report = $runtime->shared('report');

        // The synchronized write path. A bare $report->rows = ... is legal and visible, but it is
        // unsynchronized: a shared object is rewired to std_object_handlers, so there is no write
        // hook that could take the stripe lock for you.
        $handle = $runtime->mutableHandle($report);
        $handle->writeScalar('rows', 128);
        $handle->writeString('status', 'done');

        return $report;
    }
}

$runtime = new Runtime(workers: 4);

// Everything shared is created BEFORE run() forks. A root declared afterwards would exist only in
// the process that declared it, so it is refused with a message that says so.
$runtime->declareShared('report', Report::class);
$runtime->declareShared('jobs', SharedChannel::class, 64);
$runtime->publishTask($task = new BuildReport());

$report = $runtime->shared('report');

$runtime->run(function (TaskRuntime $runtime) use ($task, $report): void {
    $returned = $runtime->spawnParallel($task)->await();

    var_dump($returned === $report);  // true — the address is the value, not a copy of it
    echo $report->status;             // "done", written in another process
});
```

- **`spawnParallel()` returns a `JoinHandle`.** `await()` parks the calling coroutine on the poller,
  wakes on the settled slot and reads the value **straight out of shared memory**. A slot that is
  already settled returns without parking, and a slot may be awaited from any process of the family —
  `attachResult($slotId)` is the handle for that.
- **A `SharedChannel` is a `ChannelInterface`**, so it drops into `Select` next to a local `Channel`
  and one `select` statement can mix the two. Its `readinessFd()` is the arena's wake socket, which
  the poller drains on every readiness — level-triggered pokes, so an undrained pipe would spin.
- **A panic in a task** surfaces at `await()` as `ParallelTaskException` carrying the original class,
  message and trace, moved into the arena as three arena strings. The `Throwable` itself never
  crosses, and nothing on that path is serialized.
- **A worker that dies owing a result** fails its waiter with `WorkerCrashedException` rather than
  leaving a coroutine parked for the rest of the run — including a worker killed while it held an
  arena lock.
- **Closures cross only by provenance.** `registerSharedClosure($name, $closure)` before the fork
  makes one shareable for the life of the family; a closure created afterwards is refused, because no
  inspection can tell it apart from a stale address holding a different, perfectly valid `Closure`.

## Layers

| Layer | What it gives you | FFI |
| --- | --- | --- |
| **1 — cooperative runtime** | scheduler, channels, `select`, timers, IO parking, deadlock detection | **no FFI calls** |
| **2 — preemption** | ~10 ms time slices, so a call-free loop cannot starve its peers | z-engine `InterruptHook` |
| **P — parallelism** | prefork workers, shared objects, shared channels, result slots | arena + engine hooks |

**Layer 1 makes no FFI calls** — no z-engine, no engine hooks, just `Fiber` and `stream_select()`.
That is a property of the design, not of the installation: `ext-ffi` is required regardless, because
z-engine requires it, and z-engine is a hard dependency of this package.

## Requirements

- PHP **8.4** or **8.5**, NTS.
- `ext-ffi` with `ffi.enable=1`, and `opcache.jit=off` — the JIT rewrites the executor internals the
  engine hooks depend on.
- `lisachenko/z-engine`, resolved per PHP minor (`8.4.x-dev` on 8.4, `8.5.x-dev` on 8.5) — engine
  structures are read by byte offset, so the line must match the running minor. `ZEngine\Core::init()`
  enforces it and refuses to boot on a mismatch.
- `ext-pcntl` and `ext-posix` for the parallel and preemption layers (suggested, not required).
  Preemption needs `ext-pcntl` specifically: the slice timer is delivered as `SIGALRM`.

## Limits worth knowing up front

- **Cooperation is real cooperation, until Layer 2.** A coroutine that never suspends keeps the CPU.
  Worse, a coroutine that only ever `yield()`s in a busy-wait keeps the run queue non-empty, and
  timers and IO wakeups are processed **only when the run queue empties** — measured: 200 000 spins
  and a 10 ms sleeper still had not fired. Wait on the primitive, never on a flag.
- **When main returns, the run is over.** Pending coroutines are dropped and never resumed, so their
  `finally` blocks do not run — exactly as a goroutine's deferred calls do not run when `main`
  returns.
- **An uncaught throwable is a panic**: it ends the run and comes back out of `Runtime::run()`.
- **Every participant must see the arena at the same address**, and must agree on the engine pointers
  inside shared structs (class entries, object handlers) — an address is the value, so both have to
  hold for a shared object to mean the same thing twice. Forked workers get both for free, which is
  how the runtime works today; it is a requirement, not a restriction to forking forever.
- **Plain arrays are not shareable.** Use `SharedArray`; a plain array grows into the private heap of
  whichever process filled it. Closures are shareable only by **pre-fork registration**
  (`registerSharedClosure()`); work created after the fork travels as a `Task`. Anything else throws
  `NotShareableValueException` naming the remedy.
- **A shared channel needs capacity ≥ 1.** A cross-process rendezvous only accepts a send while a
  sibling is parked inside the substrate's own blocking `recv()`, and this runtime parks Fibers on its
  poller instead — so capacity 0 is refused rather than delivered as a channel that usually does
  nothing.
- **`persist()` is per instance, roots are per name.** Two `RenderJob`s — or twenty — are twenty
  graphs, none superseding another, and two roots of one class are two roots. What a design pays
  for spawning arbitrary unpublished tasks is arena memory per spawn, held until teardown;
  `publishTask()` before the fork allocates nothing per spawn.
- **The arena is a bump allocator with no free list.** Blocks are reclaimed when the region dies with
  the creating process, and rewriting a shared string property costs a block per write. Size for it,
  and watch the watermark **plateau** rather than expecting it to fall.
- **Never `var_dump()`, `json_encode()`, `get_object_vars()` or `(array)` a shared object** — those
  read-shaped operations make engine C code write a per-process `properties` pointer into the shared
  struct and segfault every sibling. Diagnostics are the code most likely to do it.
- **The JIT must be off** wherever the engine hooks are used — it rewrites the executor internals
  those hooks depend on.
- **The 10 ms slice is a target, not a bound.** Preemption happens between opcodes, so a single
  long-running one is not interruptible: `sort()` over four million integers defers a preemption by
  around two seconds. Every *loop* shape is interrupted promptly, including an empty
  `while (true) {}`, so no program can starve the scheduler — but do not size a latency SLO on the
  slice.
- **Preemption is opt-in** (`new Runtime(preemptive: true)`) and, once armed, makes coroutine
  lifetimes the scheduler's business: a preempted coroutine is suspended inside an engine callback,
  so it is drained rather than discarded when a run ends.
- **`workers: 0` maps no arena at all.** The shared surface is then refused with a message naming the
  remedy rather than half-composed — a cooperative runtime stays exactly as cheap as it was.

## Development

```bash
php8.4 -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit   # the suite, on both minors
php8.5 -d ffi.enable=1 -d opcache.jit=off vendor/bin/phpunit
composer phpstan                                               # level max
composer cs:fix                                                # PER-CS2.0, a local step
```

Coding standards are deliberately **not** a CI job — CI runs `tests` and `static-analysis` across
PHP 8.4 and 8.5. A segfault is an engine-level bug, never a flaky test: capture the command, the PHP
version and a minimal reproducer instead of retrying.

Contributor rules — the engine contracts, the shared-memory disciplines, the preemption obligations
and the test discipline — live in [`AGENTS.md`](AGENTS.md).

### Soak tooling

Long-running checks, run deliberately, not part of `composer test`:

```bash
php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-memory-flatness.php
php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-no-leftover-children.php
php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-arena-watermark.php
```

| Tool | Asserts | Exit codes |
| --- | --- | --- |
| `soak-memory-flatness.php` | RSS and `memory_get_usage(true)` stay flat over sustained spawn/park/resume cycles; a monotonic climb, or growth past `--tolerance`, fails | 0 flat, 1 climbing, 2 inconclusive |
| `soak-no-leftover-children.php` | no live child and no zombie survives a run | 0 clean, 1 leftovers, 2 inconclusive |
| `soak-arena-watermark.php` | the arena watermark **plateaus** under a steady-state parallel workload, process memory stays flat, and no child survives. The arena is a bump allocator with no free list, so the criterion is a plateau and not zero growth | 0 plateau, 1 climbing or a leftover child, 2 inconclusive |

Each tool can produce its own failure on demand — `--inject-leak` and `--self-test` — so the detector
itself is testable. `soak-arena-watermark.php --self-test` rewrites a shared string property every
round, which costs an arena block per write by design, and must come back FAIL.

## License

MIT — see [LICENSE](LICENSE).
