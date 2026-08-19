--TEST--
Every .phpt in the suite carries the two mandatory --INI-- lines and suppresses no diagnostics
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

// The two settings have to hold in every child process the .phpt runner spawns, and nothing but the
// file's own --INI-- section puts them there: ffi.enable cannot be set at runtime, and the JIT
// rewrites the executor internals the engine hooks depend on.
//
// A third line, error_reporting=E_ALL & ~E_DEPRECATED, used to sit alongside them. It was there to
// keep a *dependency's* deprecation out of the captured output back when z-engine was consumed from
// a development branch: PHPUnit's .phpt runner forces display_errors=1, so anything raised is
// prepended to a test's output and fails an --EXPECT-- block over noise. The stable releases this
// package now requires raise nothing, and the suppression was hiding this package's own
// deprecations too (see #39, an SplObjectStorage::contains() call PHP 8.5 deprecated that 12 tests
// were exercising in silence). So the suite runs at the runner's default error_reporting and a
// deprecation from our own code fails a test the day it appears, which is the point.
//
// This test is the guard on both halves: a new .phpt that omits a required line fails here, and so
// does one that re-adds a diagnostic filter to hide something rather than fix it.
$required = [
    'ffi.enable=1',
    'opcache.jit=off',
];

$root  = dirname(__DIR__);
$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)),
    '/\.phpt$/',
);

$paths = [];
foreach ($files as $file) {
    $paths[] = (string) $file;
}
sort($paths);

if ($paths === []) {
    echo 'no .phpt files were found under ', $root, PHP_EOL;
}

$violations = 0;
foreach ($paths as $path) {
    $relative = substr($path, strlen($root) + 1);
    $contents = (string) file_get_contents($path);

    // The --INI-- body: everything between the section header and the next section header.
    if (preg_match('/^--INI--\R(.*?)^--[A-Z_]+--$/ms', $contents, $matches) !== 1) {
        echo $relative, ': has no --INI-- section', PHP_EOL;
        ++$violations;

        continue;
    }

    $declared = array_filter(array_map('trim', preg_split('/\R/', $matches[1]) ?: []));

    foreach ($required as $setting) {
        if (!in_array($setting, $declared, true)) {
            echo $relative, ': --INI-- is missing ', $setting, PHP_EOL;
            ++$violations;
        }
    }

    foreach ($declared as $setting) {
        if (str_starts_with($setting, 'error_reporting')) {
            echo $relative, ': --INI-- sets ', $setting, ' — a deprecation from this package is a bug to fix, not to silence', PHP_EOL;
            ++$violations;
        }
    }
}

echo $violations === 0
    ? 'every .phpt declares both required --INI-- lines and filters no diagnostics'
    : $violations . ' violation(s)';
echo PHP_EOL;
?>
--EXPECT--
every .phpt declares both required --INI-- lines and filters no diagnostics
