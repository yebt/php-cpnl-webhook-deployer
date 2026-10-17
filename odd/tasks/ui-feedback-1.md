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
- [x] T2 — UI fixes 2, 3, 5, 6, 8 (alignment, spinner, accent bar, icon, log header overlap). Route: delegated.
- [x] T3 — Run view "All steps" vs focused step mode. Route: delegated.
- [x] T4 — Live sidebar project status. Route: delegated.

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

### T2 — done (route: delegated writer; trigger: 2+ non-trivial files)
- Alignment root cause: `.field` is a grid stretched by its row; with default `align-content` the extra height of a hinted neighbour was distributed between label and input, pushing Name's input down. Fix: `.field { align-content: start }` + shared `.form-row` (`align-items: start`) in main.css (ProjectForm uses it; other forms are single-column).
- Spinner: new `AppSpinner.vue` (16-unit viewBox, faint full track + `pathLength`-dashed arc, stroke kept ~1.75-2 device px at any size). Used by StatusBadge, StatusIcon (steps + sidebar) and UpdatePanel; the old `spinner` path icon was removed (`statusIcon.running` falls back to `circle`, never rendered).
- Accent bars removed (sidebar active nav, selected step) → new `--selected-bg` token fill (dark #20252b / light #e6eaef), stronger than hover.
- Key icon: the path lacked the shaft segments joining ring and teeth; replaced with a fully connected path. Icon sheet audit (all icons at 32/15 px) showed no other gaps. Global `svg { flex-shrink: 0 }` prevents clipping in flex rows.
- Log header overlap: reproduced — when the log is scrolled (follow-to-bottom or jump to step) the topmost line is cut at the header edge. Decision: a top fade (`.log-wrap.scrolled::before`) shown only while scrolled; padding unchanged at the top. Conservative: no scroll-snap (would fight follow mode).
- Tests: no meaningful deterministic RED for pure CSS/SVG changes (exception); checks: `npx vue-tsc --noEmit` OK, `npx vitest run` 26 passed, build OK; headless screenshots `t2-*` (form aligned, badge ring continuous at DPR3, key complete, sidebar/steps without bars, faded scrolled log).
- Commit: e2b38b2 `fix(ui): align form rows, unify the spinner and quiet selected states`.

### T3 — done (route: delegated writer; trigger: 2+ non-trivial files)
- Pure logic in `frontend/src/lib/logSteps.ts` (`linesForStep`, `groupLinesByStep`, `parseStepQuery`, `stepQueryValue`); the runner already tags header/command/output/result lines with the 0-based step position.
- Decisions: `?step=N` is **1-based** (matches "Step N/M"); out-of-range/malformed → all steps. URL updated with `router.replace` (no history entries). `scrollBehavior` now keeps the scroll position on query-only navigation (it used to jump to top). Clicking the selected step again does not toggle (explicit "All steps" entry in the step list, "All steps · Esc" button in the log header, Esc key).
- Focused view: log header shows status + step name + "Step N of M", other steps dimmed (opacity .6), steps column narrows, taller log; a running focused step keeps following live output, a finished one opens at its top. Stacked (≤900px) layout scrolls the log card into view. The old "scroll to step start in the full log" behavior was replaced by the focused view.
- RED: `npx vitest run src/lib/logSteps.test.ts` → failed (module missing). GREEN: 7 passed; full `npx vitest run` 33 passed; `vue-tsc` OK; build OK.
- Headless: focused running step grew 25 → 33 lines while focused; Esc → URL without `step`, title "Log"; reload `?step=2` → focused "Build". Screenshots `t3-*` desktop + mobile.
- Commit: 8c9e4aa `feat(runs): focus the run log on a single step`.

### T4 — done (route: delegated writer; trigger: 2+ non-trivial files)
- No new endpoint: the sidebar keeps using `GET /api/runs?limit=40`. New `useAdaptivePolling` composable (3s while any recent run is queued/running, 30s otherwise, paused while the tab is hidden with an immediate load on return, `refresh()` serialized with in-flight loads, scope-disposed). New `runEvents` bus: RunView emits when its log stream sees active → terminal; sidebar refreshes on it and on route change.
- Scope note: the Projects list "Last run" badge had the same staleness; it now uses the same poller + event (same root cause, same files area). Runs triggered externally (webhook) while idle appear within ≤30s by design.
- RED: `npx vitest run src/composables/useAdaptivePolling.test.ts` → failed (module missing). GREEN: 8 passed; full suite 41 passed; `vue-tsc` OK; build OK.
- Headless (built phar): from /projects, sidebar showed Running 80 ms after load and flipped to Success without navigation (URL unchanged); on the run page the sidebar showed Success 4 ms after the badge. Screenshots `t4-*` desktop + mobile.
- Commit: ba088fa `feat(ui): keep recent project statuses live`.

### Final verification
- `vendor/bin/phpunit` → OK (231 tests, 816 assertions); `php -l` over 165 PHP files → 0 errors.
- `npx vitest run` → 6 files, 41 tests passed; `npx vue-tsc --noEmit` OK; `npm run build` OK.
- `DEPLOYER_VERSION=0.2.0 build/build.sh --skip-frontend` → dist/deployer.phar 0.2.0 (43 web assets); `php dist/deployer.phar about` → "Deployer 0.2.0".
- Headless Chrome against the built phar (`php -S`): new project form, notifier form with thread id (validation error + saved 42 + edit prefill), run page running, focused step, sidebar before/after finish — desktop 1440 and mobile 375.

## Next step
Parent: native review per RDD, then fast-forward `main`, tag `v0.2.0`, push (user decision).
