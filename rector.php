<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // A stream wrapper's methods are called by PHP with a fixed argument
        // list — `stream_open($path, $mode, $options, &$openedPath)` and
        // `stream_set_option($option, $arg1, $arg2)`. Rector sees parameters a
        // body happens not to read; PHP sees a contract. Dropping them makes
        // the class stop looking like the thing it has to be.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/src/Stream/StreamWrapper.php',
        ],
    ])
    ->withRules([AddNameToLiteralArgumentRector::class]);
