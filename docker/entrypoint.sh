#!/bin/sh
set -eu

mkdir -p "${MARATON_DATA_DIR:-/var/lib/maraton}"
chown -R www-data:www-data "${MARATON_DATA_DIR:-/var/lib/maraton}" 2>/dev/null || true
chmod 750 "${MARATON_DATA_DIR:-/var/lib/maraton}" 2>/dev/null || true

php /usr/local/bin/maraton-backup.php

exec "$@"
