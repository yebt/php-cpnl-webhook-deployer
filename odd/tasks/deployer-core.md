# Feature: deployer-core

## Objective
A self-hosted deployment tool, shipped as ONE file (`deployer.phar` renamed to `index.php`) uploaded to a cPanel subdomain. It receives authenticated webhooks per project, queues runs per project, executes YAML-defined steps, streams logs live, allows cancellation, keeps history and notifies Telegram on completion.

## Problem / Why
cPanel hosting (Apache + PHP-FPM, possibly CloudLinux limits) does not reliably allow always-on daemons, so the TypePHP/Swoole approach (see `odd/tasks/typephp-spike.md`) is replaced by a daemon-less model. The user's hard constraint is a single uploaded artifact.

## Architecture (decided)
- Runtime: host PHP >= 8.2 with `proc_open`, `pdo_sqlite`, `phar`. No Swoole, no daemon. The static-php-cli binary stays as a future packaging option for non-cPanel hosts.
- One artifact: `deployer.phar` (web front controller + CLI runner in the same file). Apache serves it as `index.php`; the runner runs as `php index.php <command>`.
- Storage: SQLite single file in a data dir outside the web root by default (configurable at bootstrap), plus per-run log storage.
- Queue: webhook/manual trigger inserts a `queued` run, then spawns a detached runner (`setsid`/`nohup` via `proc_open`) for that project. The runner takes a per-project `flock` and drains that project's queue, then exits. Different projects run in parallel; same project runs sequentially. Optional cron `runner:sweep` recovers orphaned queues.
- Cancel: cooperative (`cancel_requested` flag polled by the runner, which calls `Process::stop()` on the whole process group) with PID-signal fallback. Runner records `cancelled` itself (SIGKILL yields `exitCode=-1`, see spike).
- Live logs: runner appends timestamped lines tagged stdout/stderr/system per step; UI polls incrementally by offset (1–2s).
- Pipeline YAML: stored as a file on disk (default `<project folder>/deployer.yml`, configurable), editable both from the filesystem and from the app. Read at run start and snapshotted into the run.
- Webhook: `POST /hooks/{project}` with header `X-Deployer-Token`; tokens stored hashed (sha256), shown once, regenerable; constant-time comparison.
- Auth: first-run bootstrap creates the single admin user (password_hash); session cookie (HttpOnly, Secure, SameSite=Lax) + CSRF for state-changing API calls.
- Telegram notifiers: bot token + chat id; message on run finish (success/failed/cancelled) with project, duration, failed step.
- Frontend: Vue 3 + Vite SPA (Kestrel-like dark UI from the reference image), built assets embedded in the phar and served by the front controller.
- Code layout: screaming + hexagonal (`src/<Feature>/{Domain,Application,Infrastructure}`), Symfony components only (process, yaml, var-dumper as dev).

## Pipeline YAML format (v1)
```yaml
vars:
  APP_DIR: /home/user/apps/shop
  BRANCH: main
steps:
  - name: Fetch
    run: git -C ${APP_DIR} fetch origin ${BRANCH}
  - name: Install
    run: composer install --no-dev --working-dir=${APP_DIR}
    timeout: 600          # seconds, optional
    continue_on_error: false
```
Vars may reference earlier vars. Unknown `${X}` is a validation error. Built-in vars: `PROJECT`, `RUN_ID`, `PROJECT_DIR`.

## Scope
Bootstrap/login, notifiers CRUD, projects CRUD (folder, notifier, instructions file, webhook token), YAML editor, webhook trigger + manual trigger, per-project queue, cancel, live log view, run history, Telegram notification, phar build, cPanel install docs.

## Out of scope (v1)
Multiple users/roles, branch filters on webhook payloads, artifact retention policies beyond simple log pruning, the static binary packaging.

## Constraints
- Generated artifacts in English. Conventional commits, no AI attribution.
- Must work under Apache + PHP-FPM without a daemon; must not rely on Swoole/pcntl in the web SAPI.
- Local dev PHP is 8.5 NTS (php.new); keep code compatible with PHP 8.2.

