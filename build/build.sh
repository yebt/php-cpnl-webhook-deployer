#!/usr/bin/env bash
# Builds the release artifact: dist/deployer.phar (upload it as index.php) and the
# optional dist/.htaccess for clean URLs.
#
#   build/build.sh                    build the web UI, then the phar
#   build/build.sh --skip-frontend    reuse an existing frontend/dist
#   build/build.sh --version=1.2.3    stamp this version (default: $DEPLOYER_VERSION,
#                                     else the exact `vX.Y.Z` git tag of HEAD, else `dev`)
#
# Needs: PHP >= 8.2 CLI with phar, composer, and (unless skipped) Node + npm.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
skip_frontend=0
phar_args=()

for arg in "$@"; do
    case "$arg" in
        --skip-frontend) skip_frontend=1 ;;
        --version=*) phar_args+=("$arg") ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

if [[ "$skip_frontend" -eq 0 ]]; then
    echo "==> Building the web UI (frontend/dist)"
    (cd "$root/frontend" && npm ci --no-audit --no-fund && npm run build)
fi

echo "==> Building the phar"
php -d phar.readonly=0 "$root/build/build-phar.php" "${phar_args[@]+"${phar_args[@]}"}"
