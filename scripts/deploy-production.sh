#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

secret_files=(.env.production .env.database)

if [[ -f secrets/tunnel.env ]]; then
    secret_files+=(secrets/tunnel.env)
fi

for file in "${secret_files[@]}"; do
    if [[ ! -f "$file" ]]; then
        printf 'Missing required file: %s\n' "$file" >&2
        exit 1
    fi

    mode=$(stat -c '%a' "$file")
    if (( (8#$mode & 077) != 0 )); then
        printf '%s must not be readable or writable by group/others (current mode: %s).\n' "$file" "$mode" >&2
        exit 1
    fi
done

compose=(docker compose --env-file .env.production -f compose.production.yaml)

if [[ -f secrets/tunnel.env ]]; then
    compose+=(--profile tunnel)
fi

"${compose[@]}" build app
"${compose[@]}" up -d --wait mysql clamav
"${compose[@]}" run --rm app php artisan migrate --force
"${compose[@]}" up -d --wait
"${compose[@]}" exec -T app php artisan queue:restart
"${compose[@]}" ps

curl --fail --silent --show-error http://127.0.0.1:"${APP_BIND_PORT:-8080}"/up
printf '\nDeployment health check passed.\n'
