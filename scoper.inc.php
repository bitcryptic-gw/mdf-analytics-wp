<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'MdfAnalytics\\Vendor',

    'finders' => [
        // The library files — scoped PHP files placed under vendor/league/html-to-markdown/
        Finder::create()
            ->files()
            ->in('vendor')
            ->path('league/html-to-markdown')
            ->name('*.php')
            ->exclude(['test', 'tests', 'Test', 'Tests']),
        // Anchor file: including vendor/autoload.php forces php-scoper's common-path
        // to be vendor/ rather than deeper, so the output preserves the full
        // vendor/league/html-to-markdown/... tree instead of flattening.
        Finder::create()
            ->files()
            ->in('vendor')
            ->name('autoload.php')
            ->depth('== 0'),
    ],

    'exclude-namespaces' => [
        // Do not prefix Composer's own internal classes
        'Composer',
    ],

    'expose-global-constants' => false,
    'expose-global-functions' => false,
    'expose-global-classes' => false,
];
