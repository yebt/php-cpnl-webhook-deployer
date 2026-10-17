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
- [x] T2 — `run` as a list (same shell, per-item log lines, strict stop). Route: delegated.
- [x] T3 — `env:` global + per step (validation, interpolation of vars, export to the step process, not logged). Route: delegated.
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

### T2 — `run` as a list (commit 1ae29a6)
- Route: delegated (parser + executor + engine).
- Design: `Step::$commands` (non-empty list); `Step::$command` is the items joined with newlines (stored in `run_steps.command`, API unchanged). A string `run` is a one-item list (byte-identical script, no marker).
- Per-item logging: the items are concatenated into ONE bash script; before each item the script runs `printf '\037<nonce>:<i>\037\n'`. The nonce is 16 random hex chars per execution. The executor recognises a stdout line ending with that marker, emits any text before it as stdout (previous item's output without trailing newline), and calls `onCommand(i)`; the engine logs `$ <item>` there. Why: markers share the stdout pipe with the items' output, so command lines are exactly ordered relative to stdout without a DEBUG trap, extra fds or feeding commands through stdin (which a command reading stdin would swallow). Stderr ordering stays best effort, as for any stdout/stderr interleaving. Rejected: engine-side per-item processes (would break the same-shell requirement).
- Failures: `StepOutcome::$command` is the last announced item; for multi-item steps the result line and run error read `Step failed (exit code N, Xs) at command i/n: <first line of item>` (timeouts get the same suffix).
- Frontend: no change needed (lines are filtered by step position; each `$ ` line renders as `▸`); added a vitest case documenting a multi-command step.
- RED: new parser/executor/engine tests failed (TypeError/unknown shapes, no per-item lines); GREEN after the change.
- `vendor/bin/phpunit`: OK (251 tests, 864 assertions). `npx vitest run src/lib/logSteps.test.ts`: 8 passed.

### T3 — `env:` (commit cdf00e7)
- Route: delegated (parser + executor + API).
- Design: `Pipeline::$env` (global) and `Step::$env` (global merged with step, step wins, via `array_merge`). The executor passes `Shell::environment($step->env)`, i.e. on top of the base env with PATH/HOME defaults, so `env: {HOME: ...}` overrides HOME.
- Value rules (decided): scalars only; booleans become `"true"`/`"false"` (same as vars), numbers are stringified, `null`/lists/maps are errors (`env.X: value must be a scalar (string, number or boolean).`). `${X}` is interpolated from built-ins + vars at parse time (unknown → `env.X: unknown variable "X".`), `$${X}` yields a literal `${X}`. Values are exported verbatim — the deployer never shell-expands them (so `PATH: /opt/bin:$PATH` is literal; extend PATH with `export` inside `run`). Names follow `^[A-Za-z_][A-Za-z0-9_]*$`; step errors are prefixed with the step label.
- Not logged: the engine only logs commands, never env values (asserted in `ExecuteRunTest`).
- Redaction (decided, conservative): `GET /api/runs/{id}` returns `pipeline_yaml` verbatim when the snapshot has no `env`; otherwise `YamlEnvRedactor` re-dumps it with every env value replaced by `[redacted]` plus a notice comment (comments/formatting lost). The stored snapshot itself is unchanged; the live instructions editor still shows the file as written (it is the editable source).
- Extra hardening: bash is resolved to an absolute path from the base PATH (`Shell::bash()`), so a step `PATH` without `/usr/bin` cannot hide the shell (RED observed by temporarily reverting, GREEN restored).
- RED: parser/engine/API tests failed (unknown key `env`, values missing, snapshot leaked); GREEN after the change. A real child process (`php -r 'getenv("APP_ENV")'`) sees the value.
- `vendor/bin/phpunit`: OK (266 tests, 895 assertions).

## Next step
T4.
