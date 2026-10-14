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
- [x] T2 — Self-update core (release client, checksum, phar validation, atomic swap, backup) + CLI `self-update`. TDD with fake HTTP client. Route: delegated. Commit `ade84db`.
- [x] T3 — API + UI Updates view + README section. Route: delegated. Commit `0e9e603`.

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
- T2 (delegated writer): `src/Updates` (Domain `SemVer`/`Release`; Application `CheckForUpdate`, `ApplyUpdate`, ports `ReleaseSource`/`PharInstaller`; Infrastructure `GitHubReleaseSource`, `FilesystemPharInstaller`, CLI `self-update`). `HttpClient` port gained `get()` (no redirect following, byte cap).
  - RED: `vendor/bin/phpunit tests/Updates` → aborted with `Interface "Deployer\Updates\Application\Port\ReleaseSource" not found` (SemVer, GitHub source, ApplyUpdate, installer and CLI tests written first). GREEN: full suite 218 tests, 748 assertions OK. The 3 ConfigLoader update-settings tests were added after the implementation (no RED observed for them).
  - Integration (`SelfUpdateCommandTest`, `FilesystemPharInstallerTest`): tiny real phars built in a subprocess prove swap + `.bak` + kept permissions, checksum mismatch and tampered/foreign phars leave the file untouched.
  - Real-build simulation: `DEPLOYER_VERSION=0.1.0` and `0.2.0` builds; fake GitHub API on `php -S 127.0.0.1:18765` (302 redirect for the phar asset) via `DEPLOYER_UPDATE_API`. `self-update --check` → exit 2 "Update available: 0.1.0 -> 0.2.0"; `self-update` → exit 0, `about` now `Deployer 0.2.0`, `index.php.bak` reports 0.1.0, mode 0640 kept; rerun → "already up to date" exit 0. With a zeroed `.sha256`: exit 1 "Checksum mismatch ... not touched", sha256 of `index.php` unchanged, no `.bak`/temp file. From source: exit 1 "only works for the packaged phar".
  - Migrations on next boot: verified, `Application::pdo()` runs `Migrator::migrate()` on the first DB use of every request/command; nothing added.
  - Size: ~1000 authored source lines + ~860 test lines, above the 400-line advisory heuristic because the adapter, installer and CLI only make sense together with their security checks.
- T3 (delegated writer): `UpdatesController` (`GET /api/updates`, `POST /api/updates/apply`), `useUpdates` composable + `UpdatePanel` + `UpdatesView`, sidebar entry, README (Quick path download, Updates, config keys, Releasing).
  - RED: `vendor/bin/phpunit tests/Api/UpdatesApiTest.php` → 6 failures (no route); `npx vitest run` → `useUpdates.test.ts` failed to import. GREEN: PHPUnit 226 tests, 787 assertions OK; vitest 26 passed; `vue-tsc --noEmit` OK; `npm run build` OK; php lint 0 errors.
  - Web simulation (0.1.0 phar served by `php -S` as `index.php`, fake API at 127.0.0.1): `/api/me` → `app.version` 0.1.0; `GET /api/updates` → available 0.2.0, `can_apply` true; `POST /api/updates/apply` without token → 403 `csrf_mismatch`; with token → `{"updated":true,"from":"0.1.0","to":"0.2.0","backup_file":"index.php.bak"}`; next `/api/me` reports 0.2.0; `/updates` serves the SPA (200).
  - Not exercised: a real browser session of the Updates view, and Apache + PHP-FPM with OPcache `validate_timestamps=0`.

## Decisions
- `/api/health` is public, so it no longer returns the version; the version moved to the authenticated `/api/me` (`app.version`).
- Actions pinned to exact tags: `actions/checkout@v7.0.1`, `actions/setup-node@v7.1.0`, `shivammathur/setup-php@2.40.0` (latest releases at implementation time). Release upload uses the preinstalled `gh` CLI, no third-party release action.
- GitHub renames release assets whose name starts with a dot, so the rewrite rules ship as `deployer.htaccess` (rename to `.htaccess` on the server).
- Tags with a prerelease suffix (`v1.2.0-rc.1`) create a GitHub prerelease.
- Download hosts: only `https://api.github.com`, `github.com`, `objects.githubusercontent.com`, `release-assets.githubusercontent.com`; redirects are followed manually (max 5) so every hop is checked. Assets are fetched through their API URL with `Accept: application/octet-stream` (works for private repos); the token is only sent to the API origin and redacted from errors.
- Test-only override: `DEPLOYER_UPDATE_API` (environment only, never the config file) accepts only a loopback origin (`http(s)://127.0.0.1|localhost|[::1]`), which then becomes the only allowed host; any other non-GitHub value is rejected. Production therefore stays https + GitHub-only.
- Config keys: `update_repository` (default `yebt/php-cpnl-webhook-deployer`), `update_token`, `update_prereleases` (bool, default false); env `DEPLOYER_UPDATE_REPOSITORY` / `DEPLOYER_UPDATE_TOKEN` override.
- Version rules: never downgrade, even with `--force`; same version is a no-op unless `--force` (reinstall); `dev` builds see any release as available but apply only with `--force`. With prereleases enabled the highest non-draft semver release wins.
- Validation: the download is written as `.deployer-update-<rand>.phar` in the target directory and opened with `new Phar()` (manifest + signature check, no code executed); it must be signed, contain `public/index.php`, and its `VERSION` must equal the release version. Size caps: 64 MiB phar, 4 KiB checksum, 2 MiB API JSON.
- OPcache: `opcache_invalidate()` on the file and, when `opcache_get_status()` is allowed, on every cached `phar://<file>/...` script.
- CLI exit codes: 0 updated/up to date, 1 refused/failed, 2 `--check` found an update.
- No stream-wrapper fallback for HTTP: ext-curl stays required (as for Telegram).
- The API never forces: `POST /api/updates/apply` ignores any `force` flag, so dev builds (409 `dev_build_requires_force`) and source checkouts (409 `not_running_from_phar`) are CLI-only. Refusals map to 409 with the refusal code, GitHub/transport errors to 502 (`update_check_failed` / `update_failed`). The apply response returns only the backup file name, not its absolute path.
- Update use cases are built lazily in the router, so a bad update setting breaks only the two update routes.
- Rollback documentation states that migrations are not reverted.

## Next step
Parent: review, fast-forward `main`, push, then tag `v0.1.0` (or the chosen first version) to produce the first release.
