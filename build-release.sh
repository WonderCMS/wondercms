#!/usr/bin/env sh
# Build the release ZIP exactly as every release since 3.3.0: a wondercms/ wrapper with
# index.php, .htaccess and themes/sky; no version, license, tests or .github.
# Usage: ./build-release.sh 3.7.0   (builds from the tag; run after `git tag 3.7.0`)
set -eu
TAG="${1:?usage: build-release.sh <tag>}"
NAME="wondercms-$(printf '%s' "$TAG" | tr -d .).zip"
TMP="$(mktemp -d)"
git archive --format=tar --prefix=wondercms/ "$TAG" index.php .htaccess themes/sky | tar -x -C "$TMP"
( cd "$TMP" && zip -q -r -X "$OLDPWD/$NAME" wondercms )
rm -rf "$TMP"
printf '%s\n' "$NAME"
shasum -a 256 "$NAME"
# Self-test: the index.php inside the ZIP must equal the tagged file byte for byte.
unzip -p "$NAME" wondercms/index.php | shasum -a 256 | cut -d' ' -f1 > "$TMP.sha"
git show "$TAG:index.php" | shasum -a 256 | cut -d' ' -f1 | cmp -s - "$TMP.sha" && echo "self-test: index.php in ZIP matches tag $TAG" || { echo "self-test FAILED: ZIP index.php differs from tag"; rm -f "$TMP.sha"; exit 1; }
rm -f "$TMP.sha"
