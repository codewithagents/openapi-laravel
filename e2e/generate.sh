#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# generate.sh - Regenerate the mechanical layer of the e2e demo from the spec.
#
# This demo behaves like a real consumer of the two generators: the business
# logic is versioned, the generated layer is NOT. This script reproduces that
# generated layer fresh, exactly as CI does, before the stack is built.
#
#   Backend  (e2e/backend):  composer install + `php artisan openapi:generate`
#                            -> app/Data/**, Abstract*Controller.php, routes/api.generated.php
#   Frontend (e2e/frontend): pnpm install + `pnpm gen`
#                            -> src/api/** (the whole openapi-zod-ts client)
#
# Both read the single source of truth: e2e/spec/petstore.yaml.
#
# Usage:
#   ./generate.sh            - regenerate backend and frontend
#
# Run it from a clean checkout where the generated files do not exist yet:
# they will be created. Re-running is idempotent (deterministic generators).
# ---------------------------------------------------------------------------

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="${SCRIPT_DIR}/backend"
FRONTEND_DIR="${SCRIPT_DIR}/frontend"

log() { echo "[generate] $*"; }

# ---------------------------------------------------------------------------
# Backend: the generator is a require-dev dep behind a composer PATH repo
# (../.., symlinked). We MUST install WITH dev so it resolves, then run the
# command with --controllers --routes (the e2e config disables both, so the
# flags are required to force-enable the server scaffold).
# ---------------------------------------------------------------------------

log "Backend: installing composer dependencies (with dev, so the generator resolves) ..."
( cd "${BACKEND_DIR}" && composer install --no-interaction --no-progress )

log "Backend: generating Data classes, abstract controllers, and routes ..."
( cd "${BACKEND_DIR}" && php artisan openapi:generate --controllers --routes )

# Link public/storage -> storage/app/public so uploaded images served at
# /storage/uploads/<file> work in a local (non-docker) `run.sh --no-docker`
# flow too. The docker image does the same at container start. Harmless and
# idempotent; --force replaces any stale link.
log "Backend: linking the public storage disk (for uploaded image serving) ..."
( cd "${BACKEND_DIR}" && mkdir -p storage/app/public/uploads && php artisan storage:link --force )

# ---------------------------------------------------------------------------
# Frontend: openapi-zod-ts is a devDependency; install then run the `gen`
# script, which writes the whole client into src/api.
# ---------------------------------------------------------------------------

log "Frontend: installing pnpm dependencies ..."
if ! ( cd "${FRONTEND_DIR}" && pnpm install --frozen-lockfile ); then
  log "Frontend: frozen lockfile rejected, retrying with a plain install ..."
  ( cd "${FRONTEND_DIR}" && pnpm install )
fi

log "Frontend: generating the openapi-zod-ts client into src/api ..."
( cd "${FRONTEND_DIR}" && pnpm gen )

log "Done. Generated backend (app/Data, Abstract*Controller, routes/api.generated.php) and frontend (src/api)."
