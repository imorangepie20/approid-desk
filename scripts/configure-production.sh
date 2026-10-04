#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
umask 077

if [[ -e .env.production || -e .env.database || -e secrets/tunnel.env ]]; then
    printf 'Production secrets already exist. Refusing to overwrite them.\n' >&2
    exit 1
fi

read -r -p 'SMTP username [desk@approid.team]: ' smtp_username
smtp_username=${smtp_username:-desk@approid.team}
read -r -s -p 'SMTP password: ' smtp_password
printf '\n'
read -r -p "Mail from address [$smtp_username]: " mail_from_address
mail_from_address=${mail_from_address:-$smtp_username}
read -r -s -p 'Cloudflare tunnel token: ' tunnel_token
printf '\n'

if [[ -z "$smtp_password" || -z "$tunnel_token" ]]; then
    printf 'SMTP password and Cloudflare tunnel token are required.\n' >&2
    exit 1
fi

if [[ "$smtp_username" != *@* || "$mail_from_address" != *@* ]]; then
    printf 'SMTP username and mail from address must be full email addresses.\n' >&2
    exit 1
fi

if ! TUNNEL_TOKEN_TO_VALIDATE="$tunnel_token" python3 - <<'PY'
import base64
import json
import os
import sys

token = os.environ.pop('TUNNEL_TOKEN_TO_VALIDATE')

try:
    padding = '=' * ((4 - len(token) % 4) % 4)
    payload = json.loads(base64.b64decode(token + padding, validate=True))
except Exception:
    sys.exit(1)

sys.exit(0 if isinstance(payload, dict) and {'a', 't', 's'} <= payload.keys() else 1)
PY
then
    printf 'The tunnel token must be the long eyJ... value, not the Docker command or an API token.\n' >&2
    exit 1
fi

dotenv_quote() {
    local value=$1

    if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
        printf 'Secrets must not contain line breaks.\n' >&2
        exit 1
    fi

    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//\$/\\\$}
    printf '"%s"' "$value"
}

app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
database_password=$(openssl rand -hex 32)
database_root_password=$(openssl rand -hex 32)

mkdir -p secrets

cat > .env.production <<EOF
APP_NAME="Approid Desk"
APP_ENV=production
APP_KEY=$(dotenv_quote "$app_key")
APP_DEBUG=false
APP_URL=https://desk.approid.team
APP_TIMEZONE=Asia/Seoul
APP_BIND_PORT=8080

APP_LOCALE=ko
APP_FALLBACK_LOCALE=ko
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=approid_desk
DB_USERNAME=approid_desk
DB_PASSWORD=$(dotenv_quote "$database_password")

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=desk.approid.team
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90

FILESYSTEM_DISK=local
ATTACHMENT_DISK=attachments
ATTACHMENT_SCANNER=clamav
CLAMAV_HOST=clamav
CLAMAV_PORT=3310
CLAMAV_CONNECT_TIMEOUT=5
CLAMAV_READ_TIMEOUT=30

MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=host.docker.internal
MAIL_PORT=587
MAIL_USERNAME=$(dotenv_quote "$smtp_username")
MAIL_PASSWORD=$(dotenv_quote "$smtp_password")
MAIL_TIMEOUT=20
MAIL_EHLO_DOMAIN=desk.approid.team
# Mailcow currently presents its self-signed certificate only on this host-local route.
MAIL_VERIFY_PEER=false
MAIL_FROM_ADDRESS=$(dotenv_quote "$mail_from_address")
MAIL_FROM_NAME="Approid Desk"

VITE_APP_NAME="Approid Desk"
EOF

cat > .env.database <<EOF
MYSQL_DATABASE=approid_desk
MYSQL_USER=approid_desk
MYSQL_PASSWORD=$(dotenv_quote "$database_password")
MYSQL_ROOT_PASSWORD=$(dotenv_quote "$database_root_password")
EOF

cat > secrets/tunnel.env <<EOF
TUNNEL_TOKEN=$(dotenv_quote "$tunnel_token")
EOF

chmod 600 .env.production .env.database secrets/tunnel.env
printf 'Production environment files created with mode 600.\n'
