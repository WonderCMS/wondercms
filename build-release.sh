#!/usr/bin/env sh
# Build the release ZIP exactly as every release since 3.3.0: a wondercms/ wrapper with
# index.php, .htaccess and themes/sky; no version, license, tests or .github.
# Usage: ./build-release.sh 3.7.0   (builds from the tag; run after `git tag 3.7.0`)
set -eu
cd "$(dirname "$0")" # the script builds the repository it lives in, whatever the caller's folder
TAG="${1:?usage: build-release.sh <tag>}"
NAME="wondercms-$(printf '%s' "$TAG" | tr -d .).zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP" "$TMP.sha"' EXIT # also after a failure partway
trap 'exit 1' INT TERM # dash runs the EXIT trap only through exit, not on a signal
rm -f "$NAME" # zip -r would add to an existing archive and keep its stale members
git archive --format=tar --prefix=wondercms/ "$TAG" index.php .htaccess themes/sky | tar -x -C "$TMP"
( cd "$TMP" && zip -q -r -X "$OLDPWD/$NAME" wondercms )
rm -rf "$TMP"
printf '%s\n' "$NAME"
shasum -a 256 "$NAME"
# Self-test: the index.php inside the ZIP must equal the tagged file byte for byte.
unzip -p "$NAME" wondercms/index.php | shasum -a 256 | cut -d' ' -f1 > "$TMP.sha"
git show "$TAG:index.php" | shasum -a 256 | cut -d' ' -f1 | cmp -s - "$TMP.sha" && echo "self-test: index.php in ZIP matches tag $TAG" || { echo "self-test FAILED: ZIP index.php differs from tag"; rm -f "$TMP.sha"; exit 1; }
rm -f "$TMP.sha"
# Self-test 2: the member list is exactly the one every release since 3.3.0 shipped (12 entries: 5 folders, 7 files).
WANT="wondercms/
wondercms/.htaccess
wondercms/index.php
wondercms/themes/
wondercms/themes/sky/
wondercms/themes/sky/css/
wondercms/themes/sky/css/fonts/
wondercms/themes/sky/css/fonts/catamaran-v7-latin-ext_latin-700.woff2
wondercms/themes/sky/css/fonts/catamaran-v7-latin-ext_latin-regular.woff2
wondercms/themes/sky/css/style.css
wondercms/themes/sky/theme.php
wondercms/themes/sky/wcms-modules.json"
GOT="$(unzip -Z1 "$NAME" | sort)"
[ "$GOT" = "$WANT" ] && echo "self-test: member list is the 12-entry release layout" || { echo "self-test FAILED: unexpected member list"; printf '%s\n' "$GOT"; exit 1; }
