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
- [ ] T2 — Runs: migration 6 `runs.action`, enqueue/execute/retry an action, API (list + run), webhook route, CLI `--action`, presenter + notification text, tests. Route: delegated.
- [ ] T3 — UI: Actions card, action labels in run lists/header, editor template/help, tests. Route: delegated.
- [ ] T4 — README (actions section + webhook URL) + changelog 0.5.0; phar build + headless check. Route: delegated.

## Checks
PHPUnit, vitest, vue-tsc, frontend build, phar build (0.5.0), headless browser: define two actions, run one from the UI, run one via webhook, see logs and labels.

## Progress / Evidence

### T1 — pipeline actions (commit 1fcab22)
- Route: delegated (bounded writer).
- Model: `Pipeline::$actions` (`array<string, Action>`, declaration order) + `Pipeline::action(id)`; `Action{id, name, description, steps}`; `Action::ID_PATTERN = ^[a-z0-9][a-z0-9_-]{0,63}$`.
- Decisions: `name` defaults to the id; an empty/whitespace `description` becomes null; `actions: {}` / `actions: ~` mean "no actions"; allowed action keys `name, description, steps`; step labels read `actions.<id>.steps[N] (name): ...`; action step env = global env merged with step env (step wins), same as deploy steps.
- `YamlEnvRedactor` also hides `actions.<id>.steps[N].env` values.
- RED: `tests/Pipeline/PipelineActionsTest.php` — 22 tests, 2 errors + 19 failures (`Undefined property Pipeline::$actions`). GREEN: `vendor/bin/phpunit` → OK (297 tests, 1001 assertions).

## Next step
T2.
