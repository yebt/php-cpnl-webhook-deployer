# Feature: pipeline-v2

## Objective
Make step failures reliable and the pipeline format more expressive, then ship `v0.3.0`.

## Problem / Why
On the real host (run #7, project "platform") a step whose `mv` failed was reported as success: steps run as `bash -c <script>`, so only the exit code of the LAST command counts. The user also wants `run` as a list, real environment variables (`env:`), and a notification whenever a run stops with failures — today `runner:sweep` marks dead-runner runs as failed without notifying (`src/Runs/Application/SweepRunners.php:53-57`).

## Decisions (agreed with the user)
- **Strict mode by default**: steps run like GitHub Actions' bash (`bash --noprofile --norc -eo pipefail`). First failing command fails the step; pipe failures count; commands in `if`/`while` conditions or with `|| true` keep working. Per-step opt-out `strict: false` (old behavior: plain `bash -c`).
- **`run` as a list**: each item is a command executed in order in the SAME shell (cwd/vars persist across items); with strict mode the first failing item stops the step. The log shows each item as its own `▸` command line (exact interpolated text). A single string (including `|` blocks) keeps working.
- **`env:`** at top level (global) and per step (merged, step overrides): exported as real environment variables to the step process. Values are strings (scalars converted), may reference `vars` with `${X}` (interpolated at parse time, unknown → validation error), names must match `^[A-Za-z_][A-Za-z0-9_]*$`. In commands they are used with plain `$NAME` (bash expands them; the deployer does not). Env values are NOT printed in the log. `vars:` semantics unchanged (textual substitution before execution).
- **Notify on every failed stop**: success/failed/cancelled from the runner (existing), PLUS runs failed by `runner:sweep` (dead runner). Notification failures never change run status.

## Scope
Pipeline parser/validation (PHP domain), run engine step execution, sweep notification, instructions editor starter template + docs (README YAML reference), UI log rendering of multi-command steps if needed.

## Out of scope
Secrets management/masking of env values beyond not printing them; per-step shell selection other than `strict`.

## Constraints
- Generated artifacts in English. Conventional commits, no AI attribution.
- Backward compatible: existing v1 pipelines parse unchanged (only the strict default changes failure detection — documented as a behavior change in the README/changelog notes).
- Work on `feat/pipeline-v2` from `main`; fast-forward `main`, tag `v0.3.0`; push may fail in this session (no SSH key) → hand commands to the user.

## Tasks
- [x] T1 — Strict mode (default on, `strict: false` opt-out) + regression test reproducing run #7 (failing `mv` followed by `echo` → step failed). Route: delegated.
- [ ] T2 — `run` as a list (same shell, per-item log lines, strict stop). Route: delegated.
- [ ] T3 — `env:` global + per step (validation, interpolation of vars, export to the step process, not logged). Route: delegated.
- [ ] T4 — Notify on sweep-failed runs; README YAML reference + editor starter template updated. Route: delegated.

## Checks
PHPUnit (unit + integration with real processes), vitest, vue-tsc, frontend build, phar build, end-to-end through the phar with a pipeline using list + env + a failing command.

## Progress / Evidence

### T1 — strict mode (commit 5ba6010)
- Route: delegated (bounded writer; executor + parser + engine, 2+ non-trivial files).
- Design: `Step::$strict` (default true). The executor's setsid wrapper now `exec`s `bash --noprofile --norc -eo pipefail -c "$1"` for strict steps and keeps `bash -c "$1"` for `strict: false`. `StepExecutor::execute()` takes the `Step` itself (timeout/strict read from it) instead of loose arguments.
- Logging decision: no extra line for the default; a system line `strict: false; only the exit code of the last command counts.` is written only for opted-out steps, so ordinary logs stay clean.
- RED: `ExecuteRunTest::testStrictModeFailsTheStepAtTheFirstFailingCommand` (run #7 script: `if/else` with `mv composer.phar /missing/dir/composer` then `echo done`) failed with status `success`; parser/`strict: false` tests failed on the unknown key. GREEN after the change.
- Tests added: run #7 regression, pipe failure (`false | cat`), `if !`/`|| true`/`test && ...` not failing, `strict: false` last-command semantics (executor + engine), `strict` must be boolean, startup files not read (guard; it also passed before, since non-interactive bash skips rc files).
- `vendor/bin/phpunit`: OK (240 tests, 839 assertions).

## Next step
T2.
