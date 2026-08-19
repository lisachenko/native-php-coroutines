# Preemption spikes

These are the experiments that **select the Layer 2 preemption mechanism** (ticket #5). They are
not part of the library and are not run by `composer test`; they exist so the decision has evidence
behind it and can be re-checked when PHP or z-engine moves.

Read [`VERDICTS.md`](VERDICTS.md) for the results and the selected mechanism. Raw output of every
run is in [`raw/`](raw/).

## The headline

| Spike | Question | Verdict |
| --- | --- | --- |
| S1 | `Fiber::suspend()` from a pcntl async signal handler | **RED** — engine-level block |
| S2 | forced yield from a z-engine `InterruptHook` FFI callback | **GREEN** — the selected mechanism |
| S3 | FFI `setitimer` at ~10 ms | **GREEN** — 100.0 tick/s |
| S4 | interrupt density in call-free loops | **GREEN**, with a hard caveat on single opcodes |
| S5 | `Fiber::throw()` into a preempt-suspended fiber | **GREEN** — hazard established |
| S6 | suspended-fiber GC | **GREEN** — with a shutdown obligation |
| S7 | endings available with an undrainable fiber alive | **GREEN** (8.4) — only a signal avoids the S6 fatal |

S1 being red is the load-bearing result: it rules out an FFI-free preemption path, so **preemption
requires z-engine**, while Layer 1 remains FFI-free.

## Running them

Every invocation needs both flags. FFI cannot be enabled at runtime, and the JIT rewrites the very
executor internals the hook depends on:

```bash
php8.4 -d ffi.enable=1 -d opcache.jit=off s1_fiber_suspend_from_signal.php
```

Always run under a hard `timeout` — a spike that hangs is itself a result:

```bash
timeout 30 php8.4 -d ffi.enable=1 -d opcache.jit=off s4_interrupt_density.php
```

Run each spike on **both** minors; a result that holds on one proves nothing about the other.

### z-engine, for S2 and S7

S2 and S7 need z-engine, and z-engine reads engine structures by byte offset, so the line must match
the running minor. That means **two separate vendor trees** — one resolved by each PHP — which the
scripts expect at `ze84/vendor` and `ze85/vendor` (S7 falls back to the package's own `vendor/`,
which is only correct for whichever minor that tree was resolved by):

```bash
for v in 8.4:ze84 8.5:ze85; do
  php="php${v%%:*}"; dir="${v##*:}"
  mkdir -p "$dir" && (cd "$dir" \
    && "$php" $(command -v composer) init -n --name=spikes/preemption \
    && "$php" $(command -v composer) require lisachenko/z-engine:"~8.4.2 || ~8.5.0" -n)
done
```

The vendor trees are deliberately not committed. A single shared `vendor/` would be wrong for one
of the two minors, which is exactly the failure mode `ZEngine\Core::init()` exists to catch.

## Reading a result

Each script prints a machine-greppable verdict line:

```
VERDICT S1: RED — Fiber::suspend() from handler raised FiberError: ...
```

Verdicts are `GREEN`, `RED`, `HANG`, `CRASH`, `BLOCKED` or `INCONCLUSIVE`. Several scripts also take
flags that deliberately trigger the failure they document (`--throw-probe`, `--unsafe-hook`,
`--preempt-destroy`, `--preempt-shutdown`, S7's `--leave-installed`, `--leave-uninstalled` and
`--exit`); those exit with a **PHP fatal error, by design** — see `VERDICTS.md` for the list and
their survivable counterparts. S7 runs all of its own modes as subprocesses, so running the script
plainly is safe.

A **segfault or bus error is never a flaky run.** Capture the command, the PHP version and a minimal
reproducer, and report it.