## Delivery strategy
ask-on-risk. Forecast: ~3500 authored changed lines (exceeds 400). Chain strategy (user choice): **stacked-to-main** — one PR per task slice merged to `main` in order. Slice boundaries (commits per PR) are recorded under Progress as tasks close.

## Tasks
- [x] T1 — Skeleton: composer, PHPUnit, layout, front controller (web vs CLI), config/data dir, SQLite migrations, phar build script. Route: delegated.
  - Decisions:
    - Run logs live in the SQLite `run_logs` table (one row per line: run_id, step_position, stream, line, logged_at) instead of per-run files: one storage file to back up, transactional writes, and the autoincrement `id` is a natural cursor for incremental reads (`WHERE run_id = ? AND id > ?`). WAL mode + `busy_timeout` let the web front controller read while detached runners write. Revisit (files) only if log volume becomes a problem; pruning is a simple DELETE.
    - Config precedence: env (`DEPLOYER_DATA_DIR`, `DEPLOYER_PHP_CLI`) > `deployer.config.php` next to the artifact > defaults. The config file is PHP returning an array so Apache executes it rather than serving it if it sits in the web root.
    - Default data dir: phar → `<artifact dir>/../deployer-data` (outside the web root); source → `<repo>/data` (gitignored). A `Require all denied` `.htaccess` is written into the data dir as defense in depth.
    - Default PHP CLI: `PHP_BINARY` only under the `cli` SAPI, otherwise `php` from PATH (under FPM `PHP_BINARY` is php-fpm).
    - Migrations are PHP arrays (`Migrations::all()`), applied automatically on first DB connection inside `BEGIN IMMEDIATE`; `migrate` command also exists.
    - Phar stub has no shebang (it is served by FPM as index.php); CLI usage is `php deployer.phar <command>`. Phar is not gz-compressed to avoid a runtime dependency on ext-zlib (~330 KiB). Production deps are installed with `--no-dev` in `build/.staging` so dev packages never reach the artifact.
    - `.gitignore`: the previous blanket `build/` rule was narrowed to spike build dirs so `build/build-phar.php` is tracked.
- [x] T2 — Pipeline: YAML parsing, vars interpolation (incl. chained vars, built-ins), validation errors. TDD. Route: delegated.
  - Decisions:
    - Domain `PipelineFactory` validates decoded data (no I/O); `YamlPipelineParser` (Infrastructure) only decodes YAML via symfony/yaml and implements the `PipelineParser` port.
    - Default step timeout: 3600 s (`Pipeline::DEFAULT_TIMEOUT`) so a hung step cannot block a project queue forever. `timeout` must be a YAML integer > 0 (quoted strings and floats are rejected).
    - Unknown top-level and step keys are errors (catches typos such as `timout`). Built-in names cannot be redefined in `vars`.
    - Var values may be string, number or boolean (booleans become `true`/`false`).
    - `$${NAME}` escapes to a literal `${NAME}` so shell brace syntax stays usable; `$NAME` without braces is passed through untouched.
    - All errors are collected and reported together, each prefixed with its location (`vars.A`, `steps[1] (Install)`).
- [x] T3 — Run engine: queue model, detached runner spawn, per-project flock, step execution with symfony/process (process group), log persistence, timeouts, cooperative cancel, final status. Tests with real processes. Route: delegated.
  - Decisions:
    - Steps run as `setsid bash -c '<close fds>; exec bash -c "$1"' deployer-step <command>`: the step bash is the leader of a new session/process group, so cancel/timeout signal `-pgid` (SIGTERM, `kill_grace_seconds` grace, SIGKILL) and kill grandchildren. Signals are sent and polled (not the blocking `Process::stop()`), and the runner records `cancelled`/`failed` itself; exit codes are never used to infer cancellation.
    - Inherited descriptors above 2 are closed in both the step wrapper and the runner launcher. Found by test: PHP's `fopen()` handles lack close-on-exec, so a step would inherit the project flock (a background daemon started by a step would block the project forever) and, under FPM, the launcher could inherit the client connection.
    - Child processes get a default `PATH` and `HOME` when missing (PHP-FPM `clear_env`), since git/composer need them.
    - Steps run with the project folder as working directory; a missing folder fails the run before any step.
    - A failed step with `continue_on_error: true` is recorded as `failed` but the run can still end `success`.
    - On normal step completion, background processes the step left running are not killed (allows intentional `nohup` workers); only cancel/timeout kill the whole group.
    - Cancelling a queued run marks it `cancelled` immediately; cancelling a running run sets `cancel_requested` (polled every 0.5 s and between steps).
    - Runner drain loop re-checks the queue after releasing the lock, so a run enqueued while the previous runner was finishing is never orphaned. `runner:sweep` only fails a `running` run when its PID is dead AND its project lock can be taken (never fails a run that might still execute); it launches runners for projects with queued runs and a free lock.
    - Extra CLI commands `run:trigger <project>` and `run:cancel <run>` (operational/manual use; HTTP equivalents come in T5).
    - Without a `setsid` binary the executor degrades to signalling only the step leader (cPanel/Linux ships util-linux, so this is a fallback only).
