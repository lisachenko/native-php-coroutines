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

use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;

/**
 * One end of the parent/worker control socket, framed into fixed-size records.
 *
 * This class is the **only** thing in the runtime that writes to a control socket, which is what
 * makes the Never-Serialize Rule enforceable by inspection: the single write path takes a
 * {@see ControlRecord} and emits exactly {@see ControlRecord::SIZE} bytes. There is no method that
 * accepts a string, and adding one would be the violation.
 *
 * # Partial frames are the normal case, not the edge case
 *
 * A stream socket is a byte stream. Thirty-two bytes written in one `fwrite()` may arrive as 20 and
 * then 12, and two records written back to back may arrive as one 64-byte read. {@see self::drain()}
 * therefore appends whatever the kernel gives it to a private buffer and hands back only *whole*
 * records, keeping the remainder for the next readable event. A reader that assumed one read equals
 * one record would decode garbage under load — and, because every record is the same size, it would
 * do so silently.
 *
 * # Non-blocking, because the poller owns the blocking
 *
 * Both ends are non-blocking: the socket is registered with the process's single `stream_select()`
 * through {@see \Lisachenko\NativePhpCoroutines\PollerInterface::watchReadable()}, so a worker event
 * is just another readable descriptor. The one place this class may wait is {@see self::send()},
 * which blocks — bounded — on writability when the peer's receive buffer is momentarily full.
 */
final class ControlSocket
{
    /** How long a single record write may spend waiting for the peer to make room. */
    private const float WRITE_TIMEOUT_SECONDS = 5.0;

    /** Read granularity; a multiple of the record size purely for tidiness. */
    private const int READ_CHUNK = 8192;

    /** @var resource|null */
    private $stream;

    /** Bytes received that do not yet add up to a whole record. */
    private string $buffer = '';

    private bool $eof = false;

    /**
     * @param resource $stream A connected stream socket, typically one end of a socket pair.
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('a control socket needs an open stream resource');
        }

        stream_set_blocking($stream, false);
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);

        $this->stream = $stream;
    }

    /**
     * A connected pair: element 0 stays with the parent, element 1 goes to the child.
     *
     * @return array{0: self, 1: self}
     */
    public static function pair(): array
    {
        $pair = @stream_socket_pair(
            // AF_UNIX where it exists; Windows has no fork, so the fallback is only for completeness.
            defined('STREAM_PF_UNIX') ? STREAM_PF_UNIX : STREAM_PF_INET,
            STREAM_SOCK_STREAM,
            0,
        );

        if ($pair === false) {
            throw new \RuntimeException('unable to create a control socket pair');
        }

        return [new self($pair[0]), new self($pair[1])];
    }

    /**
     * The descriptor to register with the poller.
     *
     * @return resource
     */
    public function stream()
    {
        return $this->stream ?? throw new \LogicException('the control socket has been closed');
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream);
    }

    /** Whether the peer has closed its end — for a worker, the signal that the parent is gone. */
    public function isEof(): bool
    {
        return $this->eof;
    }

    /** Bytes of an incomplete record held back for the next {@see self::drain()}. */
    public function pendingBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Write one record: exactly {@see ControlRecord::SIZE} bytes, all of them.
     *
     * A short write is retried against the writability of the socket rather than dropped, because
     * half a record on the wire desynchronises the framing for every record after it.
     *
     * @throws \RuntimeException When the peer is gone or refuses the bytes within the timeout.
     */
    public function send(ControlRecord $record): void
    {
        $stream = $this->stream;
        if (!is_resource($stream)) {
            throw new \RuntimeException('cannot send on a closed control socket');
        }

        $bytes    = $record->encode();
        $total    = strlen($bytes);
        $written  = 0;
        $deadline = microtime(true) + self::WRITE_TIMEOUT_SECONDS;

        while ($written < $total) {
            $chunk = @fwrite($stream, substr($bytes, $written));

            if ($chunk === false) {
                throw new \RuntimeException('the control socket refused a record; the peer is gone');
            }

            if ($chunk === 0) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException(sprintf(
                        'the peer did not accept a control record within %.1fs',
                        self::WRITE_TIMEOUT_SECONDS,
                    ));
                }

                $read   = [];
                $write  = [$stream];
                $except = [];
                @stream_select($read, $write, $except, 0, 20_000);

                continue;
            }

            $written += $chunk;
        }
    }

    /**
     * Consume everything the kernel has and return the whole records in it.
     *
     * Never blocks. An incomplete tail stays buffered, so calling this on a socket that has half a
     * record on it returns an empty list and loses nothing.
     *
     * @return list<ControlRecord>
     */
    public function drain(): array
    {
        $stream = $this->stream;

        if (is_resource($stream)) {
            while (true) {
                $chunk = @fread($stream, self::READ_CHUNK);

                if ($chunk === false || $chunk === '') {
                    // Distinguishes "nothing right now" from "the peer closed": both give an empty
                    // read on a non-blocking socket, and only feof() tells them apart.
                    $this->eof = $this->eof || feof($stream);

                    break;
                }

                $this->buffer .= $chunk;

                if (strlen($chunk) < self::READ_CHUNK) {
                    break;
                }
            }
        }

        $records = [];

        while (strlen($this->buffer) >= ControlRecord::SIZE) {
            $frame        = substr($this->buffer, 0, ControlRecord::SIZE);
            $this->buffer = substr($this->buffer, ControlRecord::SIZE);

            $records[] = ControlRecord::decode($frame);
        }

        return $records;
    }

    /**
     * Close this end.
     *
     * Closing the parent's end is what a worker sees as EOF, and vice versa — the backstop that
     * stops a worker outliving the process that forked it.
     */
    public function close(): void
    {
        $stream = $this->stream;

        $this->stream = null;
        $this->buffer = '';

        if (is_resource($stream)) {
            @fclose($stream);
        }
    }
}
