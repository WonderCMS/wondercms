# Releasing WonderCMS

The `main` branch is the update channel: when an admin clicks **Update**, their site downloads
`main/index.php` and writes it over itself. Merging into `main` is the release. Everything
below exists to make sure only a complete, versioned `index.php` ever gets there.

## Rules

1. `index.php` reaches `main` only through a release branch (`370`, `371`, ...) merged with a
   merge commit. Never commit to `main` directly, never squash.
2. The version bump is the **last** commit on the release branch: `define('VERSION', 'X.Y.Z')`
   in `index.php` and the `version` file, together. CI fails when they differ, and fails a PR
   into `main` that changes `index.php` without a higher version.
3. The admin CSS and JS pins (`wcms-admin.min.css`, `wcms-admin.min.js` from
   `WonderCMS/wondercms-cdn-files`) use the **same** tag and both carry an `integrity` hash.
   If either file changes, tag the CDN repo first, then update the pin and hash on the
   release branch, before the merge.
4. PHP floor is 7.4. CI lints and boots on 7.4, 8.1, 8.3, 8.4 and 8.5 and runs the tests.
5. There is no rollback. Sites that already updated never download an older version, so a bad
   release is fixed by the next patch release, never by reverting `main`.

## What the updater accepts

Since 3.7.0 a site only replaces its `index.php` when the download starts with `<?php`, contains `class Wcms`,
defines `define('VERSION', 'x.y.z');` with a version higher than the running one, and parses. Keep that exact
`define` line format; a release that breaks it is never installed.

## Checklist

1. Release branch from `main`; every change as its own commit with a test. Run
   `php phpunit.phar` locally (phpunit.phar is ignored by git; download it from
   https://phar.phpunit.de/).
2. Version bump commit (the old updater only offers an Update when the remote version is higher,
   so the rehearsal needs the bumped file). CI green.
3. Rehearse: install the branch's `index.php` in a non-public folder on the server, run an
   **Update** from a copy of the previous release pointed at the branch's raw URL, try to break it.
   A fix after this point is a new commit; re-run the gates and the rehearsal.
4. Merge the release branch into `main` (merge commit).
5. Wait for GitHub's raw cache (up to 5 minutes), then check that
   `https://raw.githubusercontent.com/WonderCMS/wondercms/main/version` shows the new version and that the
   SHA-256 of the served `main/index.php` equals `git show <merge-sha>:index.php` (the 3.6.0 updater writes
   whatever it receives). Check the same two things on the legacy URL
   `https://raw.githubusercontent.com/robiso/wondercms/master/` (GitHub redirects it; sites on 3.0 to 3.3
   still update from there). Compare twice, five minutes apart.
6. Canary: confirm the live `index.php` is the stock previous release (compare its SHA-256), back up `index.php`
   and `data/`, click **Update** on wondercms.com, check the site, the login, a save, and the `VERSION` in the
   file on disk (opcache may serve the old code for a moment). Hold the news post and any announcement
   until the canary has passed; the daily notice means publicity is what drives the fleet's updates.
   **If the canary fails:** restore the backed-up `index.php` on wondercms.com, and fix forward. `main` cannot be
   reverted (CI requires a higher version for any change to `index.php`, and protection applies to admins):
   the remedy is the next patch release (`X.Y.Z+1`) carrying the fix, or, in the worst case, the previous
   release's code with `VERSION` set to `X.Y.Z+1` (sites already on `X.Y.Z` only accept a higher version). Nothing is announced until that patch is live and verified.
7. Tag the merge commit on `main`, not the branch head: `git fetch origin && git tag X.Y.Z <merge-sha> &&
   git push origin X.Y.Z`. Nothing else goes into `main` between the merge and the tag.
8. Build the ZIP from the tag: `./build-release.sh X.Y.Z`, then create the GitHub release with
   notes (Features and fixes / Changes / Backwards compatibility / How to update / Installation
   with the SHA-256 of the uploaded asset) and attach the ZIP.
9. wondercms.com: copy the ZIP next to the previous ones, point `download-latest.php` at the new
   asset, update the latest/download/news/contributors pages and the docs, rebuild the demo. The site is
   behind Cloudflare: purge its cache after the page edits (Caching, Purge Everything), and remember
   `download-latest.php` is browser-cached for 48 hours, so check it from a private window.
10. Theme or plugin changes shipped with the release (for example `themes/sky`) are pushed to
    their own repositories with a version bump so existing sites receive them; confirm the CDN
    repo's daily `wcms-modules.json` workflow is enabled and has run.
11. Close the fixed issues with the commit links; fast-forward `develop` to `main`.
