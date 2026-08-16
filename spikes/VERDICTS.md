# Preemptive scheduling of PHP Fibers — spike results

Agent: **C2-spikes** · project: `lisachenko/native-php-coroutines` (Layer 2, Go-style time-slice preemption)

All spikes were run on **PHP 8.4.19** and **PHP 8.5.9**, every invocation with
`-d ffi.enable=1 -d opcache.jit=off` and a hard `timeout`. Raw output for every run is in
[`raw/`](raw/). No spike crashed the host process except where a crash *was* the experiment
(S2/2d, S5/5b, S6/6b, 6c, 6e — all isolated in subprocesses).

z-engine resolved per minor, exactly as the dependency policy requires:

| PHP | z-engine | commit | `Core::init()` |
|-----|----------|--------|----------------|
| 8.4.19 | `8.4.x-dev` | `8ede54b595` | OK |
| 8.5.9  | `dev-master` (`8.5.x-dev`) | `29d2f3e0e0` | OK |

---

## Verdict table

| Spike | PHP 8.4 | PHP 8.5 | Verdict |
|-------|---------|---------|---------|
| **S1** `Fiber::suspend()` from a pcntl async signal handler | 0 preemptions / 427 × `FiberError` | 0 preemptions / 371 × `FiberError` | **RED** — engine-level block, no workaround |
| **S2** forced yield from a z-engine `InterruptHook` FFI callback | 419 preemptions, mean slice 10.20 ms, state intact | 376 preemptions, mean slice 10.22 ms, state intact | **GREEN** |
| **S3** FFI `setitimer` at 10 ms | 100.0 tick/s, mean 10.000 ms, sd 0.030 ms | 100.0 tick/s, mean 10.000 ms, sd 0.006 ms | **GREEN** |
| **S4** interrupt density in call-free loops | no shape unbounded; worst non-allocating 92 µs | no shape unbounded; worst non-allocating 195 µs | **GREEN** (hard caveat: allocation + single opcodes) |
| **S5** never `Fiber::throw()` into a preempt-suspended fiber | cancellation silently lost / fatal | cancellation silently lost / fatal | **GREEN** — hazard established |
| **S6** suspended-fiber GC | 0 B/fiber leak; destroying a preempted fiber is **fatal** | 0 B/fiber leak; destroying a preempted fiber is **fatal** | **GREEN** — with a hard shutdown obligation |
| **S7** endings available with an undrainable fiber alive | every shutdown path fatals; a self-directed signal does not | *not measured — see below* | **GREEN** on 8.4 |

Nothing was BLOCKED: both z-engine lines installed successfully, so S2 was fully exercised.

S1–S6 were run on both minors. **S7 was added later, from a session with a single vendor tree
resolved by 8.4**, and its 8.5 column is therefore empty rather than assumed: it re-measures S6's
fatal (identical on both minors there) and adds only which *endings* avoid it, which is a property of
`fork`/`signal` semantics rather than of an engine offset. Re-run it on 8.5 with the `ze85` tree from
[`README.md`](README.md) before treating the 8.5 column as known.

---

## S1 — `Fiber::suspend()` from a pcntl async signal handler

> Can a `pcntl_async_signals(true)` handler suspend the running fiber, giving preemption with
> no FFI at all?

**No. This is blocked by the engine, in both directions, on both minors.**

`php8.4`, 200 000 000-iteration call-free arithmetic loop inside a fiber, SIGALRM every 10 ms
from a forked ticker process:

```
phase1 fiber-less alarms: handlerEntries=49 outsideFiber=49 (survived=yes)
phase2 work: 4.331 s (reference 4.325 s, overhead +0.1%)
phase2 preemptions=0 badResumeValues=0
phase2 handler: entries=476 suspendCalls=427 outsideFiber(during work)=0
phase2 suspendErrors=427 first=FiberError: Cannot switch fibers in current execution context
phase2 result=599999994 reference=599999994 stateIntact=YES
```

`php8.5` is identical in kind: 417 handler entries, 371 suspend attempts, 371 × the same
`FiberError`, 0 preemptions.

The diagnostics localize the block precisely — this is the part that matters for the design:

