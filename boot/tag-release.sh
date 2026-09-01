#!/usr/bin/env bash
# tag-release.sh — create and push a semver release tag for mdf-analytics-wp
# Usage: ./boot/tag-release.sh [version]
#   version: optional X.Y.Z semver. If omitted, read from the `Version:` header
#            in mdf-analytics.php (the source of truth, already committed).
# Creates an annotated tag v<version> on the current commit and pushes it.
#
# NOTE: this script only handles the git tag. Creating/publishing the GitHub
# Release itself is a separate, not-yet-automated step.
set -euo pipefail

REPO="bitcryptic-gw/mdf-analytics-wp"
PLUGIN_FILE="mdf-analytics.php"

# --- Version resolution ------------------------------------------------------
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    if [ ! -f "$PLUGIN_FILE" ]; then
        echo "ERROR: ${PLUGIN_FILE} not found — run this from the repo root." >&2
        exit 1
    fi
    VERSION=$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/p' "$PLUGIN_FILE" | head -n1)
    if [ -z "$VERSION" ]; then
        echo "ERROR: could not read a version from the Version: header in ${PLUGIN_FILE}." >&2
        exit 1
    fi
    echo "[tag] No version argument given — using ${VERSION} from ${PLUGIN_FILE}."
fi

# --- Semver validation -------------------------------------------------------
if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "ERROR: Invalid version. Expected X.Y.Z semver, got: ${VERSION}" >&2
    exit 1
fi

TAG="v${VERSION}"

# --- Clean working tree ------------------------------------------------------
if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: working tree is not clean. Commit or stash changes before tagging." >&2
    git status --short >&2
    exit 1
fi

# --- Tag collision check (no auto-incrementing suffix) -----------------------
if git rev-parse "${TAG}" &>/dev/null; then
    echo "ERROR: tag ${TAG} already exists locally." >&2
    echo "Bump the version in ${PLUGIN_FILE} (and CHANGELOG.md / README.md) first." >&2
    exit 1
fi
if git ls-remote --tags origin "${TAG}" 2>/dev/null | grep -q .; then
    echo "ERROR: tag ${TAG} already exists on origin." >&2
    echo "Bump the version in ${PLUGIN_FILE} (and CHANGELOG.md / README.md) first." >&2
    exit 1
fi

# --- Tag message -------------------------------------------------------------
# Prefer the CHANGELOG.md section for this version; fall back to a short message.
MSG="Release ${TAG}"
if [ -f CHANGELOG.md ]; then
    SECTION=$(awk -v h="## [${VERSION}]" '
        index($0, h) == 1 { in_section = 1; next }
        in_section && index($0, "## [") == 1 { exit }
        in_section { print }
    ' CHANGELOG.md)
    if [ -n "$SECTION" ]; then
        MSG="$SECTION"
    fi
fi

echo "=== Tag Release ==="
echo "Version: ${VERSION}"
echo "Tag:     ${TAG}"
echo "Commit:  $(git rev-parse --short HEAD)"
echo ""
git tag -a "$TAG" -m "$MSG"
echo "[tag] Created annotated tag: ${TAG}"
echo "[tag] Pushing tag to origin..."
git push origin "$TAG"
echo ""
echo "Done — tag ${TAG} created and pushed to ${REPO}."
echo "The GitHub Release (if wanted) is a separate, manual step — create it"
echo "from this tag, using the [${VERSION}] section of CHANGELOG.md as the body:"
echo "  https://github.com/${REPO}/releases/new?tag=${TAG}"
