<?php

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude(['var', 'vendor', 'tests/_output', 'tests/Support/_generated'])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return new PhpCsFixer\Config()
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'native_function_invocation' => true,
        'native_constant_invocation' => true,
        'self_accessor' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
