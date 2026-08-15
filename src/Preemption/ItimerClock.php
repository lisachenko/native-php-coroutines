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

namespace Lisachenko\NativePhpCoroutines\Preemption;

/**
 * The preemption clock: libc's `setitimer(ITIMER_REAL)`, bound through FFI.
 *
 * PHP's own `pcntl_alarm()` has one-second granularity, which is three orders of magnitude too
 * coarse for a 10 ms time slice, so the interval timer is armed through a package-local `FFI::cdef`
 * of `setitimer(2)` instead. Nothing else about the clock is exotic: it raises `SIGALRM` on a
 * repeating interval, ext-pcntl's C signal handler raises `EG(vm_interrupt)` for it, and
 * {@see InterruptBridge} does the actual work at the next VM interrupt check.
 *
 * On its own this class only makes a signal arrive. `SIGALRM`'s default disposition terminates the
 * process, so whoever arms the clock owes it a handler — {@see Preemptor::arm()} installs one.
 *
 * # The struct is written as bytes, on purpose
 *
 * `struct itimerval` is two `struct timeval`, each two 64-bit fields: 32 bytes of little-endian
 * integers on x86-64 Linux. It is filled with `pack()` and `FFI::memcpy()` rather than by assigning
 * to CData fields, which keeps the layout in exactly one place — {@see self::PACK_FORMAT} together
 * with the header text — instead of splitting it between a C declaration and four PHP assignments
 * whose types nothing can check. {@see self::assertLayout()} then holds `FFI::sizeof` against those
 * 32 bytes at bind time, because a layout mismatch would not fail loudly: it would arm the timer at
 * an interval nobody asked for.
 *
 * # Forking clears the timer
 *
 * POSIX requires `fork()` to clear the child's interval timers, and it does: a forked worker that
 * does not call {@see self::rearmAfterFork()} is simply never preempted, silently. That is why the
 * re-arm is a named method and not something a caller has to remember to open-code.
 */
final class ItimerClock
{
    /** The real-time interval timer, counting wall-clock time and delivering SIGALRM. */
    private const int ITIMER_REAL = 0;

    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    /** `struct itimerval`: it_interval.tv_sec, it_interval.tv_usec, it_value.tv_sec, it_value.tv_usec. */
    private const string PACK_FORMAT = 'q4';

    private const int ITIMERVAL_SIZE = 32;

    private const int TIMEVAL_SIZE = 16;

    /** Exactly the x86-64 Linux declarations, and no more of libc than the clock needs. */
    private const string LIBC_DECLARATIONS = <<<'C'
        typedef long time_t;
        typedef long suseconds_t;
        struct timeval { time_t tv_sec; suseconds_t tv_usec; };
        struct itimerval { struct timeval it_interval; struct timeval it_value; };
        int setitimer(int which, const struct itimerval *new_value, struct itimerval *old_value);
        int getitimer(int which, struct itimerval *curr_value);
        C;

    /** Bound lazily, so a runtime that never asks for preemption performs no FFI at all. */
    private ?\FFI $libc = null;

    private bool $armed = false;

    private readonly int $intervalMicroseconds;

    /**
     * @param float $interval Slice length in seconds; the timer repeats at this interval.
     */
    public function __construct(float $interval)
    {
        $microseconds = (int) round($interval * self::MICROSECONDS_PER_SECOND);

        if ($microseconds < 1) {
            throw new \InvalidArgumentException(sprintf(
                'a preemption slice of %ss rounds to less than one microsecond, which setitimer(2) '
                . 'reads as "disarm" rather than as a very short slice',
                rtrim(rtrim(number_format($interval, 9, '.', ''), '0'), '.'),
            ));
        }

        $this->intervalMicroseconds = $microseconds;
    }

