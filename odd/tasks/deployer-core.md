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
- [x] T4 — Auth: bootstrap first-run, login/logout, sessions, CSRF. Route: delegated.
  - Decisions:
    - Server-side sessions in SQLite (`sessions` table, migration 2) instead of PHP native sessions: deterministic in-process tests, no dependence on the host's `session.save_path`, and revocation of all sessions on password reset. The cookie `deployer_session` carries a random 256-bit token; only its sha256 is stored. Cookie: `Path=/; Max-Age=7d; HttpOnly; SameSite=Lax`, plus `Secure` on HTTPS or when `X-Forwarded-Proto: https` (that header only adds the flag, so spoofing it cannot weaken anything).
    - Session lifetime: 12 h idle, 7 days absolute; `last_seen_at` refreshed at most once per minute so 1-2 s log polling does not write on every request. Login always issues a new token and destroys the one presented (session fixation defense).
    - CSRF: session-bound random token, returned by `POST /api/setup`, `POST /api/login` and `GET /api/me`; required (`hash_equals`) in `X-CSRF-Token` on every non-GET/HEAD/OPTIONS `/api` call that needs auth (`/api/logout` included). Setup/login are exempt (no session yet; SameSite=Lax limits login CSRF).
    - Public `/api` paths: `/api/health`, `/api/setup/status`, `/api/setup`, `/api/login`. The guard runs before routing, so unknown `/api/*` paths answer 401 (not 404) without a session: no route enumeration. Non-`/api` paths (webhooks, SPA) are not guarded by the session.
    - `POST /api/setup` logs the new admin in (201 + cookie). Creation is a single `INSERT ... WHERE NOT EXISTS` so concurrent setups cannot create two users; afterwards 409 `already_set_up`. Known risk (inherent to first-run bootstrap): whoever reaches a fresh install first claims it; the operator can recover with `user:password`.
    - Credentials: username `[A-Za-z0-9._@-]{1,64}`, password 10-1024 chars, `password_hash(PASSWORD_DEFAULT)` with transparent rehash on login. Unknown usernames verify against a dummy bcrypt hash so both failure paths cost the same and return the same 401 body.
    - Throttling: per client IP (`REMOTE_ADDR` only; `X-Forwarded-For` is spoofable and ignored), 5 failures in 15 min → 429 `too_many_attempts` with `Retry-After`, even for the correct password. Success clears the IP's failures; rows older than 1 day are pruned on each failure. No global lockout (it would let anyone lock the admin out).
    - Uniform JSON error shape for all API errors: `{"error": {"code", "message", "details"?}}` (`ApiError`), including 404/405/413/500. Use cases throw `Shared\Domain\ValidationFailed` (field => message), rendered as 422 `validation_failed`. Every response gets `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.
    - Body limits: the web SAPI never buffers more than 1 MiB (`Content-Length` checked, then a capped read) → 413; JSON bodies default to 64 KiB, credentials to 8 KiB.
    - CLI `user:password [username]` reads the password twice from STDIN (echo disabled on a TTY; never argv, so it does not leak to the process list), sets it, revokes every session, and creates the admin if none exists (shell access already implies operator trust).
- [x] T5 — HTTP API: notifiers, projects, instructions file read/write, webhook with token, manual trigger, runs list/detail, incremental logs, cancel. Route: delegated.
  - Decisions:
    - Endpoints: `GET/POST /api/notifiers`, `GET/PUT/DELETE /api/notifiers/{id}`; `GET/POST /api/projects`, `GET/PUT/DELETE /api/projects/{id}`; `GET/PUT /api/projects/{id}/instructions`; `POST /api/projects/{id}/token`; `POST /api/projects/{id}/runs`; `GET /api/runs?project=&status=&limit=&before=`; `GET /api/runs/{id}`; `GET /api/runs/{id}/logs?after=&limit=`; `POST /api/runs/{id}/cancel`; `POST /hooks/{slug}`. PUT is a partial update (missing fields keep their value). Non-numeric/unknown ids → 404.
    - Notifier bot tokens are never returned: responses carry `bot_token_masked` (`****` + last 4 chars). On update an empty/missing `bot_token` keeps the stored one. Deleting a notifier detaches its projects (explicit UPDATE plus the FK's `ON DELETE SET NULL`).
    - Projects: `folder` must be absolute (need not exist yet; runs fail cleanly if missing). `slug` defaults from the name, `^[a-z0-9][a-z0-9-]{0,62}$`, duplicate → 409 `slug_taken`. Deleting a project with queued/running runs → 409 `project_busy` (cancel first); otherwise runs/logs cascade.
    - Instructions path safety: an absolute `instructions_file` is an explicit operator choice and is used as is. A relative one may not contain `..` (422 at save time) and, at every read/write (runner included), its parent directory must resolve (realpath, so symlinks count) inside the project folder; a final-component symlink must also resolve inside it. Violations → 409 `instructions_path_outside_folder`.
    - `PUT /instructions` validates with the pipeline parser (`RUN_ID` = 0 for validation): invalid content → 422 `invalid_pipeline` with `details.errors` and nothing is written, unless `?force=1` (saved, response says `valid:false` + errors), so an operator can save a work in progress. Writes are atomic (temp file + rename in the same directory, keeping existing permissions); the directory must already exist (409 `instructions_not_writable`). Content max 256 KiB.
    - Webhook tokens: 256-bit random hex returned once by `POST /api/projects/{id}/token` (201); only sha256 stored; regenerating invalidates the old token immediately. `POST /hooks/{slug}` compares `hash_equals(sha256(header), stored)`; unknown slug, project without token, missing or wrong token all return the same 401 `invalid_token` and create no run. Header only (no query-string token, which would leak into access logs).
    - Webhook payload: every valid-token call triggers a run (no event/branch logic, e.g. a GitHub `ping` also deploys). A summary (`event`, `ref`, `branch`, `commit`, `message` first line, `author`; max 200 chars each) is extracted from GitHub/GitLab/Gitea/custom JSON and stored in `runs.trigger_meta` (migration 3). Non-JSON bodies trigger without metadata; bodies over the 1 MiB cap still trigger (body ignored) instead of 413, so a large push never blocks a deploy.
    - Triggers stored as `webhook`, `manual` (API) or `cli`. Cancel: queued → 200 `{"result":"cancelled"}`, running → 202 `{"result":"requested"}`, finished → 409 `already_finished`.
    - Run/step responses include `duration_ms` (null while not finished); logs return `{lines:[{id,step,stream,line,at}], next_after, run_status, complete}`; list is newest first with keyset pagination (`before`), `limit` 1-200 (default 50).
- [x] T6 — Telegram notifier on run finish. Route: delegated.
  - Decisions:
    - Ports: `Notifiers\Application\Port\Notifier` (send HTML through a `NotifierConfig`) with the `TelegramNotifier` adapter, over `Shared\Application\Port\HttpClient` (`CurlHttpClient` in production, `FakeHttpClient` in tests: no real network). The runner hook is the `Runs\Application\Port\RunFinishedListener` port, implemented by `NotifyRunFinished`, so Runs does not depend on Notifiers.
    - `ExecuteRun` calls the listener only after the final status is persisted, and wraps it in try/catch; `NotifyRunFinished` itself never throws. Delivery outcome is appended as a system line (`Notification sent via "<name>".` / `Notification via "<name>" failed: <reason>`). Those lines come after `Run finished: ...`, so a client that stopped polling at `complete` may only see them on reload (accepted: notifying before persisting the status would delay the visible finish by up to the 10 s HTTP timeout).
    - Only runs executed by a runner notify (success/failed/cancelled). A queued run cancelled from the UI/CLI never reaches the runner and sends nothing.
    - Message (HTML parse mode, all dynamic text escaped with `htmlspecialchars`): status emoji (✅ success, ❌ failed, 🚫 cancelled) + project name + status; run id, trigger, duration; branch/commit from the trigger summary when present; on failure the step that stopped the run (failed and not `continue_on_error`) and its last 10 stdout/stderr lines in `<pre>` (each line ≤300 chars, whole message ≤4000 chars keeping the newest lines); a failure before any step shows the run error instead.
    - The bot token lives in the request URL, so every surfaced error (API description or transport error) is redacted (`***`) before it reaches logs or API responses. HTTP timeout 10 s (connect 5 s), TLS verification on, no redirects.
    - `POST /api/notifiers/{id}/test` sends a test message: 200 `{"sent":true}` or 502 `notification_failed` with the (redacted) reason.
    - ext-curl is used for Telegram (ubiquitous on cPanel); without it the notification fails with a clear logged reason and runs are unaffected. No runtime dependency added for HTTP.
    - Added `symfony/polyfill-mbstring` as a production dependency (it was already locked as a dev transitive dependency, same version): T4-T6 use `mb_*` for user-facing length limits and UTF-8 scrubbing, and ext-mbstring is not guaranteed on shared hosts. Phar grows 505 → 658 KiB.
- [x] T7 — Frontend Vue SPA: setup/login, projects, notifiers, YAML editor, run live view (steps + log), history. Route: delegated.
  - Decisions:
    - Stack: Vue 3.5 + Vue Router 4 + Vite 8 + TypeScript 6 (`vue-tsc` runs in `npm run build`), Vitest 5 in the node environment. No UI kit, no state library: a module-level session store and small composables (`useResource`, `useLogStream`, `usePolling`, `useNow`, `useBreadcrumbs`, `useToasts`). TypeScript stays on 6.x because TS 7 (native port) is not yet a safe base for `vue-tsc`.
    - YAML editor: CodeMirror 6 (state/view/commands/language/lang-yaml, no `basicSetup`) loaded with a dynamic import only on the instructions screen (326 KB raw / 107 KB gzip chunk); a monospace textarea is shown until it loads or if the chunk fails. Ctrl/Cmd+S saves; Esc then Tab leaves the editor.
    - Base path: the front controller splits the URL into a base and a route path (`Request::splitPath`): behind a rewrite the base is '' (or the install subdirectory); without one, PATH_INFO URLs work (`/index.php/projects/3`, base `/index.php`). The bare root without the rewrite marker (`E=DEPLOYER_REWRITE:1` from the shipped .htaccess, seen as `DEPLOYER_REWRITE` or `REDIRECT_DEPLOYER_REWRITE`) gets the PATH_INFO base, since Apache's DirectoryIndex would 404 clean deep links. PHP's built-in server always keeps clean URLs, and a `SCRIPT_NAME` that does not end in `.php` is ignored (php -S reports the request path there when the docroot has no matching script).
    - Vite builds with `base: './'`; `index.html` carries `<base href="/">`, which the server rewrites to `<base href="{base}/">`. Assets, router history and API calls all resolve against it (`appBasePath()`), so one build works under any base.
    - Static serving (`StaticSite`, before the auth guard, never for `/api` or `/hooks`): GET/HEAD only; files under `assets/` get `Cache-Control: public, max-age=31536000, immutable`, others and the shell `no-cache` + sha256 ETag (304 on `If-None-Match`). Paths without an extension fall back to `index.html`; missing files with an extension 404. Paths are validated lexically per segment (`[A-Za-z0-9_-][A-Za-z0-9._-]*`, so no traversal and no dotfiles) because `realpath` does not work on `phar://`. The shell gets a CSP (`script-src 'self'`, `style-src 'self' 'unsafe-inline'` for CodeMirror, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`. A missing build answers 404 with a hint. Web root defaults to `frontend/dist` next to `src/` (inside the phar when packaged) and is injectable for tests.
    - API client: same-origin credentials; CSRF token from login/setup responses or lazily from `GET /api/me`, refreshed and retried once on `csrf_mismatch`; only the `unauthenticated` 401 code triggers the login redirect (wrong credentials do not); every failure is an `ApiError` (network → status 0 `network_error`, non-JSON → `http_error`). Post-login `redirect` accepts only same-app paths (no open redirect).
    - Live log: cursor polling every 1.5 s, immediate re-fetch while a full page (500) comes back, stop on `complete`, stop for good on 401/404, retry other errors at 3 s. The run detail (step statuses/durations) is refreshed whenever the log moves and once more when the stream stops. The executed command (system line `$ cmd`) renders as `▸ cmd`; stderr is red, system lines dimmed, step headers bold. The log follows output until the user scrolls up ("Jump to latest" resumes); selecting a step scrolls to its first line.
    - Conservative product choices: destructive actions (delete project/notifier, regenerate an existing token) use `window.confirm`; the webhook token is held only in component memory right after generation; leaving the instructions editor with unsaved changes asks first; a missing instructions file opens with a starter pipeline. Dark theme by default, light tokens via `prefers-color-scheme`; the log and editor panels stay dark in both. System font stacks only (nothing remote, CSP-friendly).
    - Size: ~4.6k authored lines (excluding `package-lock.json`), well above the 400-line planning heuristic: the SPA is one coherent screen set over an existing API and splitting it would leave unusable intermediate states. Recorded as an accepted overage for this slice.
