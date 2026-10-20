# Feature: ui-feedback-2

## Objective
Second round of user feedback from production use (deploy.menukap.com), shipped as `v0.4.0`.

## Scope (user feedback)
1. **Copy logs**: a "Copy" button on the log card that copies exactly what is currently shown (all steps, or only the focused step), as plain text with timestamps and stream markers readable (e.g. `HH:MM:SS [stderr] ...`, commands prefixed with `$ `). Feedback on success ("Copied") and graceful fallback when the Clipboard API is unavailable (non-secure context): select-all textarea fallback.
2. **Retry failed runs**: on a run that ended `failed` (and `cancelled`), a "Retry" button enqueues a NEW run for the same project (trigger `retry`, linked to the original via `retry_of`), using the project's CURRENT instructions file (record decision), then navigates to the new run. API endpoint with auth + CSRF; 409 if the source run is not in a terminal failed/cancelled state. Run detail shows "Retry of #N" when applicable.
3. **Confirm modal**: replace every `window.confirm` (delete project/notifier, regenerate/revoke token, cancel run, apply update, discard unsaved editor changes if it uses confirm, etc.) with an accessible in-app modal component (focus trap, Esc/Cancel, Enter confirms, danger variant for destructive actions, promise-based `useConfirm()` API).
4. **Richer command highlighting**: command lines (`▸ ...`) in the log get bash syntax highlighting with more color: keywords (`if then else fi for do done while case esac function`), builtins/common commands, strings (single/double), variables (`$X`, `${X}`, `$(...)`), operators (`&& || | > >> <`), comments, flags (`--force`, `-eq`), numbers. Lightweight tokenizer (no heavy dependency), safe against HTML injection (render via text nodes/escaped spans), theme tokens for dark/light, good contrast.
5. **Token revocation**: projects keep a single webhook token; add "Revoke token" (with confirm modal) that deletes it so the webhook returns 401 until a new token is generated. The webhook card shows the state (active since <date> / no token). API endpoint with auth + CSRF.

## Constraints
- Generated artifacts in English. Conventional commits, no AI attribution.
- Work on `feat/ui-feedback-2` from `main` (c4426e1); fast-forward `main`, tag `v0.4.0`; push may fail in this session (no SSH key) → hand commands to the user.

## Tasks
- [x] T1 — Confirm modal + replace all `window.confirm`. Route: delegated.
- [x] T2 — Token revocation (API + UI + tests). Route: delegated.
- [x] T3 — Retry failed/cancelled runs (migration if needed, API, UI, tests). Route: delegated.
- [x] T4 — Copy logs + bash highlighting for command lines. Route: delegated.
- [ ] T5 — README updates (retry, revoke, copy) + changelog 0.4.0. Route: delegated.

## Checks
PHPUnit, vitest, vue-tsc, frontend build, phar build, headless browser screenshots (desktop + mobile) of modal, revoke, retry, copy, highlighting.

## Progress / Evidence

