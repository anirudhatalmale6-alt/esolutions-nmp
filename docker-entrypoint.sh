#!/bin/bash
set -e

cd /var/www/html

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-solidinvoice}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-SolidInvoice2026}"
DB_SERVER_VERSION="${DB_SERVER_VERSION:-8.0.46}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@nmpmobiles.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-Nmp@2026}"
APP_URL="${APP_URL:-http://localhost:8080}"

# Build the database URL the app uses at runtime AND during install.
# Without this the app falls back to the default SQLite DSN.
if [ -z "${SOLIDINVOICE_DATABASE_URL}" ]; then
    export SOLIDINVOICE_DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/${DB_NAME}?serverVersion=${DB_SERVER_VERSION}&charset=utf8mb4"
fi

# Run the app with an empty application URL so it derives its public host from
# the incoming request (honouring X-Forwarded-Host from a reverse proxy). This
# lets the same image work behind Cloud Shell web preview, tunnels, or any
# domain without generating localhost links or 404-ing on an unknown host.
# The installer below still receives a valid --application-url (it is required).
export SOLIDINVOICE_APPLICATION_URL=""

mkdir -p var/cache var/log config/env
chown -R www-data:www-data var config

echo "[entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT} ..."
for i in $(seq 1 60); do
    if mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -p"${DB_PASSWORD}" --silent 2>/dev/null; then
        echo "[entrypoint] MySQL is up."
        break
    fi
    sleep 2
done

if [ ! -f config/env/.installed ]; then
    echo "[entrypoint] First run - building schema and installing ..."

    # 1) Build the schema directly from entity metadata as a safety net.
    #    (The sequential migrations have an FK-ordering bug on MySQL, so we
    #    never run them for a fresh install.) If the installer already built
    #    the schema this is a harmless no-op, so any "already exists" output
    #    is expected and ignored.
    su -s /bin/bash www-data -c "php bin/console doctrine:schema:create --no-interaction" \
        > var/install.log 2>&1 || true

    # 2) Run the installer to generate app secret, build id, admin user and
    #    mark the application as installed.
    su -s /bin/bash www-data -c "php bin/console app:install \
        --database-driver=pdo_mysql \
        --database-host='${DB_HOST}' \
        --database-port='${DB_PORT}' \
        --database-name='${DB_NAME}' \
        --database-user='${DB_USER}' \
        --database-password='${DB_PASSWORD}' \
        --admin-email='${ADMIN_EMAIL}' \
        --admin-password='${ADMIN_PASSWORD}' \
        --locale=en \
        --application-url='${APP_URL}' \
        --disable-telemetry \
        --no-interaction" >> var/install.log 2>&1

    if grep -q "installed successfully" var/install.log; then
        touch config/env/.installed
        echo "[entrypoint] Installation complete. Admin: ${ADMIN_EMAIL}"
    else
        echo "[entrypoint] Installation may have failed. Last lines:"
        grep -viE "Deprecat|ProxyHelper|var-exporter" var/install.log | tail -20
    fi
    chown -R www-data:www-data var config
fi

echo "[entrypoint] Warming cache ..."
su -s /bin/bash www-data -c "php bin/console cache:clear --env=prod --no-warmup" >/dev/null 2>&1 || true
su -s /bin/bash www-data -c "php bin/console cache:warmup --env=prod" >/dev/null 2>&1 || true
chown -R www-data:www-data var config

exec "$@"
