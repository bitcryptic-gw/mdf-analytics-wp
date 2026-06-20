<?php

// ---------------------------------------------------------------------------
// Custom PSR-4 autoloader for the namespace-scoped league/html-to-markdown
// vendored into MDF Analytics.  No Composer dependency at runtime.
// ---------------------------------------------------------------------------

spl_autoload_register(static function (string $class): void {
    $prefix = 'MdfAnalytics\\Vendor\\League\\HTMLToMarkdown\\';
    $len    = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file     = __DIR__ . '/league/html-to-markdown/src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load the scoper autoload map for string-based class references.
// php-scoper generates this to map original (unprefixed) class names to the
// scoped files, handling cases where the library uses ::class-like strings
// or class_exists() calls internally.
if (file_exists(__DIR__ . '/scoper-autoload.php')) {
    require_once __DIR__ . '/scoper-autoload.php';
}
