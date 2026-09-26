#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
umask 077
mkdir -p backups/dev
chmod 700 backups backups/dev

stamp=$(date -u +%Y%m%dT%H%M%SZ)
dump="backups/dev/mailcenter-${stamp}-$$.dump"
partial="${dump}.partial"
trap 'rm -f "$partial"' EXIT HUP INT TERM

docker compose exec -T postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" -d mailcenter -Fc --no-owner --no-acl' > "$partial"
mv "$partial" "$dump"
chmod 600 "$dump"
trap - EXIT HUP INT TERM
printf 'Backup written to %s\n' "$dump"
if [ -n "$(find backups backups/dev "$dump" -maxdepth 0 -perm /077 -print)" ]; then
    printf 'Warning: this filesystem did not apply private permissions; protect the backup directory manually.\n' >&2
fi
