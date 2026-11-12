#!/usr/bin/env sh
# Browser e2e tests on a throwaway stand (docker-compose.e2e.yml), from the repository root:
#   sh e2e/run.sh            all tests
#   sh e2e/run.sh -g "вносит план"   only tests whose title matches
# The dev stack must have been set up once (dev php image, vendor volume, backend/config/jwt/test keys).
# The stand is always removed afterwards; the report stays in e2e/playwright-report (traces of failures inside).
set -u

cd "$(dirname "$0")/.."
E2E="docker compose -f docker-compose.e2e.yml"

cleanup() { $E2E --profile run down -v --remove-orphans >/dev/null 2>&1; }
trap cleanup EXIT INT TERM

set -e
$E2E up -d --build --wait mariadb php nginx frontend
$E2E exec -T php sh -c 'php bin/console doctrine:migrations:migrate -n -q && php bin/console doctrine:fixtures:load -n -q'
$E2E exec -T mariadb sh -c 'MYSQL_PWD=root mariadb -uroot rnp_e2e_test' < e2e/seed.sql
set +e

# Arguments go to `playwright test` as they are (a -g pattern may contain spaces).
$E2E run --rm playwright sh -c 'npm ci --no-audit --no-fund --loglevel=error && npx playwright test "$@"' playwright "$@"
