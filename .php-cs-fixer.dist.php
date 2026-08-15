<?php

declare(strict_types=1);

// tests/ is deliberately absent: a .phpt is not a PHP file, and the fixtures under tests/Support
// exist to be shaped exactly as a test needs them. tools/ is included — the soak scripts are
// ordinary code that outlives the session that wrote them.
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tools'])
    ->name('*.php')
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0'             => true,
        'declare_strict_types'   => true,
        'ordered_imports'        => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'      => true,
        'single_quote'           => true,
        'array_syntax'           => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
    ])
    ->setFinder($finder);
