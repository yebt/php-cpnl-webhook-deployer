# Feature: release-self-update

## Objective
Publish the deployer artifact through GitHub Releases and let an installed deployer download the latest release build and replace itself.

## Problem / Why
The user uploads the artifact to cPanel by hand. Building it in CI on release removes local build steps, and self-update removes the manual re-upload for every new version.

## Scope
- GitHub Actions workflow: on a published release / pushed `v*` tag, build the frontend + phar with the version stamped from the tag, and attach `deployer.phar`, `deployer.phar.sha256` and `.htaccess` to the release. Also a CI workflow (tests on push/PR).
- Version stamping: the build embeds the version (tag) in the phar; `about` and `/api/me` (or a dedicated endpoint) expose it.
- Self-update:
  - Check: query `https://api.github.com/repos/<owner>/<repo>/releases/latest` (repo configurable, default `yebt/php-cpnl-webhook-deployer`; optional token for private repos), compare semver with the running version.
  - Apply: download the `deployer.phar` asset and its `.sha256`, verify the checksum, verify the file opens as a valid phar, write to a temp file in the same directory, keep a backup of the current file (`<name>.bak`), atomic `rename` over the running artifact (`Phar::running(false)`), run migrations on next boot.
  - Refuse when not running from a phar (dev mode), when the target is not writable, or when the checksum fails. Never downgrade.
  - UI: admin-only "Updates" view (current version, latest version, release notes link, Update button, result). API endpoints protected by auth + CSRF.
  - CLI: `self-update` (and `self-update --check`) so the user can automate it with cron.
  - Running deployments are unaffected by the file swap (Linux keeps the open inode); updating while runs are active is allowed but warned in the UI.

## Out of scope
Code signing with keys, rollback UI (the `.bak` file is the manual rollback), automatic update without a user-configured cron.

## Constraints
- Generated artifacts in English. Conventional commits, no AI attribution.
- PHP 8.2+, works under Apache + PHP-FPM; HTTP via ext-curl with stream fallback if feasible.
- Pin GitHub Actions to exact versions.

## Delivery strategy
User asked to organize everything on `main`: work happens on `feat/release-self-update` and is fast-forwarded into `main` locally. Push is done by the user (SSH key not available in this session).

## Tasks
- [x] T1 — Version stamping + GitHub Actions (CI tests + release build/upload). Route: delegated. Commit `e863797`.
- [ ] T2 — Self-update core (release client, checksum, phar validation, atomic swap, backup) + CLI `self-update`. TDD with fake HTTP client. Route: delegated.
- [ ] T3 — API + UI Updates view + README section. Route: delegated.

## Acceptance criteria
- Pushing tag `vX.Y.Z` produces a release with the three assets and the phar reports version `X.Y.Z`.
- An installed older phar detects the newer release and replaces itself; checksum mismatch aborts without touching the file.

## Checks
PHPUnit, vitest, vue-tsc, php lint, actionlint if available, local self-update simulation against a fake release server.

## Progress / Evidence
- `main` now points to the former `feat/deployer-core` head (`7c3e403`), including the TypePHP spike history.

- T1 (delegated writer): `AppVersion` reads `VERSION` at the artifact root (phar root when packaged; missing/invalid → `dev`). `build/build-phar.php` stamps it from `--version=`, `DEPLOYER_VERSION`, the exact `vX.Y.Z` tag of HEAD, else `dev`; non-semver aborts the build. `about` and `GET /api/me` (`app.version`) expose it.
  - RED: `vendor/bin/phpunit` → 8 errors + 1 failure (missing `AppVersion`, `version:` ctor arg, `app.version`). GREEN: 159 tests, 604 assertions OK.
  - `DEPLOYER_VERSION=0.1.0 build/build.sh --skip-frontend` → `php deployer.phar about` prints `Deployer 0.1.0`; `--version=v1.x` aborts with "Invalid version".
  - actionlint 1.7.12 (downloaded to the session scratch dir, sha256 verified) on both workflows → exit 0, no findings (shellcheck not installed, so shell snippets were not linted).

## Decisions
- `/api/health` is public, so it no longer returns the version; the version moved to the authenticated `/api/me` (`app.version`).
- Actions pinned to exact tags: `actions/checkout@v7.0.1`, `actions/setup-node@v7.1.0`, `shivammathur/setup-php@2.40.0` (latest releases at implementation time). Release upload uses the preinstalled `gh` CLI, no third-party release action.
- GitHub renames release assets whose name starts with a dot, so the rewrite rules ship as `deployer.htaccess` (rename to `.htaccess` on the server).
- Tags with a prerelease suffix (`v1.2.0-rc.1`) create a GitHub prerelease.

## Next step
T2.
