#!/bin/sh
# Nightly database dump, run inside the mariadb container by the scheduler (and by `make prod-backup`).
# Writes /backups/rnp-YYYY-MM-DD_HHMM.sql.gz and keeps the last RNP_BACKUP_DAYS days (default 14).
# The root password comes from the container environment; it never appears on a command line.
set -eu

dir=/backups
days="${RNP_BACKUP_DAYS:-14}"
file="$dir/rnp-$(date -u +%Y-%m-%d_%H%M).sql.gz"
tmp="$dir/.rnp-partial.sql"

mkdir -p "$dir"
rm -f "$tmp" "$tmp.gz"
# Dump to a file first: sh has no pipefail, so a failing dump piped into gzip would still "succeed".
MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb-dump -uroot \
    --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 \
    "$MARIADB_DATABASE" > "$tmp"
gzip -6 "$tmp"
# Rename only after a complete dump: a failed run never leaves a truncated file that looks valid.
mv "$tmp.gz" "$file"

find "$dir" -name 'rnp-*.sql.gz' -mtime +"$days" -delete
echo "Backup written: $file ($(du -h "$file" | cut -f1))"
