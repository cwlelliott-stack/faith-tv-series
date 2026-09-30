#!/usr/bin/env bash
# Runs the WordPress test suite against a local test site: the containers from the README's
# "Test locally" recipe (a wordpress:php8.3-apache container with this plugin mounted, plus
# its MySQL). WP-CLI runs in a throwaway wordpress:cli container that shares the site's
# volumes and gets this repository mounted read-only at /repo.
#
#   bash tests/run-docker.sh              # everything
#   bash tests/run-docker.sh cache        # only tests whose name or file contains "cache"
#   FTVS_WP=ftvs3-wp FTVS_DB=ftvs3-db FTVS_NET=ftvs3-net bash tests/run-docker.sh
#
# Settings (all optional): FTVS_WP, FTVS_DB, FTVS_NET, FTVS_CLI_IMAGE, FTVS_DB_USER,
# FTVS_DB_PASSWORD, FTVS_DB_NAME. Set FTVS_STRICT=1 to count known bugs as failures.
set -euo pipefail

WP="${FTVS_WP:-ftvs-wp}"
DB="${FTVS_DB:-ftvs-db}"
NET="${FTVS_NET:-ftvs-net}"
IMAGE="${FTVS_CLI_IMAGE:-wordpress:cli}"

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Docker Desktop on Windows wants C:/Users/... rather than Git Bash's /c/Users/...
if command -v cygpath >/dev/null 2>&1; then
	repo="$(cygpath -m "$repo")"
fi
export MSYS_NO_PATHCONV=1

docker run --rm -i \
	--network "$NET" \
	--volumes-from "$WP" \
	-v "$repo:/repo:ro" \
	-e WORDPRESS_DB_HOST="$DB" \
	-e WORDPRESS_DB_USER="${FTVS_DB_USER:-wp}" \
	-e WORDPRESS_DB_PASSWORD="${FTVS_DB_PASSWORD:-wpdev}" \
	-e WORDPRESS_DB_NAME="${FTVS_DB_NAME:-wp}" \
	-e FTVS_STRICT="${FTVS_STRICT:-}" \
	--user 33:33 \
	"$IMAGE" wp eval-file /repo/tests/run.php "$@"
