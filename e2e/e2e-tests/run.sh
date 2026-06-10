#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# run.sh - Orchestrate the openapi-laravel e2e Playwright suite.
#
# Usage:
#   ./run.sh              - bring up stack, run tests, tear down
#   ./run.sh --no-docker  - run tests against an already-running stack
#
# Environment variables:
#   SKIP_DOCKER=1         - same as --no-docker (skip compose up/down)
#   BACKEND_URL           - override backend health-check URL (default http://localhost:8088/up)
#   FRONTEND_URL          - override frontend health-check URL (default http://localhost:8080)
#
# Exit code mirrors the Playwright test exit code.
# ---------------------------------------------------------------------------

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="$(cd "${SCRIPT_DIR}/.." && pwd)/docker-compose.yml"

BACKEND_URL="${BACKEND_URL:-http://localhost:8088/up}"
FRONTEND_URL="${FRONTEND_URL:-http://localhost:8080}"
SKIP_DOCKER="${SKIP_DOCKER:-0}"

if [[ "${1:-}" == "--no-docker" ]]; then
  SKIP_DOCKER=1
fi

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

log() { echo "[e2e] $*"; }

wait_for_url() {
  local url="$1"
  local label="$2"
  local max_attempts=60
  local attempt=0

  log "Waiting for ${label} at ${url} ..."
  while true; do
    attempt=$((attempt + 1))
    if curl -sf --max-time 3 "${url}" > /dev/null 2>&1; then
      log "${label} is up."
      return 0
    fi
    if [[ $attempt -ge $max_attempts ]]; then
      log "ERROR: ${label} did not become healthy after ${max_attempts}s."
      return 1
    fi
    sleep 1
  done
}

# ---------------------------------------------------------------------------
# Stack management
# ---------------------------------------------------------------------------

if [[ "$SKIP_DOCKER" != "1" ]]; then
  log "Starting compose stack (${COMPOSE_FILE}) ..."
  docker compose -f "${COMPOSE_FILE}" up -d --build

  # Register teardown on any exit.
  trap 'log "Tearing down compose stack ..."; docker compose -f "${COMPOSE_FILE}" down --remove-orphans' EXIT
fi

# ---------------------------------------------------------------------------
# Health checks
# ---------------------------------------------------------------------------

wait_for_url "${BACKEND_URL}" "backend"
wait_for_url "${FRONTEND_URL}" "frontend"

# ---------------------------------------------------------------------------
# Install Playwright browsers if not already present
# ---------------------------------------------------------------------------

cd "${SCRIPT_DIR}"

if ! npx playwright --version > /dev/null 2>&1; then
  log "Installing Playwright dependencies ..."
  npm install
fi

log "Installing Chromium browser ..."
npx playwright install --with-deps chromium

# ---------------------------------------------------------------------------
# Run tests
# ---------------------------------------------------------------------------

log "Running Playwright tests ..."
npx playwright test --reporter=list
TEST_EXIT=$?

if [[ "$TEST_EXIT" -eq 0 ]]; then
  log "All tests passed."
else
  log "Tests FAILED (exit code ${TEST_EXIT}). Check playwright-report/ for details."
fi

exit "$TEST_EXIT"
