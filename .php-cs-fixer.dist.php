<?php

return (new PhpCsFixer\Config())
    ->setRules(array(
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP71Migration' => true,
        '@PHP71Migration:risky' => true,
        '@PHP73Migration' => true,
        '@PHPUnit75Migration:risky' => true,
        '@DoctrineAnnotation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'class_definition' => ['single_line' => false],
        'declare_strict_types' => false,
        'ordered_imports' => true,
        'php_unit_strict' => false,
        'php_unit_test_class_requires_covers' => false,
        'self_accessor' => false,
        'single_line_comment_style' => false,
    ))
    ->setRiskyAllowed(true)
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in(__DIR__)
            ->exclude([
                'vendor',
            ])
    )
    ->setCacheFile('.php-cs-fixer.cache')
;
