# native-php-coroutines

Go-style coroutines for PHP: **concurrency** within a process on native `Fiber`, and **true
parallelism** across forked workers that exchange real shared PHP objects — with **zero
serialization** on the data path.

> **Status: in implementation.** The runtime is being built layer by layer; see the
> [EPIC](https://github.com/lisachenko/native-php-coroutines/issues/1) for the current board.

## The model, in one sentence

Coroutines are **concurrent within a worker**; workers are **parallel across processes**.

Every process runs its own Fiber-based scheduler whose single blocking point is one
`stream_select()`. Values that cross a process boundary are not encoded — they live in a
fork-shared `mmap` arena and travel as addresses.

## The Never-Serialize Rule

> No value crossing a worker boundary may pass through `serialize()`, igbinary, JSON, or any
> byte-encoding of PHP value graphs. Cross-worker data is exchanged as real PHP values: scalars
> inline, strings as arena-copied `zend_string` addresses, arrays/objects as addresses of shared
> objects in a fork-shared mmap arena. Sockets carry only wake bytes and fixed-size event records
> (opcode + tag + address/slot id) — signaling, not serialization.

## Layers

| Layer | What it gives you | FFI |
| --- | --- | --- |
| **1 — cooperative runtime** | scheduler, channels, `select`, timers, IO parking, deadlock detection | **none** |
| **2 — preemption** | ~10 ms time slices, so a call-free loop cannot starve its peers | opt-in |
| **P — parallelism** | prefork workers, shared objects, shared channels, result slots | required |

**Layer 1 uses no FFI at all** and runs on a stock PHP build. FFI becomes a hard requirement only
when you ask for `workers > 0`.

## Requirements

- PHP **8.4** or **8.5**, NTS.
- For `workers > 0`: `ext-ffi` with `ffi.enable=1`, and `opcache.jit=off`.
- `lisachenko/z-engine`, resolved per PHP minor (`8.4.x-dev` on 8.4, `8.5.x-dev` on 8.5) — engine
  structures are read by byte offset, so the line must match the running minor.

## Limits worth knowing up front

- **Sharing is fork-only.** Shared objects are valid because children inherit an identical address
  layout; there is no attach-by-key across unrelated processes.
- **Plain arrays and closures are not shareable.** Use `SharedArray` and `Task` respectively;
  anything else throws `NotShareableValueException` naming the remedy.
- **The JIT must be off** wherever the engine hooks are used — it rewrites the executor internals
  those hooks depend on.

## License

MIT — see [LICENSE](LICENSE).