- [x] T8 — Packaging + docs: phar with embedded assets, cPanel install guide (subdomain, PHP CLI path, cron sweep), end-to-end smoke test. Route: delegated.
  - Decisions:
    - `build/build-phar.php` requires a prebuilt `frontend/dist` (fails with a hint otherwise) and embeds every file under `frontend/dist/` in the phar; the single entry point `build/build.sh` (also `composer build`) runs `npm ci && npm run build` first; `--skip-frontend` reuses an existing build. Phar grows 658 → 1172 KiB (41 assets; the CodeMirror chunk is 326 KiB and only downloaded on the editor screen). Still uncompressed (no ext-zlib dependency).
    - `.htaccess` is optional: `public/.htaccess` (copied to `dist/.htaccess` by the build) rewrites non-file paths to index.php with `E=DEPLOYER_REWRITE:1`, keeps real files (AutoSSL `/.well-known`) reachable, and denies `deployer.config.php`. Without it the app works through PATH_INFO URLs (`/index.php/...`, webhook `/index.php/hooks/<slug>`), and the bare domain switches to that form. Hosts that disable PATH_INFO need the `.htaccess` (documented with a `/index.php/api/health` check).
    - README follows cognitive-doc-design: quick path first, then requirements table (incl. how to check `proc_open` under the web SAPI), configuration, URL modes, cron sweep, recovery, YAML reference, webhooks (curl + GitHub Actions), Telegram setup, security notes (token once, throttling behind proxies), limitations, development/build.

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
- T4 (delegated writer; trigger: 2+ non-trivial files). RED observed: `vendor/bin/phpunit tests/Auth` → 17/17 errors (classes/params missing) before implementation; GREEN: 17/17. Checks: `vendor/bin/phpunit` → OK (83 tests, 277 assertions); `php -l` → 0 errors. Commit: 7e99333.
- T5 (delegated writer). RED observed: `vendor/bin/phpunit` → 29 failures in the new `tests/Api` suites before implementation; GREEN: OK (115 tests, 475 assertions). `php -l` → 0 errors. Phar smoke (`dist/deployer.phar` 505 KiB copied as `index.php`, `php -S`): setup/status 200 `needs_setup:true` → setup 201 → setup again 409 → login 200 → me 200 → create project without CSRF 403 → with CSRF 201 → instructions 200 valid → token 201 → webhook bad token 401 → good token 202 (run 1) → polled logs 3 times until `complete` → `success` with step output, `pwd` = project folder; run list shows trigger `webhook` with meta `{ref, branch, commit}`, and only 1 run exists (the 401 created none). Commit: 05f9e77.
- T6 (delegated writer). RED observed: `vendor/bin/phpunit` → 62 errors (the `HttpClient` port used by the test fake did not exist; new Notifiers suites) before implementation; GREEN: OK (128 tests, 527 assertions). `php -l` → 0 errors. Phar smoke repeated on the 658 KiB phar with the same observed statuses as T5 (setup 201/409, login 200, CSRF 403/201, token 201, webhook 401/202, run `success` after 3 log polls). Telegram delivery itself is verified only through the fake HTTP client (no real network by design). Commit: b5d189f.

