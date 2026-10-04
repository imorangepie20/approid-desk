#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
umask 077

if [[ ! -f .env.production ]]; then
    printf 'Missing required file: .env.production\n' >&2
    exit 1
fi

read -r -p 'SMTP mailbox address [desk@approid.team]: ' smtp_username
smtp_username=${smtp_username:-desk@approid.team}
read -r -s -p 'SMTP mailbox password: ' smtp_password
printf '\n'
read -r -p "Mail from address [$smtp_username]: " mail_from_address
mail_from_address=${mail_from_address:-$smtp_username}

if [[ "$smtp_username" != *@* || "$mail_from_address" != *@* || -z "$smtp_password" ]]; then
    printf 'Full mailbox addresses and a non-empty password are required.\n' >&2
    exit 1
fi

dotenv_quote() {
    local value=$1

    if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
        printf 'Credentials must not contain line breaks.\n' >&2
        exit 1
    fi

    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//\$/\\\$}
    printf '"%s"' "$value"
}

temporary_file=$(mktemp .env.production.XXXXXX)
trap 'rm -f "$temporary_file"' EXIT

while IFS= read -r line || [[ -n "$line" ]]; do
    case "$line" in
        MAIL_USERNAME=*) printf 'MAIL_USERNAME=%s\n' "$(dotenv_quote "$smtp_username")" ;;
        MAIL_PASSWORD=*) printf 'MAIL_PASSWORD=%s\n' "$(dotenv_quote "$smtp_password")" ;;
        MAIL_FROM_ADDRESS=*) printf 'MAIL_FROM_ADDRESS=%s\n' "$(dotenv_quote "$mail_from_address")" ;;
        *) printf '%s\n' "$line" ;;
    esac
done < .env.production > "$temporary_file"

chmod 600 "$temporary_file"
mv "$temporary_file" .env.production
trap - EXIT

printf 'SMTP credentials updated.\n'
