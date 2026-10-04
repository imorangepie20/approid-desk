#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
umask 077

read -r -s -p 'Cloudflare tunnel token (eyJ... value only): ' tunnel_token
printf '\n'

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

    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//\$/\\\$}
    printf '"%s"' "$value"
}

mkdir -p secrets
temporary_file=$(mktemp secrets/tunnel.env.XXXXXX)
trap 'rm -f "$temporary_file"' EXIT
printf 'TUNNEL_TOKEN=%s\n' "$(dotenv_quote "$tunnel_token")" > "$temporary_file"
chmod 600 "$temporary_file"
mv "$temporary_file" secrets/tunnel.env
trap - EXIT

printf 'Cloudflare tunnel token updated.\n'