- T7 (delegated writer). RED observed: `vendor/bin/phpunit tests/Shared` → 25 errors (`Request::splitPath`, `basePath`, `webRoot` missing) before implementation, then 1 failure for the added php -S `SCRIPT_NAME` case before its guard; GREEN: OK. `npx vitest run` → 2 failed suites (modules missing) before implementation; GREEN: 3 files, 19 tests. Checks: `vendor/bin/phpunit` → OK (154 tests, 592 assertions); `php -l` → 0 errors; `npx vue-tsc --noEmit` → 0 errors; `npm run build` → OK. Source smoke (`php -S -t public public/index.php`): `/` 200 html base `/`, hashed asset 200 `text/javascript` immutable, `/index.php/<asset>` 200, `/projects/x` 200 shell, `/index.php/projects/x` shell with base `/index.php/`, `/api/setup/status` 200 JSON, missing asset 404. Browser check (headless Chrome via playwright-core from a scratch dir, not a repo dependency): setup → notifier → project → token shown once → Deploy → live run → success with 3 `▸` command lines and 1 stderr line → deep-link reload → CodeMirror loads → invalid YAML 422 shown inline → "Save anyway" → logout → guard redirect with `redirect` param; no horizontal overflow at 1440 px or 375 px (one desktop overflow found and fixed: implicit `auto` grid tracks growing to the curl example width). Console only showed the expected 422 and post-logout 401. Commit: 9c6e173.

