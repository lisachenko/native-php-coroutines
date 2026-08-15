--TEST--
Cancelling a context cancels every context below it
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

use Lisachenko\NativePhpCoroutines\Context;
use Lisachenko\NativePhpCoroutines\Tests\Support\FakeScheduler;

include __DIR__ . '/../../vendor/autoload.php';
include __DIR__ . '/../Support/bootstrap.php';

$scheduler = new FakeScheduler();

$request    = Context::withCancel($scheduler);
$database   = Context::withCancel($request);
$statement  = Context::withCancel($database);
$sibling    = Context::withCancel($request);

$report = static function (string $label) use ($request, $database, $statement, $sibling): void {
    printf(
        "%s: request %s, database %s, statement %s, sibling %s\n",
        $label,
        var_export($request->isCancelled(), true),
        var_export($database->isCancelled(), true),
        var_export($statement->isCancelled(), true),
        var_export($sibling->isCancelled(), true),
    );
};

$report('start');
echo 'children of the request: ', $request->childCount(), PHP_EOL;

// Cancelling one branch leaves the rest of the tree alone, and detaches it from its parent rather
// than leaving a cancelled child accumulating there.
$database->cancel();
$report('after cancelling the database branch');
echo 'children of the request: ', $request->childCount(), PHP_EOL;

$request->cancel();
$report('after cancelling the request');
echo 'children of the request: ', $request->childCount(), PHP_EOL;

// A context created under an already cancelled parent is born cancelled, rather than looking live
// and never firing.
$late = Context::withCancel($request);
echo 'a context created after the cancellation: ', var_export($late->isCancelled(), true), PHP_EOL;

// Idempotent, so an operation that finished normally can cancel in a finally without checking.
$request->cancel();
$request->cancel();
echo "cancelling again is a no-op\n";
?>
--EXPECT--
start: request false, database false, statement false, sibling false
children of the request: 2
after cancelling the database branch: request false, database true, statement true, sibling false
children of the request: 1
after cancelling the request: request true, database true, statement true, sibling true
children of the request: 0
a context created after the cancellation: true
cancelling again is a no-op
