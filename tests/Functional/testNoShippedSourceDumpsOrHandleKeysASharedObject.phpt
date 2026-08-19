--TEST--
Nothing in the shipped source dumps a shared object or keys one by its object-store handle
--INI--
ffi.enable=1
opcache.jit=off
--FILE--
<?php

declare(strict_types=1);

include __DIR__ . '/../../vendor/autoload.php';

/**
 * Two corrections from the substrate spikes, enforced by a scan.
 *
 * `var_dump()`, `json_encode()`, `get_object_vars()` and an `(array)` cast are read-shaped
 * operations that make engine C code *write* a per-process `properties` pointer into the object it
 * is reading. On a shared object that pointer is a sibling's request heap, and the next process to
 * follow it segfaults. This bites diagnostics hardest — a panic handler or a deadlock dump is
 * exactly the code that reaches for a dump of the value it is reporting — so the panic and
 * diagnostic paths are in scope here, not exempt from it.
 *
 * `spl_object_id()` and `SplObjectStorage` are the other half. Forked children inherit one
 * object-store free list and are handed *identical* handle numbers for different objects, and the
 * store overwrites the shared field with a sentinel precisely so nobody builds identity on it. The
 * only cross-process identity is the arena address.
 *
 * # Why this one has an exemption and the encoder scan does not
 *
 * A handle really is a valid identity for an object that never leaves the process — Layer 1's
 * `Context` keys its children that way, correctly. So a use may be exempted by an
 * `@local-identity` marker in a comment within the ten lines above it, which has to say *why* the
 * objects being keyed are process-local. That is review friction on purpose: adding one is a
 * deliberate act, and a use without one fails the suite.
 */
$forbidden = [
    'var_dump'         => 'dumps an object, writing a per-process properties pointer into it',
    'json_encode'      => 'dumps an object, writing a per-process properties pointer into it',
    'get_object_vars'  => 'dumps an object, writing a per-process properties pointer into it',
    'debug_zval_dump'  => 'dumps an object, writing a per-process properties pointer into it',
    'spl_object_id'    => 'keys an object by a handle that collides across a fork',
    'spl_object_hash'  => 'keys an object by a handle that collides across a fork',
];

$exemptible = ['spl_object_id', 'spl_object_hash', 'splobjectstorage'];

$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src')),
    '/\.php$/',
);

/** @var list<string> $unmarked */
$unmarked = [];
/** @var list<string> $marked */
$marked = [];

foreach ($files as $file) {
    $path   = (string) $file;
    $source = (string) file_get_contents($path);
    $tokens = token_get_all($source);

    // Every line carrying an @local-identity marker, so a use can be checked against them.
    $markers = [];

    foreach ($tokens as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
            && str_contains($token[1], '@local-identity')) {
            $start = $token[2];

            foreach (range($start, $start + substr_count($token[1], "\n")) as $line) {
                $markers[$line] = true;
            }
        }
    }

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        $name = null;

        if ($token[0] === T_STRING && isset($forbidden[strtolower($token[1])])) {
            $name = strtolower($token[1]);
        } elseif (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
            && strtolower(ltrim($token[1], '\\')) === 'splobjectstorage') {
            $name = 'splobjectstorage';
        } elseif ($token[0] === T_ARRAY_CAST) {
            $name = '(array)';
        }

        if ($name === null) {
            continue;
        }

        $line   = $token[2];
        $nearby = false;

        foreach (range(max(1, $line - 10), $line) as $candidate) {
            if (isset($markers[$candidate])) {
                $nearby = true;
            }
        }

        $where = sprintf('%s:%d %s', basename($path), $line, $name);

        if ($nearby && in_array($name, $exemptible, true)) {
            $marked[] = $where;

            continue;
        }

        $unmarked[] = $where;
    }
}

sort($unmarked);
sort($marked);

echo 'unjustified uses: ', count($unmarked), PHP_EOL;

foreach ($unmarked as $hit) {
    echo '  ', $hit, PHP_EOL;
}

echo 'uses justified as process-local: ', count($marked) > 0 ? 'some' : 'none', PHP_EOL;

// The scan has to be able to fail, or it proves nothing.
$probe   = token_get_all('<?php var_dump($shared); $m = (array) $shared; $s = new SplObjectStorage();');
$catches = 0;

foreach ($probe as $token) {
    if (!is_array($token)) {
        continue;
    }

    if ($token[0] === T_ARRAY_CAST) {
        ++$catches;
    } elseif ($token[0] === T_STRING && isset($forbidden[strtolower($token[1])])) {
        ++$catches;
    } elseif ($token[0] === T_STRING && strtolower($token[1]) === 'splobjectstorage') {
        ++$catches;
    }
}

echo 'the scan catches a real dump, cast and storage: ', $catches === 3 ? 'yes' : 'NO', PHP_EOL;
?>
--EXPECT--
unjustified uses: 0
uses justified as process-local: some
the scan catches a real dump, cast and storage: yes
