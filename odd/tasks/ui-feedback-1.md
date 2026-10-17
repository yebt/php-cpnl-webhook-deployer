# Feature: ui-feedback-1

## Objective
Apply the first round of user feedback from the real cPanel test (deploy.menukap.com) and ship it as release `v0.2.0`.

## Problem / Why
The PHP deployer works on the host (runs, queue, live logs). The user reported functional gaps and visual defects.

## Scope (user feedback)
1. Telegram notifiers support an optional **thread id** (`message_thread_id`, forum topics) — DB migration, API, UI field, sent in sendMessage and in the test message.
2. **Form alignment**: in "New project" the Name input sits lower than the Slug input in the same row (labels/inputs misaligned). Align every form grid (projects, notifiers, setup/login) so labels, inputs and hints line up per row.
3. **Status badge spinner** ("Running" pill) renders as a broken/dashed circle; the spinner used in the step list renders correctly. Use one shared, correctly drawn spinner everywhere.
4. **Run view focus mode**: keep the current full view (all steps + whole log) behind an "All steps" control; clicking a step switches to a focused "zen" view of only that step (its header, command, output, result), with an obvious way back to all steps. Live updates keep working in both modes; a running step can be focused.
5. **Remove the left accent bar** on active/selected items (sidebar nav active item, selected step, and any similar left-border highlight). Use a subtle background highlight instead.
6. **Key icon clipped** in the "Generate token" button — fix the icon so it renders completely (and check other icons for the same clipping).
7. **Sidebar recent projects status is stale**: the status indicators only refresh after navigation. They must update live (poll while any project has a queued/running run, and refresh when a run finishes).
8. Minor: the first log line is partially hidden under the log card header (see screenshot) — make sure the log body starts below the header.

## Out of scope
Backend run engine changes beyond the notifier thread id.

## Constraints
- Generated artifacts in English. Conventional commits, no AI attribution.
- Work on `feat/ui-feedback-1` from `main`; fast-forward `main`, tag `v0.2.0`, push (push may fail without SSH key in this session; then hand the commands to the user).

## Tasks
- [x] T1 — Notifier thread id (backend + API + UI + tests). Route: delegated.
- [ ] T2 — UI fixes 2, 3, 5, 6, 8 (alignment, spinner, accent bar, icon, log header overlap). Route: delegated.
- [ ] T3 — Run view "All steps" vs focused step mode. Route: delegated.
- [ ] T4 — Live sidebar project status. Route: delegated.

## Checks
PHPUnit, vitest, vue-tsc, frontend build, phar build, headless browser screenshots of the changed screens (desktop + mobile).

## Progress / Evidence
### T1 — done (route: delegated writer; trigger: 2+ non-trivial files, backend + UI)
- Decision: `thread_id` is stored in the existing JSON `notifiers.config` column (where per-type Telegram settings already live), so **no schema migration** was needed; old rows without the key read as `null`. Recorded as a deliberate deviation from the "new migration" note.
- API: `thread_id` accepts a positive integer, digit string, `null` or `""` (clears); missing on update keeps the stored value; anything else → 422 `thread_id`. Responses include `thread_id` (int|null). Masked token behavior unchanged.
- Telegram adapter sends `message_thread_id` only when set (run-finish and test messages share the adapter).
- UI: "Thread ID (optional)" field with a forum-topic hint; notifier list shows `topic N`. README Telegram section updated.
- RED: `vendor/bin/phpunit --filter 'Thread|ForumTopic'` → 5 tests, 4 failures (missing `thread_id` / `message_thread_id`).
- GREEN: `vendor/bin/phpunit` → OK (231 tests, 816 assertions); `npx vue-tsc --noEmit` OK; `npx vitest run` 26 passed.
- Commit: a6f77f3 `feat(notifiers): support optional Telegram forum topic thread id`.

## Next step
T2.
