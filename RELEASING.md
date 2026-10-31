# Releasing WonderCMS

Before a release: grep this file for `<[a-z-]*>`; the `<robert-si-...>` values come from the private `release-370/canary-sites.md`, `<backup-folder>`, `<merge-sha>` and `<branch>` at the step that uses them.

The `main` branch is the update channel: when an admin clicks **Update**, their site downloads
`main/index.php` and writes it over itself. Merging into `main` is the release. Everything
below exists to make sure only a complete, versioned `index.php` ever gets there.

## Rules

1. `index.php` reaches `main` only through a release branch (`370`, `371`, ...) merged with a
   merge commit. Never commit to `main` directly, never squash.
2. The version bump changes `define('VERSION', 'X.Y.Z')` in `index.php` and the `version` file, together.
   It need not be the last commit on the release branch: review-driven fixes may follow it (3.7.0 had
   several). What protects the bump is CI on every commit (the `VERSION` line keeps the exact shape the
   updater parses, and the `version` file equals the constant) and on the PR into `main` (a change to
   `index.php` must carry a higher version than `main`'s, the check an older site's updater makes before
   it offers the Update), plus the review greenlight for the sha that is merged. The fleet gets the
   merge commit's `index.php`: the rehearsal, the precheck and the canary test that file, not an earlier
   commit of the branch.
3. The admin CSS and JS pins (`wcms-admin.min.css`, `wcms-admin.min.js` from
   `WonderCMS/wondercms-cdn-files`) use the **same** tag and both carry an `integrity` hash.
   If either file changes, tag the CDN repo first, then update the pin and hash on the
   release branch, before the merge.
4. PHP floor is 7.4. CI lints and boots on 7.4, 8.1, 8.3, 8.4 and 8.5 and runs the tests. The updater
   parses the download with the **host's** PHP (`token_get_all`), so a core that uses newer syntax is refused
   on older hosts and never installed. That catches newer syntax only, not a call to a function the host's PHP
   lacks (`str_contains()` on 7.4 would install and fail at run time): the 7.4 CI leg is the guard for those, so
   nothing above the floor goes into `index.php`. Without the tokenizer
   extension the updater instead requires the file to end with the class's closing brace, `}` at column 0 as
   the last line; keep `index.php` ending that way (the class is the last thing in the file).
5. There is no rollback. Sites that already updated never download an older version, so a bad
   release is fixed by the next patch release, never by reverting `main`.
6. Never create a branch named `master` on `WonderCMS/wondercms`: sites from 3.0 to 3.3 update from
   `raw.githubusercontent.com/robiso/wondercms/master/`, which GitHub serves from this repository's `main`
   through the rename only as long as no `master` exists here.

## What the updater accepts

Since 3.7.0 a site only replaces its `index.php` when the download starts with `<?php`, contains `class Wcms`,
defines `define('VERSION', 'x.y.z');` with a version higher than the running one, and parses. Keep that exact
`define` line format (CI greps for it); a release that breaks it is never installed. A theme, plugin or
CDN push is a release too: existing sites pull those from their own repositories and from
`wondercms-cdn-files`, so they get the same care as `main` (the theme and plugin repositories version by their `version`
file and the CDN list, not by git tags; the CDN repository's admin assets are the exception: `index.php` pins them by
tag, rule 3 above; every push is read back through the API and recorded).

## Checklist