| probe | 8.4 | 8.5 |
|-------|-----|-----|
| 3a — `Fiber::suspend()` from a closure invoked by `array_map` (nested **internal** frame) | **OK**, suspended, value delivered | **OK** |
| 3b — `Fiber::suspend()` from the same handler dispatched **synchronously** via `pcntl_signal_dispatch()` inside the fiber | **blocked**, same `FiberError` | **blocked** |
| 3c — handler sets a **flag only**; fiber polls it at an injected checkpoint and suspends itself | 242 preemptions, mean 10.089 ms, p99 12.653 ms, max 13.328 ms, result CORRECT | 197 preemptions, mean 10.085 ms, p99 13.234 ms, max 13.312 ms, result CORRECT |

**Conclusion.** The restriction is not "you cannot suspend across an internal call frame" — 3a
proves you can. It is specific to **pcntl's signal-handler dispatch**, which brackets the
handler invocation with the engine's fiber-switch block. Async or synchronous makes no
difference. So *no* PHP signal handler, on any dispatch path, can ever suspend a fiber. Path 1
is dead by construction, not by tuning.

Two useful side results: a SIGALRM that arrives while control is in the scheduler is perfectly
survivable when the handler checks `Fiber::getCurrent() === null` and returns (49 such no-ops,
no ill effects); and the flag-plus-cooperative-checkpoint fallback (3c) genuinely delivers a
~10 ms slice with correct arithmetic.

## S2 — forced yield from a z-engine `InterruptHook` FFI callback

> Does the engine's own interrupt callback sit outside the block S1 hit?

**Yes. This is the mechanism.**

```
2a Core::init() OK — z-engine 8.4.x-dev @ 8ede54b595
2b manual requestInterrupt(): hook fired 1 time(s) => OK
2c reference 4.339 s | preempted work 4.281 s (-1.3%)
2c hookFired=839 suspendTried=419 preemptions=419 badValues=0
2c slice ms: mean=10.204 p99=10.212 max=35.581
2c suspendErrors=0 first=(none) | proceedErrors=0
2c result=599999994 reference=599999994 stateIntact=YES
```

PHP 8.5: 376 preemptions, mean 10.217 ms, p99 11.231 ms, max 24.215 ms, `stateIntact=YES`.
An earlier, less-loaded run of the same file recorded max 10.59 ms (8.4) and 11.16 ms (8.5);
the 24–36 ms maxima above are host-load outliers, which is why the p99 (10.2–11.2 ms) is the
number to quote. **Preemption overhead is not measurable** against the reference run
(−1.3 % on 8.4; PHP 8.5 ran the preempted loop *faster* than the reference, which is run-order
noise, not a speedup).

Structure of the working design:

- the pcntl C signal handler already raises `EG(vm_interrupt)` for a registered signal;
- the FFI callback fires at the next VM interrupt check and calls `Fiber::suspend('PREEMPT')`
  from there — legal, unlike S1;
- `hookFired ≈ 2 × preemptions` because the first interrupt after each tick is consumed by
  pcntl's dispatch (which sets the request flag) and the second one does the suspend.

**2e — deadline-driven variant (design refinement).** The hook can instead compare
`hrtime()` against its own slice deadline, with an *empty* PHP signal handler that exists only
so pcntl registers a C handler. This removes any dependence on pcntl's PHP-level dispatch:
8.4 → 127 preemptions, mean 10.246 ms, result CORRECT; 8.5 → 105 preemptions, mean 10.153 ms,
result CORRECT. It did **not** halve the interrupt count (still ≈2.0 firings per preemption)
because the free-running 10 ms tick grid and the per-resume deadline grid are independent; to
get one interrupt per preemption the timer would have to be re-armed one-shot at each resume.

**2d — throwing out of the callback.** Confirmed uncatchable, on both minors:

```
PHP Fatal error:  Throwing from FFI callbacks is not allowed
exit=255
```

## S3 — FFI `setitimer(ITIMER_REAL)` at 10 ms

> Is libc's interval timer a usable 10 ms preemption clock, and is it cleared across `fork()`?

**GREEN on both, with excellent numbers.**

