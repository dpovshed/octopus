<?php

$config = \PhpCsFixer\Config::create();
$config->setRules([
    '@Symfony' => true,
    '@Symfony:risky' => true,
    'align_multiline_comment' => [
        'comment_type' => 'all_multiline',
    ],
    'array_syntax' => ['syntax' => 'short'],
    'is_null' => ['use_yoda_style' => false],
    'mb_str_functions' => true,
    'native_function_invocation' => true,
    'ordered_class_elements' => true,
    'ordered_imports' => true,
    'phpdoc_add_missing_param_annotation' => true,
    'phpdoc_order' => true,
    'strict_param' => true,
    'yoda_style' => [
        'equal' => false,
        'identical' => false,
        'less_and_greater' => false,
    ],
]);

$finder = \PhpCsFixer\Finder::create();
$finder->files();
$finder->in('src');
$finder->in('tests');

$config->setFinder($finder);
$config->setUsingCache(true);
$config->setRiskyAllowed(true);

return $config;
