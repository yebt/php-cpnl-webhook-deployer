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
- [ ] T3 — Retry failed/cancelled runs (migration if needed, API, UI, tests). Route: delegated.
- [ ] T4 — Copy logs + bash highlighting for command lines. Route: delegated.
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

## Next step
T3.
