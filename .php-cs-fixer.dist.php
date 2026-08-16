<?php

declare(strict_types=1);

/**
 * Code style for phpvin.
 *
 * PSR-12 plus a handful of rules that keep diffs small and intent visible.
 * Run `composer style` to check, `composer style:fix` to apply.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/skeleton/app'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,

        // Every file declares strict types; this keeps it that way.
        'declare_strict_types' => true,

        // Imports sorted and trimmed, so merges do not fight over them.
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,

        // Import global classes and use the short name: `PDO`, not `\PDO`.
        // Leave functions and constants alone: `\strlen()` everywhere is noise.
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
            'import_constants' => false,
        ],
        'fully_qualified_strict_types' => true,

        // `! $x` rather than `!$x`, consistently.
        'unary_operator_spaces' => false,
        'not_operator_with_successor_space' => true,

        // Trailing commas make one-line-per-argument diffs clean.
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],

        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,
        'no_superfluous_phpdoc_tags' => false,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try', 'if', 'foreach']],
        'concat_space' => ['spacing' => 'one'],
        'method_chaining_indentation' => true,
        'no_empty_phpdoc' => true,
        'phpdoc_trim' => true,
        'single_line_empty_body' => true,
    ])
    ->setFinder($finder);