- T8 (delegated writer). Packaging/docs task: no meaningful RED (build script and docs); verified by the end-to-end smoke instead. Checks: `composer build` → frontend built, `Built dist/deployer.phar (1,172.2 KiB, 41 web assets) and dist/.htaccess`; `vendor/bin/phpunit` → OK (154 tests, 592 assertions); `php -l` → 0 errors; `bash -n build/build.sh` → OK. Smoke (phar copied as `www/index.php`, `php -S 127.0.0.1:8795 -t www www/index.php`, curl only): `/` 200 `text/html` with `<div id="app">`; `/assets/index-*.js` 200 `text/javascript; charset=utf-8` + `public, max-age=31536000, immutable`; `/projects/x` 200 shell (base `/`); `/index.php/projects/x` 200 shell (base `/index.php/`); `/index.php/assets/index-*.js` 200 JS; `setup/status` 200 `needs_setup:true` → setup 201 → login 200 → create project (CSRF) 201 → token 201 → webhook bad token 401 → good token 202 (run 1) → logs polled until `complete` → `success` with the command line and output, `pwd` = project folder, trigger `webhook` with branch `main`; data dir created at `../deployer-data` outside the docroot. Extra browser runs against the phar (playwright-core in a scratch dir): full UI flow as in T7 passes; PATH_INFO mode stays under `/index.php/` across navigation and reload, webhook URL shown as `/index.php/hooks/demo-shop`; simulated Apache bare root without rewrite lands on `/index.php/login`. Not verified: a real Apache/cPanel host (mod_rewrite `E=` propagation, PATH_INFO under the host's FPM config).

## Next step
Human decisions: push `feat/deployer-core` and open the stacked PR slices; try the artifact on the real cPanel host.