1. Release branch from `main`; every change as its own commit with a test. Run
   `php phpunit.phar` locally (phpunit.phar is ignored by git; download it from
   https://phar.phpunit.de/). On PHP 7.4 the suite needs a UTF-8 locale (one search test uses Cyrillic text):
   `LC_ALL=en_US.UTF-8 php phpunit.phar`. Read the `Tests:` line, not the exit code: in a Docker `php:7.4-cli` image
   without the zip extension the suite exits 0 having run no tests.
2. Version bump commit (the old updater only offers an Update when the remote version is higher,
   so the rehearsal needs the bumped file). CI green.
3. Rehearse: install the branch's `index.php` in a non-public folder on the server, run an
   **Update** from a copy of the previous release pointed at the branch's raw URL, try to break it. Locally, a raw
   source for the old updater must be served as a file (a `readfile()` router under `php -S`): the built-in server
   executes `index.php` and the 3.6.0 updater would write the rendered HTML over the core. Start that server with
   `php -d opcache.enable=0 -S ...` (the cli-server SAPI honours `opcache.enable`, so a request within two seconds of
   the swap still runs the old compile), and restart it after swapping a file for a symlink (the realpath cache).
   A fix after this point is a new commit; re-run the gates and the rehearsal. The greenlight comment on the PR
   names the head sha, the tree hash, the `index.php` SHA-256 and the PR's draft status at that moment, and is written
   only after the commit MESSAGES on the branch were read too (`git log --format=%B main..HEAD`): a rebase can carry
   the right tree with a stale message.
4. Precheck: the working tree is clean, `git rev-parse HEAD` equals the PR's `headRefOid` and both equal the sha
   the last greenlight names; otherwise stop (a change made after the gate ships unreviewed). Then set the PR title to `WonderCMS X.Y.Z` first (GitHub puts the PR title in the merge commit's body under the
   subject "Merge pull request #N from WonderCMS/<branch>"; a review marker such as ✅ would live in `main`'s
   history). Check that the PR's head sha is the one the gate greenlit (`gh pr view N --json headRefOid`) and
   that `gh pr view N --json closingIssuesReferences` is empty (nothing auto-closes at the merge). The release PR's
   body names the issues it fixes as plain references, never with a closing keyword (`Fixes`, `Closes`, `Resolves`,
   nor in a commit message on the branch): a merge must not close an issue before the canary proves the release, so
   the issues are closed by hand in step 11 with their close-out texts once both canaries pass. The PR check sees only
   the body: also run `git log origin/main..<branch> --format=%B | grep -iE '\b(close[sd]?|fix(e[sd])?|resolve[sd]?) #[0-9]+'`, since
   GitHub closes an issue named that way in ANY commit that reaches `main`. A branch that already carries such commits
   is not rewritten (it is shared and reviewed): right after the merge, reopen the issues it closed (`gh issue reopen N`
   for each, one comment: "reopened until the canaries pass, see RELEASING step 4"), so step 11 closes them by hand with
   their texts as for any release. (3.7.1 ships with eight such commit messages, written before this rule.) Then mark the draft
   ready (`gh pr ready N`), then merge the release branch into `main` (merge commit). Write the merge sha into the
   release record at once; every later compare and the tag use that value, and a final PR comment names it (the
   earlier review comments name heads that the rebases left behind). A CI run that went red on a network error
   (GitHub or the CDN unreachable) is re-run, not fixed.
5. Wait for GitHub's raw cache (up to 5 minutes), then check that
   `https://raw.githubusercontent.com/WonderCMS/wondercms/main/version` shows the new version and that the
   SHA-256 of the served `main/index.php` equals `git show <merge-sha>:index.php` (the 3.6.0 updater writes
   whatever it receives). Check the same two things on the legacy URL
   `https://raw.githubusercontent.com/robiso/wondercms/master/` (the repository was renamed and moved; GitHub
   serves the old owner's raw URL transparently with a 200, not a redirect; sites on 3.0 to 3.3 still update
   from there). The release record's `rawcheck.sh <merge-sha>` (kept with the record, not in this repository)
   loops both URLs until both match. Then run its `livecopy.sh <merge-sha> main` and `livecopy-robertsi.sh <merge-sha>
   main` (one per canary site: a copy of that site's data, themes and plugins in a fenced folder, running its own core,
   which clicks Update against the raw URL of `main` and is then checked on the public and the admin side), write the compare results into the release record at once, and run the canary (step 6) as soon
   as the first comparison matches on both URLs; compare once more five minutes after that, and again 30 minutes
   and 2 hours after the merge (GitHub's raw cache is per edge; a stale edge serves the old file long after the
   first one matched). The later comparisons confirm the cache stayed right, they do not delay the canary. Check https://www.githubstatus.com
   before the news post: a GitHub incident during the first hours means sites download nothing, not a bad file.
6. Canary: two sites, each clicked by the maintainer himself while logged in as that site's admin (the agent
   prepares, watches and verifies; it never holds the admin passwords), one after the other: www.wondercms.com first, then robert.si
   (a custom theme, `skyPersonal`, so a theme that is not shipped with the release meets the new core too). Start
   robert.si only once wondercms.com has passed. On each site: confirm the live `index.php` is the stock previous
   release (compare its SHA-256; if it is not, stop and do not click: find out why the file differs first, since a
   hand-edited or unexpected core is not the canary this step relies on), back up `index.php` and `data/` before its click
   (commands below), click **Download backup** (in the update notice, above Update), then **Update**, check the site, the login, a save,
   and the `VERSION` in the file on disk. A save means two real saves: open the home page editor, add one character, click
   outside (the page saves and reloads), then remove that character and click outside again; the content ends as it
   was and `data/database.js`'s mtime moved (add-then-remove in one go sends nothing: the stock editor saves only
   when the content differs). The backup click leaves a zip in `data/files/` and a "Backup files detected" alert:
   delete that zip from the Files tab once the site has passed. After each
   Update click wait at least 2 seconds (opcache's `revalidate_freq` default) before the first check: within that window a
   request can still run the old code and report the old version. "Check the site" means reading the page body, not
   the status code: a boot fatal comes back as HTTP 200 with the error in the body on some servers, so `curl -s -w '%{http_code}'` the
   home page (expect 200) and the login page (expect 404: the login URL is not a stored page, by design, in every
   version) and grep both bodies for `Fatal error`, `Parse error`, `Warning:`, `Deprecated:` and `Notice:` before the
   login (with display_errors off a fatal is a blank 500, which the status code catches). On robert.si also check
   that the home page body references `themes/skyPersonal/` (the custom theme rendered, not the Sky fallback), that
   no "Showing the Sky theme instead" alert appears after the login, and that the server error log has no
   `failed to load, showing Sky` line. Wait a few seconds between clicks: a second
   Update within opcache's revalidation window still runs the old updater. While on wondercms.com, check that
   `/data/database.js` and `/data/cache.json` answer 403 (not 200) from the public side; a site that predates the
   shipped `.htaccess` carries its own and must add the four deny lines the release notes tell every existing site
   to add, the maintainer's own site first. Hold the news post and any announcement
   until both canary sites have passed; an admin who is not logged in sees no update notice (a logged-in admin sees the
   alert within a day), so publicity is what drives the fleet's updates.
   **If the canary fails:** restore the backed-up `index.php` and `data/` on the site that failed, and fix forward.
   If wondercms.com fails, robert.si is not updated. If wondercms.com passes and robert.si fails, wondercms.com
   stays on the new core, robert.si is restored, and a patch release follows (as below). `main` cannot be
   reverted (CI requires a higher version for any change to `index.php`, and protection applies to admins):
   the remedy is the next patch release (`X.Y.Z+1`) carrying the fix, or, in the worst case, the previous
   release's code with `VERSION` set to `X.Y.Z+1` (sites already on `X.Y.Z` only accept a higher version). Nothing is announced until that patch is live and verified.

   **Backup, error log and restore per site.** The backup goes to a dated folder outside the web root; write its name
   into the release record at once. The restore keeps the failed state, puts `index.php` and `data/database.js` back
   atomically (a temp name, then a rename) and copies the rest of `data/` back over the live folder, so `data/` is never
   missing and no request can start a fresh install. Files created in `data/` after the backup (an upload, say) are
   kept: the restore overwrites what the backup holds and removes only leftover `*.tmp` copies.
   www.wondercms.com (ssh host `wondercms`, web root `~/public_html`; the host writes PHP errors to `error_log` in the
   script's folder):

   ```sh
   # before the click: back up, and note the error log length
   ssh wondercms 'B=~/backups/canary-$(date +%Y%m%d-%H%M%S) && mkdir -p "$B" && cp -p ~/public_html/index.php "$B/index.php" && cp -Rp ~/public_html/data "$B/data" && echo "backup: $B" && shasum -a 256 ~/public_html/index.php "$B/index.php" && du -sh "$B/data"; wc -l ~/public_html/error_log 2>/dev/null'
   # after the click and the checks: what the update added to the error log (expect nothing new)
   ssh wondercms 'tail -n 20 ~/public_html/error_log 2>/dev/null'
   # restore, only if the canary failed
   ssh wondercms 'B=<backup-folder> && cp -p ~/public_html/index.php "$B/index.php.failed" && cp -Rp ~/public_html/data "$B/data.failed" && cp -p "$B/index.php" ~/public_html/index.php.restore && mv ~/public_html/index.php.restore ~/public_html/index.php && find "$B/data" -mindepth 1 -maxdepth 1 ! -name database.js -exec cp -Rp {} ~/public_html/data/ \; && cp -p "$B/data/database.js" ~/public_html/data/database.js.restore && mv ~/public_html/data/database.js.restore ~/public_html/data/database.js && rm -f ~/public_html/data/*.tmp && shasum -a 256 ~/public_html/index.php "$B/index.php" ~/public_html/data/database.js "$B/data/database.js"'
   ```

   robert.si (the second canary site; its ssh host, port, user, key and web root are NOT in this public file: they
   are in the maintainer's private release folder, `release-370/canary-sites.md`, which also holds the backup folder
   made before the release): the same three commands with the values from that file, as `<robert-si-ssh>` (the full
   ssh command), `<robert-si-root>` (the web root) and `<robert-si-log>` (its error log):

   ```sh
   <robert-si-ssh> 'B=~/backups/canary-$(date +%Y%m%d-%H%M%S) && mkdir -p "$B" && cp -p <robert-si-root>/index.php "$B/index.php" && cp -Rp <robert-si-root>/data "$B/data" && echo "backup: $B" && shasum -a 256 "$B/index.php" "$B/data/database.js" && wc -l < <robert-si-log>'
   <robert-si-ssh> 'tail -n 20 <robert-si-log> 2>/dev/null'
   <robert-si-ssh> 'B=<backup-folder> && cp -p <robert-si-root>/index.php "$B/index.php.failed" && cp -Rp <robert-si-root>/data "$B/data.failed" && cp -p "$B/index.php" <robert-si-root>/index.php.restore && mv <robert-si-root>/index.php.restore <robert-si-root>/index.php && find "$B/data" -mindepth 1 -maxdepth 1 ! -name database.js -exec cp -Rp {} <robert-si-root>/data/ \; && cp -p "$B/data/database.js" <robert-si-root>/data/database.js.restore && mv <robert-si-root>/data/database.js.restore <robert-si-root>/data/database.js && rm -f <robert-si-root>/index.php.*.tmp <robert-si-root>/data/*.tmp && shasum -a 256 <robert-si-root>/index.php <robert-si-root>/data/database.js "$B/index.php" "$B/data/database.js"'
   ```

   On a cPanel or LiteSpeed host the error log is `error_log` in the web root, as on wondercms.com; otherwise read
   `error_log` from `php -i` on that host or its control panel, and make sure the `failed to load, showing Sky` check
   above reads that file.
7. Tag the merge commit on `main`, not the branch head. First `git fetch --prune origin`, then `git tag -l X.Y.Z`
   must print nothing (the tree is clean, see step 4; `git archive` builds from the tag, never from the tree) and `git tag -l | wc -l` must equal `git ls-remote --tags origin | grep -vc '\^{}'` (a local tag left by a test build or
   a reviewer would be tagged or pushed by mistake; delete it). Then `git tag X.Y.Z <merge-sha>
   && git push origin X.Y.Z`: one tag, by name, never `git push --tags`. Nothing else goes into `main` between
   the merge and the tag.
8. Build the ZIP from the tag: `./build-release.sh X.Y.Z`. Its member list is exactly the 3.6.0 one, 12 entries (5 folders, 7
   files): `wondercms/`, `index.php`, `.htaccess`, `themes/`, `themes/sky/`, `theme.php`, `wcms-modules.json`, `css/`,
   `style.css`, `css/fonts/` and the two `catamaran` woff2 files; `unzip -l` must show nothing else. Then create the GitHub release with
   notes (Features and fixes / Changes / Backwards compatibility / How to update / Installation
   with the SHA-256 of the uploaded asset) and attach the ZIP. The login throttle's `data/logs/login-attempts.json` holds only salted hashes of addresses and times, nothing that identifies a visitor, so the notes ask for no `.htaccess` edit on sites whose `.htaccess` predates 3.7.0. Then download the asset from the release page and
   compare its SHA-256 with the local file; the notes carry the downloaded file's hash, not the local one (a ZIP
   built on another machine or in another timezone has other member timestamps and another hash).
9. wondercms.com: copy the ZIP next to the previous ones, point `download-latest.php` at the new
   asset, update the latest/download/news/contributors pages and the docs. The demo is rebuilt only on its own
   host with its own document root (`demo.wondercms.com`, created 2026-10-29 with a placeholder page), never under
   the live site's origin, which it would share with the admin's and the forum's cookies; the old demo folder under
   the live site stays as it is until the new host serves the build, then redirects to it. On the shared server a new
   host is created in this order: the cPanel subdomain with its own document
   root and a placeholder page, a check that the origin answers for that name (`curl --resolve`), then the DNS record,
   then the certificate (AutoSSL validates over HTTP, so it needs the name to resolve first); a DNS record before the
   vhost served another customer's default vhost under the demo name for four minutes on 2026-10-29. Delete any
   placeholder `index.html` before the built `index.php` goes in, and purge the demo host's Cloudflare cache after the
   build, then refresh every theme and plugin on the demo from the official list zips; compare version files to the
   list, zero differences. The site is
   behind Cloudflare: purge its cache after the page edits (Caching, Purge Everything), and remember
   `download-latest.php` is browser-cached for 48 hours, so check it from a private window.
10. Theme or plugin changes shipped with the release (for example `themes/sky`) are pushed to
    their own repositories with a version bump so existing sites receive them; confirm the CDN
    repo's daily `wcms-modules.json` workflow is enabled and has run.
11. Close the fixed issues with the commit links; fast-forward `develop` to `main`.
12. Every remote write (a push, a workflow dispatch, a comment) is read back through the GitHub API before it is
    recorded as done; a pipe after `git push` hides a rejected push. While a review panel is reading a snapshot,
    no remote action is taken.

## After the checklist

The steps above end with the issues closed. What follows a release lives in the maintainer's private release
record (`release-370/` for 3.7.0: `STATUS.md`, its "Exact remaining sequence" and "Day-after wave"), not here:
the Docker image built from the tag (`docker-post-tag.md`), the 24-hour error-log watch on both canary sites and
the 48-hour watch on the forum and the issues, the day-after compare of each canary site's `data/database.js`
mode, owner and inode against its pre-release backup, the announcements, the ecosystem follow-ups, and the
cleanup of the rehearsal folders, keys and local branches. A release is closed when that record says so.