    /** The configured slice, in microseconds. */
    public function intervalMicroseconds(): int
    {
        return $this->intervalMicroseconds;
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    /** Start (or restart) the repeating interval timer. */
    public function arm(): void
    {
        $this->setInterval($this->intervalMicroseconds);
        $this->armed = true;
    }

    /** Stop the timer. No further SIGALRM is delivered until {@see self::arm()} is called again. */
    public function disarm(): void
    {
        $this->setInterval(0);
        $this->armed = false;
    }

    /**
     * Re-arm in a freshly forked child.
     *
     * The FFI binding survives `fork()` — the child inherits the whole address space — but the
     * kernel's interval timer does not, so this is an unconditional re-arm rather than a check.
     */
    public function rearmAfterFork(): void
    {
        $this->armed = false;
        $this->arm();
    }

    /**
     * The repeating interval the kernel currently has for this process, in microseconds.
     *
     * Read back through `getitimer(2)` rather than from {@see self::isArmed()}, which is the whole
     * point of it: it is the only way to tell a timer this process armed from one the kernel
     * cleared behind its back — a `fork()` child above all, where the two disagree.
     */
    public function kernelIntervalMicroseconds(): int
    {
        $timer = $this->allocateTimer();

        $this->callLibc('getitimer', self::ITIMER_REAL, \FFI::addr($timer));

        $fields = unpack(self::PACK_FORMAT, \FFI::string(\FFI::addr($timer), self::ITIMERVAL_SIZE));

        // Fields 1 and 2 are it_interval.tv_sec and it_interval.tv_usec; 3 and 4 are it_value, the
        // time left until the next expiry, which is a moving target and deliberately not reported.
        $seconds      = $fields === false ? null : ($fields[1] ?? null);
        $microseconds = $fields === false ? null : ($fields[2] ?? null);

        if (!is_int($seconds) || !is_int($microseconds)) {
            throw new \RuntimeException('the struct itimerval getitimer(2) wrote did not unpack as integers');
        }

        return $seconds * self::MICROSECONDS_PER_SECOND + $microseconds;
    }

    /**
     * Arm ITIMER_REAL at $microseconds, repeating; zero disarms it.
     *
     * Both `it_value` (the first expiry) and `it_interval` (every one after) get the same value,
     * which is what makes it a free-running tick grid rather than a one-shot.
     */
    private function setInterval(int $microseconds): void
    {
        $seconds = intdiv($microseconds, self::MICROSECONDS_PER_SECOND);
        $microseconds %= self::MICROSECONDS_PER_SECOND;

        $timer = $this->allocateTimer();

        \FFI::memcpy(
            $timer,
            pack(self::PACK_FORMAT, $seconds, $microseconds, $seconds, $microseconds),
            self::ITIMERVAL_SIZE,
        );

        $this->callLibc('setitimer', self::ITIMER_REAL, \FFI::addr($timer), null);
    }

    private function allocateTimer(): \FFI\CData
    {
        return $this->libc()->new('struct itimerval');
    }

    /**
     * Call one of the two C functions declared above and insist that it succeeded.
     *
     * The functions a `FFI::cdef()` handle exposes come from the header text at runtime, so they
     * are reached as a callable rather than as declared methods — and whether the binding really
     * has them is checked here rather than assumed. Both return 0 on success and -1 with `errno`
     * set on failure, and every failure mode of these two calls (`EINVAL` on a malformed
     * `itimerval`, `EFAULT` on a bad pointer) is a bug in this class rather than a condition a
     * caller could handle.
     */
    private function callLibc(string $function, mixed ...$arguments): void
    {
        $call = [$this->binding(), $function];

        if (!is_callable($call)) {
            throw new \RuntimeException(sprintf(
                'the libc binding does not expose %s(); the interval timer cannot be armed',
                $function,
            ));
        }

        $result = $call(...$arguments);

        if ($result !== 0) {
            throw new \RuntimeException(sprintf(
                '%s(ITIMER_REAL) failed, returning %s',
                $function,
                var_export($result, true),
            ));
        }
    }

    /**
     * The libc handle, with its static type erased.
     *
     * `FFI::cdef()` returns an `FFI` object whose callable surface is whatever the header declared;
     * none of it exists as a PHP method, and no PHP type describes it. Erasing the type here is
     * what lets {@see self::callLibc()} do the honest thing — check for the function and fail
     * loudly — instead of pretending the surface is statically known.
     */
    private function binding(): mixed
    {
        return $this->libc();
    }

    private function libc(): \FFI
    {
        return $this->libc ??= self::bindLibc();
    }

    /**
     * Bind the declarations above against libc.
     *
     * The soname candidates are tried in order: `null` binds against the symbols already in the
     * process (PHP links libc, so this normally wins and loads nothing), and the explicit sonames
     * cover builds where the process symbols are not visible to `dlsym`.
     */
    private static function bindLibc(): \FFI
    {
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException(
                'preemption needs ext-ffi to arm the interval timer; run PHP with ffi.enable=1',
            );
        }

        $failure = null;

        foreach ([null, 'libc.so.6', 'libc.so'] as $soname) {
            try {
                $libc = \FFI::cdef(self::LIBC_DECLARATIONS, $soname);
            } catch (\Throwable $error) {
                $failure ??= $error;

                continue;
            }

            self::assertLayout($libc);

            return $libc;
        }

        throw new \RuntimeException(
            'could not bind setitimer(2) through FFI: ' . ($failure?->getMessage() ?? 'unknown reason'),
            0,
            $failure,
        );
    }

    /**
     * Refuse a platform whose struct layout is not the one the pack format assumes.
     *
     * A mismatch here is not a portability inconvenience to route around: writing 32 bytes of
     * 64-bit fields over a differently shaped `struct itimerval` arms the timer at an interval
     * nobody asked for, and there is no failure mode that would say so.
     */
    private static function assertLayout(\FFI $libc): void
    {
        $timevalSize   = \FFI::sizeof($libc->new('struct timeval'));
        $itimervalSize = \FFI::sizeof($libc->new('struct itimerval'));

        if ($timevalSize !== self::TIMEVAL_SIZE || $itimervalSize !== self::ITIMERVAL_SIZE) {
            throw new \RuntimeException(sprintf(
                'unexpected interval-timer layout: sizeof(struct timeval)=%d (expected %d), '
                . 'sizeof(struct itimerval)=%d (expected %d); this platform is not on the x86-64 '
                . 'Linux layout the preemption clock is written for',
                $timevalSize,
                self::TIMEVAL_SIZE,
                $itimervalSize,
                self::ITIMERVAL_SIZE,
            ));
        }
    }
}
