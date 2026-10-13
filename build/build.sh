#!/usr/bin/env bash
# Builds the release artifact: dist/deployer.phar (upload it as index.php) and the
# optional dist/.htaccess for clean URLs.
#
#   build/build.sh                  build the web UI, then the phar
#   build/build.sh --skip-frontend  reuse an existing frontend/dist
#
# Needs: PHP >= 8.2 CLI with phar, composer, and (unless skipped) Node + npm.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ "${1:-}" != "--skip-frontend" ]]; then
    echo "==> Building the web UI (frontend/dist)"
    (cd "$root/frontend" && npm ci --no-audit --no-fund && npm run build)
fi

echo "==> Building the phar"
php -d phar.readonly=0 "$root/build/build-phar.php"
