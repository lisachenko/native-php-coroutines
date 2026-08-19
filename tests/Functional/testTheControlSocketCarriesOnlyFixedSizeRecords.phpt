--TEST--
A real worker answers over the raw socket in whole 16-byte records and nothing else
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Parallel\ControlSocket;
use Lisachenko\NativePhpCoroutines\Parallel\PreforkTaskDirectory;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\ControlRecord;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Opcode;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\Tag;
use Lisachenko\NativePhpCoroutines\Parallel\Protocol\TaggedRecord;
use Lisachenko\NativePhpCoroutines\Parallel\WorkerChild;
use Lisachenko\NativePhpCoroutines\Tests\Support\ConstantTask;
use Lisachenko\NativePhpCoroutines\Tests\Support\SumTask;

use function Lisachenko\NativePhpCoroutines\Tests\Support\parallelChildrenLeft;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/parallel.php';

// The pair is created here rather than through the supervisor so this side stays *raw*: nothing
// frames, buffers or decodes on the parent end, and the bytes a worker really put on the wire can
// be counted one by one.
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
[$parentEnd, $childEnd] = $pair;

$tasks     = new PreforkTaskDirectory();
$sum       = $tasks->register(new SumTask(2, 3));
$truthy    = $tasks->register(new ConstantTask(true));
$fractions = $tasks->register(new ConstantTask(0.5));

$pid = pcntl_fork();

if ($pid === 0) {
    fclose($parentEnd);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    exit(WorkerChild::main(new ControlSocket($childEnd), $tasks));
}

fclose($childEnd);

$sent = 0;

foreach ([[7, $sum], [9, $truthy], [11, $fractions]] as [$slot, $address]) {
    $frame = (new ControlRecord(Opcode::SPAWN, $slot, TaggedRecord::address(Tag::OBJ, $address)))->encode();
    $sent += strlen($frame);
    fwrite($parentEnd, $frame);
}

fwrite($parentEnd, (new ControlRecord(Opcode::SHUTDOWN))->encode());

echo 'bytes sent for 3 spawns: ', $sent, "\n";

// Read to EOF: the worker closes when the SHUTDOWN record is honoured, so this terminates on its
// own, and the socket timeout keeps it terminating even if it does not.
stream_set_timeout($parentEnd, 5);
$received = '';

while (!feof($parentEnd)) {
    $chunk = fread($parentEnd, 4096);

    if ($chunk === false || $chunk === '') {
        $meta = stream_get_meta_data($parentEnd);

        if ($meta['timed_out']) {
            break;
        }
    }

    $received .= (string) $chunk;
}

fclose($parentEnd);

echo 'bytes received for 3 results: ', strlen($received), "\n";
echo 'a whole number of records: ', strlen($received) % ControlRecord::SIZE === 0 ? 'yes' : 'NO', "\n";

// The decisive assertion: three answers occupy exactly three records. A serialized value — of any
// encoding, for any one of the three results — makes this number bigger, and this test red.
echo 'records: ', intdiv(strlen($received), ControlRecord::SIZE), "\n";

foreach (str_split($received, ControlRecord::SIZE) as $frame) {
    $record = ControlRecord::decode($frame);

    printf(
        "  %s slot #%d tag %s payload %s\n",
        $record->opcode->name,
        $record->slotId,
        $record->value?->tag->name,
        var_export($record->value?->payload, true),
    );
}

pcntl_waitpid($pid, $status);

echo 'worker exited cleanly: ', pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0 ? 'yes' : 'no', "\n";
echo 'children left: ', parallelChildrenLeft(), "\n";
?>
--EXPECT--
bytes sent for 3 spawns: 48
bytes received for 3 results: 48
a whole number of records: yes
records: 3
  RESULT slot #7 tag INT payload 5
  RESULT slot #9 tag TRUE payload 0
  RESULT slot #11 tag FLOAT payload 0.5
worker exited cleanly: yes
children left: none
