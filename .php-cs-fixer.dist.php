<?php
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true, 'array_syntax' => ['syntax' => 'short']])
    ->setFinder(PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests']));
