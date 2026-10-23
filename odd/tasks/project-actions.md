# Feature: project-actions

## Objective
Per-project named "actions" (e.g. `clear-cache`, `rebuild-cache`): special instruction sets defined in the project's instructions file, run on demand from the UI or through the webhook, with the same queue, live log, cancel, retry and notifications as a deploy. Ship as `v0.5.0`.

## Problem / Why
Today a project has exactly one pipeline (`steps`), run by every webhook/manual trigger. Maintenance tasks such as clearing a cache need a full deploy or shell access (the cPanel host has no Terminal). The user wants several such tasks per project, launched with one click, with the usual log.

## Decisions (parent, defaults the user can revisit)
- **Definition**: top-level `actions:` map in the same `deployer.yml`. Key = action id (`^[a-z0-9][a-z0-9_-]*$`, max 64), value = `{ name?, description?, steps }`. `steps` uses the exact step schema of the deploy pipeline (run string/list, timeout, continue_on_error, strict, env). Global `vars` and `env` apply to actions too. `steps` (the deploy) stays required. Validation errors are prefixed `actions.<id>.`.
- **Same queue**: an action run is an ordinary run of the project (serialized with deploys, cancellable, retryable, notified). Rationale: actions touch the same files as deploys; parallel execution could corrupt a deploy.
- **Storage**: migration 6 adds `runs.action TEXT NULL` (NULL = deploy). The runner reads the CURRENT instructions file when the run starts (same as deploys/retry) and executes that action's steps; if the action no longer exists the run fails with a clear message.
- **Triggers**: UI → `POST /api/projects/{id}/actions/{action}/runs` (session + CSRF) → 202 `{run_id, status, action}`, trigger `manual`. Webhook → `POST /hooks/{slug}/actions/{action}` with `X-Deployer-Token` → 202, trigger `webhook`. Unknown action or an invalid instructions file → 404 `unknown_action` / 422 `invalid_instructions` at enqueue time (webhook: only after the token is verified). CLI `trigger` gains `--action=<id>`.
- **Listing**: `GET /api/projects/{id}/actions` → `{actions: [{id, name, description, steps}], error: null|string}` parsed from the current file.
- **Retry** keeps the original action. Notifications and run presentation include the action (id + name).
- **UI**: project page "Actions" card (one Run button per action, confirm modal, navigates to the new run; empty state explains the YAML); runs lists and run header show an action badge/label; instructions editor starter template and help mention `actions`.

## Out of scope
Action parameters/inputs, per-action tokens, scheduled actions, running an action in parallel with a deploy.

## Constraints
- Artifacts in English. Conventional commits, no AI attribution.
- Branch `feat/project-actions` from `main` (6e8456a); fast-forward `main`, tag `v0.5.0`; push may fail (no SSH key) → hand the command to the user.
- Delivery: forecast ~900 authored lines (> 400); same strategy as previous rounds (work-unit commits fast-forwarded to `main`, user pushes).

## Tasks
- [x] T1 — Pipeline: parse/validate `actions` (domain model, errors, tests). Route: delegated. Commit 1fcab22.
- [x] T2 — Runs: migration 6 `runs.action`, enqueue/execute/retry an action, API (list + run), webhook route, CLI `--action`, presenter + notification text, tests. Route: delegated. Commit c5adf8e.
- [x] T3 — UI: Actions card, action labels in run lists/header, editor template/help, tests. Route: delegated. Commit d536539.
- [x] T4 — README (actions section + webhook URL) + changelog 0.5.0; phar build + headless check. Route: delegated. Commit 41b333e.

## Checks
PHPUnit, vitest, vue-tsc, frontend build, phar build (0.5.0), headless browser: define two actions, run one from the UI, run one via webhook, see logs and labels.

## Progress / Evidence

### T1 — pipeline actions (commit 1fcab22)
- Route: delegated (bounded writer).
- Model: `Pipeline::$actions` (`array<string, Action>`, declaration order) + `Pipeline::action(id)`; `Action{id, name, description, steps}`; `Action::ID_PATTERN = ^[a-z0-9][a-z0-9_-]{0,63}$`.
- Decisions: `name` defaults to the id; an empty/whitespace `description` becomes null; `actions: {}` / `actions: ~` mean "no actions"; allowed action keys `name, description, steps`; step labels read `actions.<id>.steps[N] (name): ...`; action step env = global env merged with step env (step wins), same as deploy steps.
- `YamlEnvRedactor` also hides `actions.<id>.steps[N].env` values.
- RED: `tests/Pipeline/PipelineActionsTest.php` — 22 tests, 2 errors + 19 failures (`Undefined property Pipeline::$actions`). GREEN: `vendor/bin/phpunit` → OK (297 tests, 1001 assertions).