```
3b sizeof(struct timeval)=16 (expect 16), sizeof(struct itimerval)=32 (expect 32) => OK
3c setitimer(ITIMER_REAL, 10ms repeating) rc=0 | getitimer readback: it_interval=10000 us => MATCHES
3c over 1.000 s: ticks=100 (expected ~100) rate=100.0/s
3c interval ms: mean=10.000 sd=0.030 min=9.916 p50=9.998 p99=10.057 max=10.085   [8.4]
3c interval ms: mean=10.000 sd=0.006 min=9.984 p50=10.000 p99=10.016 max=10.022   [8.5]
3d second 0.5 s window WITHOUT re-arming: ticks=49/50 => KEEPS FIRING (repeating)
3e forked child over 0.5 s: ticks=0 | getitimer in child: it_interval=0 us it_value=0 us
   => TIMER CLEARED IN CHILD (as POSIX requires) — each worker MUST re-arm
```

The x86-64 Linux struct layout (`time_t`/`suseconds_t` both 64-bit; `timeval` = 16 B,
`itimerval` = 32 B) is confirmed both by `FFI::sizeof` and by a `getitimer()` read-back of what
was armed. **The fork behaviour required by the multi-worker design is verified explicitly:**
the child observed **zero** ticks and `it_value == 0`, so every forked worker must arm its own
timer after `pcntl_fork()`.

## S4 — interrupt density in call-free loops

> Is there any loop shape where a 10 ms slice becomes unbounded?

**No loop shape is unbounded** — but the slice is only tight for non-allocating code.

Excess latency = handler timestamp − arm timestamp − 10 ms, measured with a **one-shot** timer
re-armed inside the handler (no signal coalescing, no grid aliasing).

| shape | 8.4 mean / max (µs) | 8.5 mean / max (µs) |
|-------|--------------------:|--------------------:|
| (a) integer arithmetic | 49.5 / **92.3** | 46.0 / **194.9** |
| (b) string concatenation | 948.7 / **45 334** | 2 943.3 / **95 258** |
| (c) array append | 38 068 / **420 643** | 51 254 / **448 507** |
| (d) loop with a function call | 44.9 / **70.2** | 44.3 / **88.7** |
| (e) empty `while (true) {}` | 43.6 / **84.8** | 43.2 / **101.9** |

The empty `while (true) {}` is interrupted exactly as promptly as any other loop (150 samples,
max 85 µs), which confirms `ZEND_JMP` carries an interrupt check — there is no loop you can
write that escapes the timer.

The (b)/(c) outliers are **not** teardown: freeing the 20 MB string produced no sample at all
(< 10 ms) and freeing the 6M-element array cost only 2.4 ms (8.4) / 2.8 ms (8.5), while the
worst sample fell mid-run (`worst at sample #12 of 17`). They are single reallocation +
page-fault events inside the growth loop. Note the medians stay tiny (p50 ≈ 70 µs) — this is a
rare-outlier distribution, not a uniformly bad one.

Single long-running opcodes are the hard floor and are simply not interruptible:

| probe | 8.4 | 8.5 |
|-------|----:|----:|
| `sort()` over 4M ints | **2 002.6 ms** late | **1 622.9 ms** late |
| `str_repeat('a', 400M)` | **1 200.3 ms** late | **1 177.7 ms** late |

**The achievable slice is bounded below by the longest single opcode, not by the timer.**

## S5 — never `Fiber::throw()` into a preempt-suspended fiber

> What actually happens, and why is this a rule?

**GREEN — hazard established, identically on both minors.**

| case | outcome |
|------|---------|
| **5a** preempt-suspended, hook body wrapped in the mandatory `try/catch` | `throw()` returns normally; the hook logs `DomainException: cancel-me`; the fiber returns `'COMPLETED-NORMALLY'`; `fiberSawException=NULL`; `finallyRan=true` only via normal exit. **Cancellation silently lost.** |
| **5b** preempt-suspended, hook body unguarded | `PHP Fatal error: Throwing from FFI callbacks is not allowed`, exit **255**, uncatchable |
| **5c** suspended at an explicit `Fiber::suspend()` in user code | exception surfaces **at that exact call** (`DomainException: cancel-me @ line 277`), the user `catch` runs, `finally` runs, fiber returns `'CANCELLED-IN-FIBER'` |

