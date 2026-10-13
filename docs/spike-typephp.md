# Spike: TypePHP as the deployer's single-binary runtime

Date: 2026-10-13 · Branch: `spike/typephp-validation` · Feature doc: `odd/tasks/typephp-spike.md`

## Question

Can [swoole/typephp](https://github.com/swoole/typephp) (AOT PHP -> C++ -> native)
produce **one binary** that covers the deployer's critical runtime needs:
`symfony/process` (proc_open, live stdout/stderr, signal-based cancellation),
`symfony/yaml`, and an HTTP server that streams live output, cancels runs and
handles concurrent runs?

## Short answer

**Functionally yes, as a single deployable binary: not with the default build.**
Every runtime check passed inside the compiled binary (YAML, process
streaming + cancellation, Swoole SSE server with concurrent runs and cancel).
But the default `mode: bin` + `sapi: embed` output is a 4 MB executable that
dynamically links `libphp.so` (20 MB), `libphpx.so` (1 MB) and ~45 system
libraries, and loads Swoole as a separate 50 MB `swoole.so` through `php.ini`.
See [Static runtime attempt](#static-runtime-attempt-php-builder) for the
attempt to remove those dependencies.

## Environment

| Item | Value |
|---|---|
| Host | Fedora 42, x86_64, Podman 5.8.2 (host PHP untouched) |
| Image base | `docker.io/library/php:8.4-zts` (Debian 13), PHP 8.4.26 ZTS, configured with `--enable-embed --enable-zts`, ships `/usr/local/lib/libphp.so`, `php-config`, headers, OPcache |
| Extra packages | build-essential, cmake, pkg-config, libgmp-dev, libmpfr-dev, Composer 2, `pcntl` (docker-php-ext-install) |
| TypePHP | `master` @ `89fe80aae27938c08b59dfa1c3cbbb90a96c1507` (latest tag is v0.9.5) |
| PHPX | `swoole/phpx` v2.9.4 from TypePHP's Composer deps; `libphpx.so` built with CMake (same flags as TypePHP's Linux CI) |
| Swoole | 6.2.3 via `pecl install` (shared extension) |
| Components | symfony/process v7.4.19, symfony/yaml v7.4.20, symfony/var-dumper v7.4.18 (see `spike/app/composer.lock`) |

The `php_hash.h` C++ patch that TypePHP's CI applies was not needed: the
8.4.26 header already contains the upstream fix.

## Reproduce

```bash
spike/build.sh image     # build localhost/typephp-spike (~5 min; Swoole pecl build dominates)
spike/build.sh hello     # T1: compile + run spike/hello/hello.php
spike/build.sh yaml      # T2: compile spike/app, run `deployer yaml pipeline.yml`
spike/build.sh process   # T3: three process scenarios
spike/build.sh http      # T4: spike/http-check.sh against the compiled server
spike/build.sh static    # experimental php-builder static runtime
```

All compile and run steps execute in the container. Every compiled binary is
copied to an empty `/tmp/rt` before running, so `vendor/` is never read from disk.
`spike/app/project.yml` compiles `main.php` AOT and embeds `vendor/` as OPcache
bytecode (`embedded-files: vendor`).

## Results

| Check | Result | Evidence |
|---|---|---|
| T1 toolchain + hello world | **PASS** | `Hello from TypePHP!` / `PHP_VERSION=8.4.26 ZTS=1`; 52,504-byte binary |
| T2 YAML + var interpolation | **PASS** | `step 1 [fetch]: git -C /srv/myapp fetch origin main`; unknown `${NOT_DEFINED}` kept |
| T3 process streaming + cancel | **PASS** | live lines every ~0.5 s; SIGTERM: `exitCode=143 signaled=yes termSignal=15`; SIGKILL escalation after a 2 s grace |
| T4 HTTP SSE + cancel + concurrency | **PASS (caveat)** | two SSE streams interleave live; cancel gives `exitCode=143 ... cancelled=yes` while the other run ends with `exitCode=0`; Swoole loaded from `php.ini`, not inside the binary |
| Single self-contained binary | see below | default build is **not** self-contained |

### T1 — toolchain

`tpc.php hello.php -O2 --php-version=8.4` works out of the box once PHPX is
built. Pass `--php-version=8.4` (or set it in YAML) because the default is 8.5.
The first build compiles a PCH. Keep `--build-dir` persistent (`spike/.build/`)
to get incremental builds.

### T2 — YAML

`Yaml::parseFile()` from embedded bytecode, `preg_replace_callback` with a
`use` closure, and `json_encode` all behave like interpreted PHP.
Build log: `embedded-files: found 163 files (133 PHP)`, `Packed 163 files and 133 opcode blobs`.
App binary: 3,870,192 bytes.

### T3 — process

`spike/build.sh process` runs `bash -c` loops that echo to stdout and stderr every 0.5 s:

- A, run to completion: every line arrives within ~0.5 s with an `[stdout]`/`[stderr]` tag and a ms timestamp; `exitCode=3`.
- B, cancel at 2 s while the child honours SIGTERM: `finished after 2.06s exitCode=143 signaled=yes termSignal=15`.
- C, cancel at 2 s while the child traps SIGTERM: SIGKILL arrives after exactly 2 s of grace (`finished after 4.06s`). Symfony reports `exitCode=-1 signaled=no`.
  Interpreted PHP 8.4 in the same image gives **the identical result**:
  Symfony's `stop()` calls `proc_close()` right after SIGKILL. This is a Symfony
  quirk, not a TypePHP defect. The deployer must record "cancelled/killed" itself.
- Gotcha (my bug, not TypePHP's): `Process::stop($timeout, $signal)` always
  sends SIGTERM first. `$signal` is the signal sent *after* the grace period,
  so pass `SIGKILL`.
- Output produced while `stop()` blocks during the grace period is flushed late.
  A real implementation should signal and poll instead of calling the blocking `stop()`.

### T4 — HTTP

Swoole `Http\Server` runs inside the compiled binary with these settings:
`SWOOLE_BASE`, 1 worker, coroutines, `hook_flags = SWOOLE_HOOK_ALL`.
Symfony Process runs inside coroutines because `proc_open`, `stream_select` and
`usleep` are hooked. The endpoints are `POST /runs`, `GET /runs/{id}/stream`
(SSE) and `POST /runs/{id}/cancel`.

`spike/http-check.sh` starts two runs, streams both with `curl -N`, and cancels
run2 at +2 s. Client-side arrival stamps show both streams interleaving every
~0.5 s. Run2 ends at +2.1 s with `exitCode=143 signaled=yes termSignal=15 cancelled=yes`.
Run1 continues to `exitCode=0`. Unknown run: 404.

**Caveat:** Swoole is loaded as a shared extension through
`/usr/local/etc/php/conf.d/docker-php-ext-swoole.ini`. The embed SAPI reads the
normal `php.ini` scan dir. With `PHP_INI_SCAN_DIR=` the server fails:

```
Fatal error: Uncaught Error: class 'Swoole\Http\Server' is undefined in /work/app/main.php:239
```

### TypePHP subset limitations hit

1. **Compiler crash** in `LoopVarOptimizer`:
   `Fatal error: The variable `$run` is undefined in /work/app/main.php:184`.
   It happens for a `while` loop using `count($run->lines)` when `$run` was
   assigned inside an enclosing `if`. Interpreted PHP accepts this.
   Workaround: move the loop into its own function (`streamRun()`).
2. **Strict callback arity at runtime**:
   `ArgumentCountError: stdClass::{closure}() expects exactly 0 arguments, 1 given`
   for `$server->on('start', function () {...})`. PHP silently drops extra
   closure arguments; TypePHP does not. Declare every argument that an extension
   callback receives.
3. Known by design (docs): global scope is declaration-only, `main()` must be
   `void`, and no `$$var`, `Closure::bind`, or `declare(ticks)`. Inferred
   int/float/bool locals cannot change type. None of these were a problem for
   the spike code. Vendor code runs as embedded ZendVM bytecode, so these rules
   apply only to `sources`.

## Binary size and runtime dependencies (default embed build)

| Artifact | Size |
|---|---|
| `spike/app/deployer` (not stripped) | 3,995,200 bytes |
| `/usr/local/lib/libphp.so` (required) | 20,089,296 bytes |
| `libphpx.so` (required) | 984,320 bytes |
| `swoole.so` + ini (required for HTTP) | 50,450,160 bytes |

`ldd deployer` lists 47 entries: `libphpx.so`, `libphp.so`, libstdc++, gmp,
mpfr, readline, libxml2, ssl/crypto, sqlite3, curl and its TLS/Kerberos/LDAP
chain, onig, argon2, and others. The rpath is hardcoded to the build paths
`/opt/typephp/vendor/swoole/phpx/lib` and `/usr/local/lib`. The binary is a
glibc (Debian 13) PIE executable.

## Static runtime attempt (php-builder)

**Result: FAIL (bounded, 3 attempts).** TypePHP's `php-builder` can build a
private static PHP from php-src (`--enable-embed=static`, `--disable-all` plus
the listed extensions), which would remove `libphp.so` and the `php.ini`
dependency. The builder downloads php-8.4.26 and PECL `swoole-6.2.3` into
`~/.typephp`.

| Attempt | Config | Outcome |
|---|---|---|
| 1 | `spike/app/project-static.yml` with `~/.typephp` as a nested `:Z` bind mount | `tar: ... Cannot open: Permission denied` / `Unable to prepare self-contained PHP SAPI runtime: Command failed: tar -xJf ...`. This is an environment issue, fixed with a named volume (`typephp-spike-home`). |
| 2 | same, `extensions: [swoole, pcntl, posix, ctype, mbstring, tokenizer]` | `configure: error: Package requirements (oniguruma) were not met`. mbstring needs `libonig-dev`; dropped it because `symfony/polyfill-mbstring` covers it. |
| 3 | `extensions: [swoole, pcntl, posix, ctype, tokenizer]` (`spike/build.sh static`) | Link of `sapi/cli/php` fails: `undefined reference to 'swoole_module_entry'`, `'SwooleTG'`, `'swoole_fork(int)'`, `'php_swoole_reactor_init'` ... `collect2: error: ld returned 1 exit status`. Only `ext/swoole/thirdparty/*` was compiled; Swoole's own `ext-src/` and `src/` objects were never built in-tree, so **static Swoole via php-builder does not work** at this commit. |
| 4 | without Swoole, `spike/app/project-static-noswoole.yml` (`spike/build.sh static-noswoole`) | The private static PHP 8.4.26 and PHPX build fine (`Using private PHP 8.4.26 runtime ...`). After converting `main.php`, a second runtime preparation fails with `Unable to prepare self-contained PHP SAPI runtime: ... curl: (22) The requested URL returned error: 404`: an extension requirement inferred from the sources was looked up on PECL and does not exist there. Not investigated further (time-box). |

So this spike produced **no** binary that runs without `libphp.so`/`libphpx.so`.
It also produced no binary that contains Swoole.

## Recommendation

**No-go for TypePHP as the deployer's packaging/runtime today. Go for the
fallback: a static PHP runtime with the app packaged as a phar.**

The PHP semantics the deployer needs work under TypePHP: Symfony Process,
Symfony YAML, Swoole coroutines and SSE. The blocker is **packaging**, which
was the goal:

- The default output is not one binary. It needs `libphp.so` (20 MB),
  `libphpx.so`, ~45 distro shared libraries, a `php.ini` scan dir, and a 50 MB
  `swoole.so`, all at the build-host paths baked into the rpath and tied to the
  exact PHP 8.4.26 ZTS build (embedded opcodes are version-locked). Shipping
  that is the same as shipping a PHP installation.
- The documented route to self-containment (`php-builder`) failed for Swoole at
  link time, and also failed on the no-Swoole variant at this commit.
- TypePHP is pre-1.0 (v0.9.5, master moving daily). It has a compiler crash on
  valid PHP and stricter-than-PHP runtime semantics (closure arity). Adopting it
  means taking on compiler risk for little gain: the deployer is I/O-bound, and
  AOT speed is irrelevant to it.

Fallback, in order of preference (none were tested in this spike; each needs
its own short validation of the same four checks):

1. **static-php-cli (`spc`)**: build a musl-static `micro.sfx` with `swoole`,
   `pcntl`, `posix`, `ctype`, `tokenizer`, `phar`, and concatenate it with the
   app phar (`spc micro:combine`). The result is one static ELF with no runtime
   dependencies. Interpreted PHP gives full compatibility with Symfony
   components and none of the subset issues above.
2. **swoole-cli + phar**: the official static `swoole-cli` binary with the app
   phar beside it (two files), or combined if a micro-style SFX is available.
   This is the fastest to try; it already has Swoole and pcntl.

Revisit TypePHP if a later release fixes static Swoole in `php-builder`. The
code changes needed here were minor: one loop extracted into a function and one
callback signature.
