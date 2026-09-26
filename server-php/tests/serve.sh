#!/usr/bin/env bash
# Bring the whole product up locally, on ONE origin, for browser verification.
#
#   server-php/tests/serve.sh          # http://127.0.0.1:8793
#
# What it starts:
#   * the stub from tests/stub/router.php, standing in for the auth portal,
#     Manage, Books, Inventory and the operational products
#   * this API, mounted at /api, pointed at the stub and at a throwaway database
#   * the BUILT React app (web/dist), served from the same origin with a history
#     fallback — which is exactly how the product is deployed
#
# WHAT THIS IS NOT. A live-service check. Every figure it shows comes from the
# stub, so it proves the app's own behaviour — layout, states, permissions,
# stale-scope handling, exports — and proves nothing about production data. Live
# verification is a separate exercise and is reported separately.
#
# Requires: php with pdo_pgsql and zip, a reachable PostgreSQL, and a built web/dist.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_DIST="$(cd "$ROOT/.." && pwd)/web/dist"

PORT="${SERVE_PORT:-8793}"
STUB_PORT="${STUB_PORT:-8794}"
DB_NAME="${DEV_DB_NAME:-insights_dev}"
DB_USER="${DEV_DB_USER:-insights_test}"
DB_PASS="${DEV_DB_PASS:-insights_test}"
DB_HOST="${DEV_DB_HOST:-127.0.0.1}"
DB_PORT="${DEV_DB_PORT:-5432}"

if [ ! -f "$WEB_DIST/index.html" ]; then
  echo "web/dist is not built. Run: (cd web && npm run build)" >&2
  exit 1
fi

cat > "$ROOT/.env.dev" <<ENVEOF
APP_ENV=local
APP_PRODUCT_KEY=insights
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
PORTAL_AUTH_BASE=http://127.0.0.1:$STUB_PORT
BOOKS_API_BASE=http://127.0.0.1:$STUB_PORT
INVENTORY_API_BASE=http://127.0.0.1:$STUB_PORT
MANAGE_API_BASE=http://127.0.0.1:$STUB_PORT
SALES_API_BASE=http://127.0.0.1:$STUB_PORT
PURCHASES_API_BASE=http://127.0.0.1:$STUB_PORT
BILLING_API_BASE=http://127.0.0.1:$STUB_PORT
POS_API_BASE=http://127.0.0.1:$STUB_PORT
ENVEOF

# The dev database is separate from the test one on purpose: the integration
# suite truncates as it goes, and a browser session half way through a dashboard
# should not have the table pulled out from under it.
cp "$ROOT/.env.dev" "$ROOT/.env"
php "$ROOT/bin/migrate.php" > /dev/null

php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /tmp/insights-stub.log 2>&1 &
STUB_PID=$!
php -S "127.0.0.1:$PORT" "$ROOT/tests/front-router.php" > /tmp/insights-serve.log 2>&1 &
SERVE_PID=$!
trap 'kill $STUB_PID $SERVE_PID 2>/dev/null || true' EXIT

for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$PORT/api/health" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

echo "Insights is serving on http://127.0.0.1:$PORT"
curl -sS --noproxy '*' "http://127.0.0.1:$PORT/api/health"
echo
echo "Sign in as: auth-uuid-owner-1 | auth-uuid-colleague-2 | auth-uuid-restricted-3"
echo "Ctrl-C to stop."
wait $SERVE_PID
