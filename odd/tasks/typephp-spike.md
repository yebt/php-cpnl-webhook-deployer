# Feature: typephp-spike

## Objective
Validate that TypePHP (swoole/typephp) can produce a single binary that covers the critical runtime needs of the deployer before designing the full system.

## Problem / Why
The deployer must run on the target host as a compiled binary. Its core depends on process execution (`symfony/process`: proc_open, live stdout/stderr, signals for cancellation), YAML parsing (`symfony/yaml`) and an HTTP server with live streaming. TypePHP docs do not confirm proc_open/signals or embedded Swoole support in compiled binaries, and it compiles only a PHP subset. A failed assumption here would invalidate the whole architecture.

## Scope
- Containerized (Podman) build environment: ZTS PHP 8.4/8.5 with headers, php-config, embed libphp, CMake, GCC, GMP, MPFR, Composer, TypePHP.
- Minimal spike app compiled with TypePHP, vendor embedded.
- Checks: YAML parsing, process streaming + cancellation, HTTP server with live output streaming and a cancel endpoint, two concurrent runs.
- Findings report with go / no-go and fallback recommendation (static-php-cli / swoole-cli + phar).

## Out of scope
Real deployer features (auth, projects, Telegram, queues, UI).

## Constraints
- Do not modify the host PHP (php.new NTS musl build); everything builds inside a container.
- Components only (symfony/process, symfony/yaml, symfony/var-dumper), no Symfony framework.
- `--nano` mode is excluded (forbids process execution).

## Delivery strategy
ask-on-risk. Forecast: ~300 authored changed lines (spike code + Containerfile + report).

## Tasks
- [x] T1 — Build environment: Containerfile + script; `tpc` compiles and runs a hello-world binary. Route: delegated (research + multi-file).
- [x] T2 — YAML: compiled binary parses a YAML with a `vars` block and interpolates into steps. Route: delegated.
- [x] T3 — Process: compiled binary runs a command via symfony/process, streams stdout/stderr live with timestamps, cancels mid-run (SIGTERM then SIGKILL), reports exit code. Route: delegated.
- [x] T4 — HTTP: compiled binary serves HTTP (Swoole ext or alternative), streams a run's output live (SSE/chunked), cancel endpoint works, two runs execute concurrently. Route: delegated.
- [x] T5 — Findings report `docs/spike-typephp.md`: what worked, what failed (exact errors), binary size/runtime deps, go/no-go. Route: delegated.

## Acceptance criteria
Each check is reproducible with one documented command; outputs observed, not assumed. Failures recorded with exact error text.

## Checks
Test-first exception: this is an exploratory spike with no existing test runner; each task's check is a reproducible run of the compiled binary with observed output.

## Progress / Evidence
- T1 PASS. Image `localhost/typephp-spike` = `php:8.4-zts` (Debian 13, PHP 8.4.26 ZTS, `--enable-embed`, `/usr/local/lib/libphp.so`, OPcache) + build-essential/cmake/GMP/MPFR + Composer 2 + TypePHP @ 89fe80a + PHPX v2.9.4 (`libphpx.so` built with CMake). `spike/build.sh hello` output: `Hello from TypePHP!` / `PHP_VERSION=8.4.26 ZTS=1`. Binary 52,504 bytes, dynamically linked to `libphpx.so` + `libphp.so` (+ ~40 system libs). Commit: 4c15c84.

- T2 PASS. `spike/build.sh yaml`: binary (3,870,192 bytes, `embedded-files: vendor`, 163 files / 133 opcode blobs; symfony/yaml v7.4.20) run from an empty dir without vendor/ prints `step 1 [fetch]: git -C /srv/myapp fetch origin main` and keeps unknown `${NOT_DEFINED}`. Closures with `use`, `preg_replace_callback`, `Yaml::parseFile` all work.
- T3 PASS. `spike/build.sh process` (symfony/process v7.4.19 as embedded bytecode, proc_open in compiled binary). A: lines streamed live every ~0.5s with `[stdout]`/`[stderr]` tags, `exitCode=3`. B: cancel at 2s -> `exitCode=143 signaled=yes termSignal=15` (2.06s). C: child traps TERM -> SIGKILL after 2s grace, run ends at 4.06s with `exitCode=-1 signaled=no`; interpreted PHP 8.4 in the same image gives the identical result (Symfony `stop()` closes via proc_close after SIGKILL), so it is not a TypePHP defect -- the deployer must record "cancelled" itself. Note: output produced during the blocking `stop()` grace period is flushed late. Commit 62ce8f2.
- T4 PASS (with caveat). `spike/build.sh http` + `spike/http-check.sh`: Swoole 6.2.3 HTTP server (SWOOLE_BASE, 1 worker, coroutines, `SWOOLE_HOOK_ALL`) inside the compiled binary; two runs started via POST, both SSE streams interleave live every ~0.5s, `POST /runs/run2/cancel` at +2s -> `exitCode=143 signaled=yes termSignal=15 cancelled=yes` while run1 continues to `exitCode=0`; unknown run -> 404. Caveat: Swoole is NOT inside the binary; it is the shared `swoole.so` loaded through `/usr/local/etc/php/conf.d`. With `PHP_INI_SCAN_DIR=` the server dies: `Fatal error: Uncaught Error: class 'Swoole\Http\Server' is undefined`. Subset issues hit: (1) tpc crash `Fatal error: The variable `$run` is undefined` from LoopVarOptimizer for a `while` loop using `count($run->lines)` where `$run` was assigned inside an enclosing `if` -> workaround: move the loop into its own function; (2) runtime `ArgumentCountError: stdClass::{closure}() expects exactly 0 arguments, 1 given` for Swoole `on('start')` callback -> TypePHP strict arity, declare every callback argument.
- Extra (bounded): php-builder static runtime FAIL. Static Swoole link error `undefined reference to 'swoole_module_entry'` (only Swoole thirdparty objects built); without Swoole, the static PHP builds but a later requirement lookup fails with `curl: (22) ... 404`. Earlier attempts hit a nested `:Z` bind-mount tar permission error (fixed with a named volume) and missing oniguruma for mbstring (dropped).
- T5 PASS. `docs/spike-typephp.md` written. Recommendation: NO-GO for TypePHP packaging; fallback static-php-cli micro + phar (preferred) or swoole-cli + phar. Commit 584cc41 = T4; T5 commit recorded in the final report.

## Next step
Validate the fallback (static-php-cli micro.sfx + phar) against the same four checks.