The mechanism: a preempt-suspended fiber's resume point is *inside the scheduler's own FFI
interrupt callback*, not in user code. `Fiber::throw()` therefore injects the exception there.
Either the mandatory `try/catch` eats it (cancellation vanishes and the coroutine keeps running
to completion) or, without that guard, it escapes an FFI callback and kills the process. There
is no third outcome — the two failure modes are the only options.

## S6 — suspended-fiber GC

> Are abandoned suspended fibers collected, and must the scheduler drain at shutdown?

**Memory is a non-issue. Lifetime is a correctness issue.**

**6a — cooperatively suspended, abandoned, 10 000 cycles (both minors):**

```
6a cycles=10000 bodiesEntered=10000 destructorsRun=10000 finallyRan=10000
6a memory_get_usage():     487616 -> 490088  (+2472 B total, +0.25 B/fiber)
6a memory_get_usage(true): 2097152 -> 2097152  (+0 B total, +0.00 B/fiber)
6a RSS:                    37564416 -> 37814272 (+249856 B total, +24.99 B/fiber)
```

The RSS delta is a **one-time step**, not growth: the sampled progression is byte-identical at
2 000 / 4 000 / 6 000 / 8 000 / 10 000 cycles. Every fiber-local destructor ran, and — worth
knowing — **`finally` DOES run** when a cooperatively suspended fiber is abandoned
(10 000 / 10 000). Leak per fiber is indistinguishable from zero.

**But a PREEMPT-suspended fiber must never be destroyed while suspended:**

| probe | 8.4 | 8.5 |
|-------|-----|-----|
| 6b drop the last reference to a preempt-suspended fiber | **PHP FATAL ERROR** (255) | **PHP FATAL ERROR** |
| 6c leave one alive at request shutdown | **PHP FATAL ERROR** | **PHP FATAL ERROR** |
| 6d drain — `resume(null)` until `isTerminated()`, then drop | survived; 400 cycles, ~1 720 preemptions, 400/400 destructors and finallys, `mem(true)` +0.0 B/fiber | survived |
| 6e workaround A — uninstall the hook *first*, then drop | **PHP FATAL ERROR** | **PHP FATAL ERROR** |
| 6f workaround B — drain from `register_shutdown_function()` | survived, drained in 1 resume | survived |

The fatal is always the same line:

```
PHP Fatal error:  Throwing from FFI callbacks is not allowed in .../System/Hook/InterruptHook.php on line 84
```

The engine unwinds a dying fiber *from its suspension point*. For a preempted fiber that point
is inside the FFI callback, and the unwind is a non-`Throwable` engine sentinel (z-engine's own
`Executor::getCurrentException()` documents these "unwind-exit and graceful-exit markers"), so
the mandatory `catch (\Throwable)` cannot stop it. Uninstalling the hook does not help — the
suspended fiber's *saved stack* still contains the ext-ffi trampoline frame.

## S7 — endings available to a process holding an undrainable fiber

> S6 says the drain is the only way out and issue #18 says the drain can never finish for
> `while (true) { $x++; }`. Bounding it means deciding to stop while a fiber is still suspended in
> the callback. What endings does the process have from there, and does any of them reach the end
> without the engine destroying that fiber?

**GREEN on 8.4 — exactly one family of endings avoids the fatal, and it is a signal.**

Each row is a subprocess that preempt-suspends `while (true) { $x++; }` at a 2 ms slice, stops the
timer, and then ends the way the row names (`raw/s7_php84.txt`):

| ending | exit | S6 fatal? | output kept? |
|--------|-----:|-----------|--------------|
| let the script end with the fiber alive | 255 | **yes** | yes, then the fatal |
| uninstall the interrupt hook first, then end | 255 | **yes** | yes, then the fatal |
| `exit(70)` | **255**, not 70 | **yes** | yes, then the fatal |
| `posix_kill(self, SIGTERM)` | 143 (signal 15) | no | yes, both streams |
| `posix_kill(self, SIGKILL)` | 137 (signal 9) | no | yes, both streams |
| kill from a shutdown function registered *during* shutdown | 137 (signal 9) | no | yes, and every earlier shutdown function ran first |
| control: drain the fiber, then end normally | 0 | no | drained in **6 resumes** (2 M iterations at a 2 ms slice) |

