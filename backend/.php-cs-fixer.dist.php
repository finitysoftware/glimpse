<?php

declare(strict_types=1);

// Code style of the backend: PER Coding Style 2.0, the successor of PSR-12. `composer format` applies it,
// `composer format:check` (also in CI) only reports differences.
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/bin', __DIR__ . '/public', __DIR__ . '/tests'])
    ->append([__DIR__ . '/bootstrap.php', __DIR__ . '/config/settings.example.php', __FILE__]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
