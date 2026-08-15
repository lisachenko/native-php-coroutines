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

/**
 * Arena watermark soak — **a placeholder, deliberately not a test yet.**
 *
 *     php8.4 -d ffi.enable=1 -d opcache.jit=off tools/soak-arena-watermark.php   # exits 3
 *
 * The soak this file is reserved for measures the fork-shared mmap arena: allocate and release
 * shared strings, objects and `SharedArray`s across workers for a sustained period, and assert that
 * the arena's high-water mark settles instead of climbing. It is the parallel-layer counterpart of
 * `soak-memory-flatness.php` — and unlike the PHP allocator, an arena has no GC to bail it out, so
 * a watermark that only ever rises is the whole failure mode.
 *
 * **It cannot be written yet.** The arena, the shared roots and `persist()` all land with #7, and
 * `Runtime` currently refuses every one of them. A soak written against an interface that does not
 * exist would either be a mock — measuring nothing — or a script that reports PASS because it never
 * allocated anything. Either would be worse than this file: a green tool nobody wrote is exactly
 * how a leak reaches production with a test suite vouching for it.
 *
 * What it will need when #7 lands, so the shape is not re-derived from scratch:
 *
 * - the arena's own accounting (bytes in use, high-water mark, free-list length) read **without**
 *   `var_dump()`-shaped inspection of shared objects — those make engine C code write a per-process
 *   `properties` pointer into a shared struct and segfault every sibling;
 * - a workload that both allocates and *releases*, since a watermark that never falls proves
 *   nothing about a runtime that never freed;
 * - measurement in the parent **and** in each worker, because the arena is shared but the mapping is
 *   per-process;
 * - the same trend-not-threshold verdict `soak-memory-flatness.php` already uses, so the two tools
 *   report failures the same way.
 *
 * Exit code 3 means "reserved, not implemented" — distinct from 0 (pass), 1 (fail) and 2 (could not
 * run), so a script that runs every soak cannot mistake this for a green result.
 */

echo 'SOAK arena-watermark: NOT IMPLEMENTED — the shared arena lands with #7; ',
'this placeholder exists so nothing reports a green watermark before there is an arena to measure.',
PHP_EOL;

exit(3);
