#!/usr/bin/env bash
# TypePHP spike driver. Everything runs inside the Podman build image; the host
# PHP is never used.
#
#   spike/build.sh image          build the container image
#   spike/build.sh hello          compile + run spike/hello
#   spike/build.sh compile        composer install + compile spike/app
#   spike/build.sh yaml           compile + run spike/app (yaml check)
#   spike/build.sh process        compile + run spike/app (process check)
#   spike/build.sh http           compile spike/app and exercise the HTTP check
#   spike/build.sh shell          interactive shell in the image
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE="${IMAGE:-localhost/typephp-spike:latest}"
JOBS="${JOBS:-4}"

run() {
    podman run --rm ${PODMAN_TTY:-} -v "$HERE:/work:Z" -w /work "$IMAGE" bash -c "$1"
}

case "${1:-}" in
    image)
        podman build -t "$IMAGE" -f "$HERE/Containerfile" "$HERE"
        ;;
    hello)
        run "set -e; cd hello && tpc.php hello.php --build-dir /work/.build/hello -O2 -j $JOBS --no-progress --no-color --php-version=8.4 -o hello \
             && ./hello && ls -l hello && ldd hello"
        ;;
    deps)
        run "cd app && composer install --no-dev --classmap-authoritative --no-progress"
        ;;
    compile)
        run "set -e; cd app && test -d vendor || composer install --no-dev --classmap-authoritative --no-progress; \
             tpc.php project.yml -j $JOBS --no-progress --no-color -o deployer && ls -l deployer"
        ;;
    yaml)
        "$0" compile
        # Run from an empty directory to prove vendor/ is not read from disk.
        run "set -e; mkdir -p /tmp/rt && cp app/deployer app/pipeline.yml /tmp/rt/ && cd /tmp/rt && ./deployer yaml pipeline.yml"
        ;;
    process)
        "$0" compile
        run "set -e; mkdir -p /tmp/rt && cp app/deployer /tmp/rt/ && cd /tmp/rt \
             && echo '== A: run to completion ==' && ./deployer process 0 0 \
             && echo '== B: cancel at 2s, child honours SIGTERM ==' && ./deployer process 2 0 \
             && echo '== C: cancel at 2s, child ignores SIGTERM -> SIGKILL ==' && ./deployer process 2 1"
        ;;
    shell)
        PODMAN_TTY=-it run bash
        ;;
    *)
        sed -n '2,12p' "$0"; exit 1
        ;;
esac