### T1 — confirm dialog (commit 7e29b1a)
- Route: delegated (bounded writer; new component + composable + 8 call sites).
- Design: `useConfirm()` returns `confirm(options) => Promise<boolean>` (never rejects; cancel → `false`); requests made while a dialog is open are queued. One `ConfirmDialog` host in `App.vue` renders a native modal `<dialog role="alertdialog">` (page behind is inert) with `aria-labelledby`/`aria-describedby`, Tab trap, Esc and backdrop click cancel, focus restored to the trigger.
- Keyboard decision: Enter confirms unless focus is on Cancel. Danger dialogs open with focus on Cancel, so Enter there cancels (safe default); Tab → Enter confirms. Non-danger dialogs open on the confirm button.
- Replaced every `window.confirm` (grep-verified, none left): delete project, delete notifier, regenerate token, apply update (`useUpdates` confirm is now `({title, message}) => boolean | Promise<boolean>`, guarded against double prompts), leave instructions with unsaved changes (route guard returns the promise; `beforeunload` unchanged — browsers only allow their own prompt there).
- Added (decision): cancel run now asks too (danger; "Keep running"/"Keep queued"), since the feature scope lists it.
- New token `--danger-solid` (#da3633 dark / #cf222e light) for the filled destructive button: white text ≥ 4.5:1.
- RED: `useConfirm.test.ts` failed (module missing). GREEN: 7 tests. `useUpdates.test.ts` adapted + async-confirm case.
- `npx vitest run`: 50 passed. `npx vue-tsc --noEmit`: OK.
- Browser check: see final verification section.

### T2 — token revocation (commit 3cfeb0f)
- Route: delegated (backend repo/use case/controller + migration + UI).
- API: `DELETE /api/projects/{id}/token` → 204, behind the existing `/api` session + CSRF guard; idempotent (204 when there is no token), 404 for unknown projects. The webhook already answered 401 `invalid_token` for a project without a hash (verified by the new test).
- Migration 4 (new): `projects.webhook_token_created_at TEXT NULL`, set on generate, cleared on revoke. Decision: tokens generated before 0.4.0 keep `null` (no invented backfill); the UI then says just "Active".
- Projects expose `token_created_at` (null when no token); the hash is never returned.
- UI: status "Active · generated <relative>" (full date in the tooltip) / "No token"; "Revoke token" (danger confirm, only when a token exists) next to "Generate/Regenerate token"; a freshly shown token is hidden after revoking. Wording decision: "generated 3m ago" instead of "since 3m ago" (reads correctly with relative times).
- RED: 3 new `WebhookApiTest` cases failed (405 for DELETE, missing `token_created_at`). GREEN: `vendor/bin/phpunit` OK (272 tests, 937 assertions). `npx vitest run`: 50 passed; `vue-tsc`: OK.

### T3 — retry (commit cdbf09d)
- Route: delegated (migration + use case + controller + UI).
- Design: `RetryRun` use case → `EnqueueRun(projectId, 'retry', retryOf: id)`; the runner reads the project's CURRENT instructions file when the run starts (same path as a manual deploy), not the failed run's snapshot — so a fixed pipeline is picked up. `RunNotRetryable` → 409 `not_retryable` for queued/running/success; 404 unknown run; session + CSRF from the `/api` guard.
- Migration 5 (new): `runs.retry_of INTEGER NULL REFERENCES runs(id) ON DELETE SET NULL`. Run list/detail expose `retry_of` (null for ordinary runs). Response: `202 {run_id, status: "queued", retry_of}`.
- Notifications: unchanged code path; a retried run is an ordinary run whose message reads `trigger: retry`.
- UI: "Retry" (primary, no confirm — non-destructive) on failed/cancelled runs, navigates to the new run; "Retry of → Run #N" link in the run header meta; new `retry` icon; `isRetryable()` helper.
- RED: 3 new `RunsApiTest` cases failed (405); `api/types.test.ts` failed (`isRetryable` missing). GREEN: `vendor/bin/phpunit` OK (275 tests, 963 assertions); `npx vitest run` 51 passed; `vue-tsc` OK.

### T4 — copy logs + bash highlighting (commit d3c40e5)
- Route: delegated (2 pure modules + UI wiring).
- Copy: `lib/logCopy.ts` `formatLogForCopy(lines)` → `HH:MM:SS  <text>\n` per line, `[stderr] ` prefix for stderr; command lines keep the runner's `$ ` prefix (multi-line commands keep their newlines). `RunView` passes `visibleLines` (all steps or the focused step) to `CopyButton` as a function, so the text is built at click time (works while live). `lib/clipboard.ts` `copyText()`: Clipboard API, fallback hidden textarea + `execCommand('copy')`, resolves false when both fail; `CopyButton` shows Copied / Copy failed for 1.6 s with a polite live region. The button has an explicit accessible name ("Copy the whole log" / "Copy the log of step N").
- Highlighting: `lib/bashHighlight.ts` lossless tokenizer (tokens always join back to the input; never throws/stalls) with types keyword, command (first word of a simple command, incl. after `&& || | ; ( $(` and leading keywords), string (single, double with escapes, `$'..'`, multi-line), variable (`$X`, `${X}`, specials, `$(`/`)` markers with the inside tokenized; inside double quotes `$(...)` is one variable token; backticks), operator (separators, redirections incl. `2>&1`, `>>`, `<`, heredoc `<<`), comment (`#` at word start only), flag, number, assignment `NAME=` as variable. Heredoc bodies are one string token. Rendered as `<span class="sh-*">{{ text }}</span>` (no `v-html`), each line tokenized once (cache by line id).
- Palette decision: the log keeps a dark background in both OS themes, so one set of `--sh-*` tokens in `:root` serves both (not duplicated in the light block): keywords violet, commands teal (brand), strings green, variables cyan, operators pink, flags blue, numbers amber, comments muted italic. Contrast on `--log-bg`: ≥ 9:1, comments 5.7:1.
- RED: `bashHighlight.test.ts` (17 cases: quotes with escapes, nested `$(...)`, heredoc, `#` inside strings/words, lossless on malformed input) and `logCopy.test.ts` failed (modules missing). GREEN: all pass; `npx vitest run` 71 passed; `vue-tsc` OK.

## Final verification (built phar, headless Chrome)
- `DEPLOYER_VERSION=0.4.0 build/build.sh --skip-frontend` → `dist/deployer.phar` 0.4.0; `php dist/deployer.phar about` → `Deployer 0.4.0`, Schema 5.
- Scratch site: phar as `index.php` under `php -S`, separate `DEPLOYER_DATA_DIR`; playwright-core in a scratch dir driving system Chrome (not added to the repo). All checks PASS:
  - webhook 202 with token; confirm dialog `role=alertdialog`, danger focus on Cancel, Esc cancels + focus returns to "Delete project", Enter on Cancel cancels, Tab trap cycles, backdrop click cancels; revoke via Tab+Enter → "No token" → webhook `401 invalid_token` (curl-equivalent fetch).
  - failing run: command lines rendered with 9 token classes; Copy (all) = timestamps, `$ ` commands, `[stderr]` lines; Copy (focused step 2) = only step 2 (6 lines); without the Clipboard API the fallback reported "Copied".
  - Retry → navigates to `/runs/2`, "Retry of Run #1" link, API `trigger=retry`, `retry_of=1`; mobile delete-project confirm via Tab+Enter deletes and goes to `/projects`.
- Screenshots (desktop 1440 dark + light modal, mobile 375 light): `/tmp/claude-1000/-home-webcloster-dev-Development-Repos-PRODUCTIVITY-deployer-compiled/7a5f203f-571e-473a-b75e-14c312bb1b53/scratchpad/ui-feedback-2/`.
- Note: the first scratch pipeline failed validation (`file: ${f}` parsed as a YAML map, then `${f}` as an unknown pipeline variable — needs `$${f}`); fixed in the scratch YAML only, not a product bug.

## Next step
T5.
