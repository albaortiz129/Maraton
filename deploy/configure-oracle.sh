#!/usr/bin/env bash
set -euo pipefail

DOMAIN="${1:-}"
if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]]; then
  echo "Usage: $0 your-domain.example" >&2
  exit 1
fi

if [ ! -s data/.secret-key ]; then
  echo "data/.secret-key is missing" >&2
  exit 1
fi

KEY="$(tr -d '\r\n' < data/.secret-key)"
umask 077
printf 'MARATON_DOMAIN=%s\nMARATON_SECRET_KEY=%s\n' "$DOMAIN" "$KEY" > .env.oracle
chmod 600 .env.oracle
echo "Oracle environment configured for $DOMAIN"