Three things this settles for the bounded drain:

1. **`exit()` is not an escape.** It runs request shutdown, which is where the fiber is destroyed —
   the process ends on the engine's fatal at 255 rather than on the code it was given.
2. **A signal to self is.** The process ends where it stands, nothing is destructed, and everything
   already written to stdout *and* stderr is kept — so the diagnosis survives the ending that
   delivers it. `SIGKILL` over `SIGTERM` because a handleable signal can be handled by the
   application, and this one may not be declined.
3. **The kill can be deferred to the very last shutdown function.** Registering from inside a
   shutdown function appends to the queue, so the runtime's ending does not swallow the
   application's own shutdown work.

The control row is also where the drain budget's size comes from: a coroutine that *does* finish
needs a handful of resumes, not dozens.

---

# Recommended preemption mechanism

## Path 2 — the z-engine `InterruptHook`, clocked by an FFI `setitimer`

**Path 1 (pcntl handler) is not available at all**, and this is not a tuning problem: S1
showed `FiberError: Cannot switch fibers in current execution context` on 427/427 (8.4) and
371/371 (8.5) attempts, and probe 3b showed the block survives even when the handler is
dispatched *synchronously* from inside the fiber. **Path 2 is the only mechanism that
achieves real preemption**: S2 preempted a 200 M-iteration call-free loop 419 times (8.4) and
376 times (8.5) at a mean slice of 10.20 / 10.22 ms and p99 10.2 / 11.2 ms, with the preempted
arithmetic result bit-identical to the non-preempted reference and no measurable throughput
cost; S3 supplies the clock at 100.0 tick/s with sd 0.006–0.030 ms; S4 proves the interrupt is
observed within ~100–200 µs in every non-allocating loop shape, including an empty
`while (true) {}`, so no user code can starve the scheduler. **Path 3 (a flag consumed at
injected cooperative checkpoints) works too** — S1/3c hit 242 preemptions at a mean 10.089 ms
slice — but it can only preempt code the AST rewriter actually instrumented, which excludes
opcache-warm files and every third-party library, and that is precisely the code most likely to
run away. Path 3 therefore stays in the design as the **cancellation** mechanism (mandated by
S5) and as a fallback where FFI is unavailable, not as the time-slice mechanism. The price of
path 2 is the lifetime obligation S6 uncovered: a preempt-suspended fiber has its resume point
inside an FFI callback, so it may never be thrown into and never be destroyed while suspended.
That is a scheduler-ownership discipline, not a blocker — S6/6d and 6f show the disciplined
path survives cleanly.

**Confidence.** High for S1, S3, S5, S6 (deterministic, identical on both minors, mechanism
understood). High for S2's *feasibility*, moderate for its long-run stability: the longest
continuous run in these spikes was ~4.3 s and ~430 preemptions per process. A soak test
(hours, millions of preemptions, many concurrent fibers, real I/O) is the obvious next step and
is **not** covered here.

## Rules for AGENTS.md

- **Never call `Fiber::suspend()` from a pcntl signal handler.** It always fails with
  `FiberError: Cannot switch fibers in current execution context` — asynchronously *and* when
  dispatched synchronously via `pcntl_signal_dispatch()`. A PHP signal handler may only set a
  flag and raise `EG(vm_interrupt)` (`Executor::requestInterrupt()`). Suspending from a
  userland callback nested in an internal frame (e.g. an `array_map` callback) *is* legal — the
  block is specific to signal dispatch, so do not generalize the rule the wrong way.
- **The preemptive suspend must happen inside the z-engine `InterruptHook` callback** — that is
  the only context from which suspending a running fiber is legal.
- **Wrap the entire interrupt-callback body in `try { … } catch (\Throwable) { }`.** A throw
  escaping an FFI callback is `PHP Fatal error: Throwing from FFI callbacks is not allowed`,
  exit 255, uncatchable. Verified on 8.4 and 8.5.
