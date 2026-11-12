#!/bin/sh
# Creates .env.prod from .env.prod.example with random secrets (run once on the server, from the repo root):
#   sh docker/prod/init-env.sh
# An existing .env.prod is never overwritten: changing JWT_PASSPHRASE or the database passwords later
# breaks the stored keys / the existing database.
set -eu

cd "$(dirname "$0")/../.."

if [ -f .env.prod ]; then
    echo ".env.prod already exists, kept."
    exit 0
fi

# Hex only: the values are used inside DATABASE_URL and must stay URL-safe.
secret() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }

umask 077
sed -e "s/^APP_SECRET=.*/APP_SECRET=$(secret 16)/" \
    -e "s/^JWT_PASSPHRASE=.*/JWT_PASSPHRASE=$(secret 32)/" \
    -e "s/^MARIADB_ROOT_PASSWORD=.*/MARIADB_ROOT_PASSWORD=$(secret 24)/" \
    -e "s/^MARIADB_PASSWORD=.*/MARIADB_PASSWORD=$(secret 24)/" \
    .env.prod.example > .env.prod

echo "Created .env.prod (mode 600) with random secrets."
echo "Fill in RNP_DOMAIN, PLATFORM_API_* and BITRIX_WEBHOOK_URL, then follow docs/deploy.md."
