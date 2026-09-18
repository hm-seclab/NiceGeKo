<?php

declare(strict_types=1);

$config = new PhpCsFixer\Config();

return $config->setRules(
    [
        '@auto' => true,
        '@auto:risky' => true,

        // Adds a default ``@coversNothing`` annotation to PHPUnit test classes
        // that have no ``@covers*`` annotation.
        'php_unit_test_class_requires_covers' => true,
        // Unused use statements must be removed.
        'no_unused_imports' => true,
        // Ordering use statements.
        'ordered_imports' => true,
        // Orders the elements of classes/interfaces/traits/enums.
        'ordered_class_elements' => true,
        // Ensures a single space after language constructs.
        'single_space_around_construct' => true,
        // An empty line feed must precede any configured statement.
        'blank_line_before_statement' => true,
        // Functions should be used with $strict param set to true.
        'strict_param' => true,
        // Annotations in PHPDoc should be grouped together so that annotations
        // of the same type immediately follow each other. Annotations of a
        // different type are separated by a single blank line.
        'phpdoc_separation' => true,
        // Add leading \ before constant invocation of internal constant to speed up resolving.
        'native_constant_invocation' => true,
        // Add leading \ before function invocation to speed up resolving.
        'native_function_invocation' => true,
        // Calls to PHPUnit\Framework\TestCase static methods must all be of
        // the same type, either $this->, self:: or static::.
        'php_unit_test_case_static_method_calls' => true,

        'header_comment' => [
            'header' => 'SPDX-License-Identifier: AGPL-3.0-or-later',
        ],
    ]
)
    ->setRiskyAllowed(true)
    ->setFinder(PhpCsFixer\Finder::create()->in(__DIR__))
;
