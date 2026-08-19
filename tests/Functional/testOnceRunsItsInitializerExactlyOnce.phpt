--TEST--
Once runs its initializer exactly once and hands the same result to every caller
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Sync\Once;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();
$once      = new Once($scheduler);
$runs      = 0;

$initialize = static function () use (&$runs): string {
    $runs++;

    return 'connection';
};

foreach ([1, 2, 3] as $number) {
    $scheduler->spawn(function () use ($once, $initialize, $number): void {
        $result = $once->do($initialize);
        echo "caller {$number} got {$result}\n";
    });
}

$scheduler->loop();

echo 'initializer ran ', $runs, " time(s)\n";
echo 'hasRun: ', var_export($once->hasRun(), true), PHP_EOL;
echo 'hasFailed: ', var_export($once->hasFailed(), true), PHP_EOL;
?>
--EXPECT--
caller 1 got connection
caller 2 got connection
caller 3 got connection
initializer ran 1 time(s)
hasRun: true
hasFailed: false