### T2 — runs backend (commit c5adf8e)
- Route: delegated (bounded writer).
- Decision: migration 6 adds `runs.action TEXT NULL` **and** `runs.action_name TEXT NULL`. The display name is captured at enqueue time so run lists and notifications need not re-parse the (possibly changed) file per run; retry copies both from the source run. The presenter falls back to the id when the name is missing.
- `ProjectActions` (Runs/Application) reads + parses the current file (RUN_ID 0, like the editor validation) for listing and enqueueing; `InstructionsUnavailable` (missing/unreadable/invalid file) → 422 `invalid_instructions`, `UnknownAction` → 404 `unknown_action`. The whole file must be valid (deploy `steps` included) to run an action.
- `GET /api/projects/{id}/actions` → `{actions: [{id, name, description, steps: [step names]}], error}`. Decision: `steps` lists step names only (commands interpolated with RUN_ID 0 would be misleading); a missing file or invalid file returns 200 with `actions: []` and the error text.
- `POST /api/projects/{id}/actions/{action}/runs` (session + CSRF via the /api guard) and `POST /hooks/{slug}/actions/{action}` (token verified first; same 401 for unknown project/bad token/any action) → 202 `{run_id, status, action}`; the webhook keeps the payload summary as `trigger_meta`.
- Runner: start line reads `(trigger: manual, action: clear-cache)`; a removed action fails before any step with `Action "<id>" is not defined in the instructions file (it was removed or renamed after the run was queued).`
- CLI: `run:trigger <project> [--action=<id>]`; unknown `--` options are rejected.
- Notification: an `Action: <b>Name</b> (id)` line right below the headline (id omitted when equal to the name).
- RED: `tests/Api/ActionsApiTest.php` (10 tests) + `RunFinishedMessageTest::testActionRunsNameTheAction` → 9 failures. GREEN: `vendor/bin/phpunit` → OK (308 tests, 1085 assertions).

### T3 — UI (commit d536539)
- Route: delegated (bounded writer).
- `ActionsPanel.vue` (project page side column, above Webhook): one **Run** per action (`aria-label="Run action <name>"`), `useConfirm` (non-danger, "Run action"), navigates to `/runs/<id>`; parse errors shown as a warning with an "Edit instructions" link; empty state explains `actions:` with a YAML example. On `unknown_action`/`invalid_instructions` it reloads the list.
- `ActionBadge.vue` (new `bolt` icon, accent pill) in `RunsTable` (Run column, so it stays visible on mobile; covers Overview, Runs and project history) and in the run header, plus an "Action" meta entry (name + id).
- Decision: no badge in the sidebar (it lists recent projects, not runs).
- Webhook card: "Action" URL pattern `<webhook url>/actions/<action-id>` and one sentence in the intro.
- Decision: the instructions starter template shows `actions:` **commented out**, so a new file does not create an action that may not apply; the YAML reference lists `actions`.
- RED: `src/lib/actions.test.ts` failed (module missing). GREEN: 3 tests; `npx vitest run` 74 passed; `npx vue-tsc --noEmit` OK; `npm run build` OK.

### T4 — README, changelog, build, headless check (commit 41b333e)
- Route: delegated (bounded writer).
- README: new "Actions" section (YAML example, dashboard, webhook with curl, CLI, same-queue note, API), webhook note, CLI table `--action`, changelog 0.5.0 (migration 6).
- `DEPLOYER_VERSION=0.5.0 build/build.sh --skip-frontend` → built; `php dist/deployer.phar about` (scratch data dir) → `Deployer 0.5.0`, `Schema 6`.
- Headless E2E (phar as `index.php` under `php -S 127.0.0.1:8097`, scratch `DEPLOYER_DATA_DIR`, playwright-core 1.48.2 + system Chrome, scratch only): instructions with `clear-cache` and `rebuild-cache`. UI Run → confirm dialog → run #1 success, log `cache cleared in production`, header shows the "Clear cache" badge and Action meta. Webhook curl `rebuild-cache` → 202, run #2 success (trigger webhook, steps Warm up/Report); unknown action → 404 `unknown_action`; bad token → 401; API unknown action → 404. Runs table shows both badges. Mobile 390px: no horizontal overflow on project and run pages. No console errors.
- Screenshots: `/tmp/claude-1000/-home-webcloster-dev-Development-Repos-PRODUCTIVITY-deployer-compiled/7a5f203f-571e-473a-b75e-14c312bb1b53/scratchpad/project-actions/shots/` (project-desktop, confirm-desktop, run-desktop, runs-desktop, webhook-run-desktop, project-mobile, run-mobile, runs-mobile).

## Closing checks
- `vendor/bin/phpunit` → OK (308 tests, 1085 assertions).
- `npx vitest run` → 74 passed; `npx vue-tsc --noEmit` → OK; `npm run build` → OK.
- phar 0.5.0 build + `about` → 0.5.0 / schema 6.
- Pending (user/parent): fast-forward `main`, tag `v0.5.0`, push; Engram mirror update by the parent.

## Next step
Parent review; then fast-forward `main`, tag `v0.5.0`, push.
