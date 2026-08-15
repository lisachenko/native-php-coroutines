--TEST--
No shipped source file calls serialize, igbinary, json_encode or var_export on any path
--INI--
ffi.enable=1
opcache.jit=off
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

declare(strict_types=1);

include __DIR__ . '/../../vendor/autoload.php';

/**
 * The Never-Serialize Rule, enforced by a scan rather than by review.
 *
 * There is no fallback path in this design: a value that cannot travel as an address is refused,
 * never encoded. A `serialize()` slipped into a diagnostic, a `json_encode()` in a log line or a
 * `var_export()` in an error message all break that promise quietly, and the place they turn up is
 * exactly the code that runs when something has already gone wrong. So the rule is a test.
 *
 * The scan is **token-based**, not a grep: this repository's docblocks say the words "serialize"
 * and "json_encode" constantly, on purpose, and a text search would either be permanently red or
 * be weakened until it caught nothing.
 *
 * There is deliberately no exemption mechanism here. An encoder has no business being reachable
 * from this package at all — not on the data path, not next to it.
 */
$banned = [
    'serialize',
    'unserialize',
    'igbinary_serialize',
    'igbinary_unserialize',
    'json_encode',
    'json_decode',
    'var_export',
];

$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src')),
    '/\.php$/',
);

$found = [];

foreach ($files as $file) {
    $path   = (string) $file;
    $source = (string) file_get_contents($path);

    foreach (token_get_all($source) as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $name = strtolower($token[1]);

        if (in_array($name, $banned, true)) {
            $found[] = sprintf('%s:%d calls %s()', basename($path), $token[2], $token[1]);
        }
    }
}

sort($found);

echo 'encoders reachable from the shipped source: ', count($found), PHP_EOL;

foreach ($found as $hit) {
    echo '  ', $hit, PHP_EOL;
}

// A scan nobody has seen fail is a scan nobody knows works.
$probe = token_get_all('<?php $x = serialize($y); $z = json_encode($w);');
$hits  = 0;

foreach ($probe as $token) {
    if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), $banned, true)) {
        ++$hits;
    }
}

echo 'the scan catches a real call: ', $hits === 2 ? 'yes' : 'NO', PHP_EOL;
?>
--EXPECT--
encoders reachable from the shipped source: 0
the scan catches a real call: yes