- **Always chain `proceed()` when `hasOriginalHandler()` is true.** An interrupt hook that does
  not chain silently swallows pcntl's signal dispatch, so PHP signal handlers stop running
  entirely (observed: hook fired 65 times, the PHP `SIGALRM` handler never once).
- **Preload every class the interrupt callback touches before installing the hook.** No
  autoloading from an engine callback.
- **The interrupt callback must do nothing when `Fiber::getCurrent() === null`.** A tick that
  lands in the scheduler has nothing to preempt; leave the request pending and consume it on
  the next tick that lands inside a fiber.
- **Never `Fiber::throw()` into a preempt-suspended fiber.** Its resume point is inside the
  scheduler's FFI callback, not user code: with the mandatory `try/catch` the exception is
  silently swallowed and the coroutine runs to completion (cancellation lost, user `catch`
  never runs); without it, the process dies. **The scheduler may only ever `resume(null)` a
  preempt-suspended fiber.** Cancellation must be a flag the coroutine consumes at a
  cooperative safe point — which is also the only place `Fiber::throw()` is safe, because there
  the exception surfaces exactly at the explicit `Fiber::suspend()` call and runs the user's
  `catch` and `finally`.
- **The scheduler must own a strong reference to every preempted fiber and drain it.** Dropping
  the last reference to a preempt-suspended fiber, or leaving one alive at request shutdown, is
  a fatal error on both minors. Draining means `resume(null)` until `isTerminated()`; only then
  may the reference be released. Uninstalling the hook first does **not** help.
- **Register a shutdown drain.** `register_shutdown_function()` runs early enough to drain
  preempted fibers safely (verified). Every preempted coroutine must be drained there before
  the engine destroys it.
- **Bound the drain, and end the process yourself when it runs out.** A coroutine with no
  cooperative point is never drained, so an unbounded drain is a hang. Stopping is safe only
  because stopping is not releasing: the scheduler keeps holding the fiber, and the runtime ends
  the process with `posix_kill(self, SIGKILL)` from a shutdown function registered during
  shutdown. `exit()` is not an alternative — S7 measured it exiting **255 on the engine's fatal**,
  not on the status it was given.
- **A drain may only resume while the slice timer is live.** The resume returns because the next
  tick takes the CPU back, not because the coroutine hands it over; draining with the timer
  disarmed is the same unbounded wait in a different place.
- **Cooperatively suspended fibers need no drain for memory.** 10 000 create/suspend/abandon
  cycles leak 0.00 B/fiber (`memory_get_usage(true)`), every destructor runs and every `finally`
  runs. The drain obligation is about the preempt path only.
- **Each forked worker must arm its own interval timer.** `setitimer` intervals are cleared in
  the child (verified: 0 ticks, `it_value == 0`). A worker that does not re-arm after
  `pcntl_fork()` is never preempted.
- **Do not promise a hard slice bound.** No loop shape is unbounded, but a single internal
  opcode is not interruptible: `sort()` over 4M ints delays preemption by 1.6–2.0 s and a
  400 MB `str_repeat()` by ~1.2 s, and reallocation inside ordinary-looking `$a[] = $i` /
  `$s .= 'x'` loops produces 45–450 ms outliers. The 10 ms slice is a target, not a guarantee;
  size any watchdog and any latency SLO accordingly.
- **z-engine's line must match the running PHP minor** (`8.4.x-dev` on 8.4, `8.5.x-dev`/
  `master` on 8.5), and `Core::init()` must be allowed to enforce it. Preemption writes
  `EG(vm_interrupt)` by byte offset; a mismatched line writes the wrong memory.

## Open questions this spike did not settle

- **Long-run stability.** Longest run here: ~4.3 s / ~430 preemptions per process, single
  fiber. A multi-hour soak with many concurrent fibers and real I/O would settle it.
- **Preemption while a fiber is blocked in a syscall** (stream select, file read) was not
  tested; S4's single-opcode result suggests the interrupt is simply deferred until the
  internal call returns.
- **Interaction with opcache JIT** is untested by construction — every run used
  `opcache.jit=off`, as the project requires.
- **The one-shot-rearm variant of 2e** (re-arming the timer at each resume to get exactly one
  interrupt per preemption) was reasoned about but not measured.
