--TEST--
Every .phpt in the suite carries the three mandatory --INI-- lines
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

// The three settings have to hold in every child process the .phpt runner spawns, and nothing but
// the file's own --INI-- section puts them there: ffi.enable cannot be set at runtime, the JIT
// rewrites the executor internals the engine hooks depend on, and PHPUnit's .phpt runner forces
// display_errors=1, so an unsuppressed dependency deprecation would be prepended to the captured
// output and break an --EXPECT-- block that has nothing to do with it.
//
// This test is the guard: a new .phpt that omits a line fails here rather than years later on
// somebody else's machine. It scans the whole suite, including itself.
$required = [
    'ffi.enable=1',
    'opcache.jit=off',
    'error_reporting=E_ALL & ~E_DEPRECATED',
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
}

echo $violations === 0
    ? 'every .phpt declares all three required --INI-- lines'
    : $violations . ' violation(s)';
echo PHP_EOL;
?>
--EXPECT--
every .phpt declares all three required --INI-- lines