- [ ] T4 — Auth: bootstrap first-run, login/logout, sessions, CSRF. Route: delegated.
- [ ] T5 — HTTP API: notifiers, projects, instructions file read/write, webhook with token, manual trigger, runs list/detail, incremental logs, cancel. Route: delegated.
- [ ] T6 — Telegram notifier on run finish. Route: delegated.
- [ ] T7 — Frontend Vue SPA: setup/login, projects, notifiers, YAML editor, run live view (steps + log), history. Route: delegated.
- [ ] T8 — Packaging + docs: phar with embedded assets, cPanel install guide (subdomain, PHP CLI path, cron sweep), end-to-end smoke test. Route: delegated.

## Acceptance criteria
- Two webhooks to project A run sequentially; a webhook to project B runs in parallel with A.
- A running run can be cancelled; its process tree dies; status `cancelled`.
- Live view shows each step, the exact command executed (after interpolation) and stdout/stderr lines; logs persist after completion.
- Invalid/missing token → 401 and no run created.
- Telegram message sent on finish.
- Single uploaded file works under Apache + PHP-FPM.

## Checks
PHPUnit (unit + integration with real processes and SQLite), PHP lint, frontend build, phar smoke test.

## Progress / Evidence
- T1 (delegated writer; trigger: 2+ non-trivial files). Tests were written alongside the skeleton (no meaningful RED for scaffolding). Checks: `vendor/bin/phpunit` → OK (10 tests, 35 assertions); `php -l` over src/tests/bin/public/build → 0 errors; `php -d phar.readonly=0 build/build-phar.php && php dist/deployer.phar about` → built 332 KiB phar, `about` prints version, data dir, schema 1; manual web check: phar copied as `index.php` under `php -S` → `GET /api/health` 200 JSON, unknown path 404. Commit: `fad54b6`.
- T2 (delegated writer). RED observed: `vendor/bin/phpunit tests/Pipeline` → 29 errors (classes missing) before implementation; GREEN: 29/29 pass. A follow-up case (reference to an earlier invalid var reported as "declared after") was found in self-review, fixed and covered by a test. Checks: `vendor/bin/phpunit` → OK (40 tests, 75 assertions); `php -l` → 0 errors. Commit: `93db89d`.
- T3 (delegated writer). TDD exception, reported honestly: implementation was written before its tests, so no RED-first evidence exists for T3. Regression detection was verified by mutation instead: removing the fd-closing wrapper makes `testStepsDoNotInheritTheProjectLockDescriptor` fail (FAILURES 1/1), restoring it passes. Checks: `vendor/bin/phpunit` → OK (66 tests, 196 assertions), run 3 times with no flakes (~12 s); integration tests with real detached runners prove same-project sequential, different-project parallel (overlap, < 3.8 s for two 2 s runs), cancel kills the `sleep` grandchild and records `cancelled`, timeout kills the tree, SIGTERM-ignoring tree killed after grace, sweep relaunches an orphaned queue, logs persisted with stream tags and the exact interpolated command. `php -l` → 0 errors. Phar smoke: `run:trigger demo` through `dist/deployer.phar` executed via a detached phar runner → `success` with logs. Commit: 9edcda4.

## Next step
T4.
