#!/bin/bash
# ---------------------------------------------------------------------------
# build-vendor.sh — Vendors and namespace-scopes league/html-to-markdown
#
# IMPORTANT: php-scoper 0.18.8+ requires PHP 8.2+.  The composer.json
# constraint ^0.18 resolves to 0.18.7 on PHP 8.1 hosts.  If you upgrade
# the host PHP beyond 8.1, Composer will resolve a newer php-scoper
# automatically — no changes needed here.
# ---------------------------------------------------------------------------
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ---------------------------------------------------------------------------
# Temp build area — everything happens here, nothing from here ends up committed.
# ---------------------------------------------------------------------------
BUILD_DIR="$(mktemp -d /tmp/mdf-build-vendor.XXXXXX)"
cleanup() { rm -rf "$BUILD_DIR"; }
trap cleanup EXIT

echo "==> Staging composer.json into temp build dir..."
cp composer.json "$BUILD_DIR/"

echo "==> Installing dependencies (unscoped) in temp build dir..."
composer install --no-scripts --working-dir="$BUILD_DIR"

echo "==> Copying lockfile back to repo..."
cp "$BUILD_DIR/composer.lock" "$SCRIPT_DIR/composer.lock"

# ---------------------------------------------------------------------------
# Run php-scoper to namespace-scope league/html-to-markdown and output into the
# repo's vendor/ directory with the standard Composer vendor layout.
# ---------------------------------------------------------------------------
echo "==> Running php-scoper..."

# Clean any previous scoped output
rm -rf "$SCRIPT_DIR/vendor"

"$BUILD_DIR/vendor/bin/php-scoper" add-prefix \
    --config="$SCRIPT_DIR/scoper.inc.php" \
    --output-dir="$SCRIPT_DIR/vendor" \
    --working-dir="$BUILD_DIR" \
    --force

# ---------------------------------------------------------------------------
# Post-processing: replace the scoped Composer autoloader (which references
# every package from the build environment, not just league/*) with a tiny
# PSR-4 autoloader that maps only the scoped namespace to the library's src/
# directory.  The php-scoper-generated scoper-autoload.php is left in place —
# it handles string-based class-name references to the original class names.
# ---------------------------------------------------------------------------
echo "==> Replacing autoloader with minimal scoped PSR-4 autoloader..."

cat > "$SCRIPT_DIR/vendor/autoload.php" <<'PHPEOF'
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
PHPEOF

# Remove the Composer autoloader infrastructure that php-scoper copied over
rm -rf "$SCRIPT_DIR/vendor/composer"

echo ""
echo "✓ Vendor build complete — scoped files in $SCRIPT_DIR/vendor/"
