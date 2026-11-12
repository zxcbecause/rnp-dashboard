#!/usr/bin/env bash
# One-time local bootstrap, runs inside the php container:
#   docker compose run --rm php bash bin/init-dev.sh
# Idempotent: existing .env.local and keys are kept.
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env.local ]; then
    app_secret="$(php -r 'echo bin2hex(random_bytes(16));')"
    jwt_pass="$(php -r 'echo bin2hex(random_bytes(32));')"
    sed -e "s/^APP_SECRET=.*/APP_SECRET=${app_secret}/" \
        -e "s/^JWT_PASSPHRASE=.*/JWT_PASSPHRASE=${jwt_pass}/" \
        .env.local.example > .env.local
    echo "Created .env.local with random APP_SECRET and JWT_PASSPHRASE."
else
    echo ".env.local already exists, kept."
fi

composer install --no-interaction --no-progress

if [ ! -f config/jwt/private.pem ]; then
    php bin/console lexik:jwt:generate-keypair --no-interaction
else
    echo "Dev JWT key pair already exists, kept."
fi

# Throwaway key pair for the test environment (passphrase from .env.test).
if [ ! -f config/jwt/test/private.pem ]; then
    mkdir -p config/jwt/test
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -aes256 \
        -pass pass:test-passphrase -out config/jwt/test/private.pem 2>/dev/null
    openssl pkey -in config/jwt/test/private.pem -passin pass:test-passphrase \
        -pubout -out config/jwt/test/public.pem
    echo "Generated test JWT key pair."
fi

echo "Done."
