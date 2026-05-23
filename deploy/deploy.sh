#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: deploy.sh [target_dir]

Safely deploy RTLS into the Apache document root while preserving runtime data.

Environment variables:
  APACHE_GROUP   Group that must be able to write SQLite and logs (default: www-data)
  APACHECTL_BIN  Apache control binary for configtest (default: /usr/sbin/apachectl)
  RUN_CONFIGTEST Set to 0 to skip apache configtest (default: 1)
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
TARGET_DIR="${1:-/var/www/rtls}"
APACHE_GROUP="${APACHE_GROUP:-www-data}"
APACHECTL_BIN="${APACHECTL_BIN:-/usr/sbin/apachectl}"
RUN_CONFIGTEST="${RUN_CONFIGTEST:-1}"

if [[ ! -d "${SOURCE_DIR}" ]]; then
    echo "Source directory not found: ${SOURCE_DIR}" >&2
    exit 1
fi

mkdir -p "${TARGET_DIR}"

rsync -a --delete \
    --exclude '.git/' \
    --exclude '.env' \
    --filter 'P src/*.sqlite' \
    --filter 'P logs/rtls.log' \
    --filter 'P logs/sessions/' \
    --filter 'P logs/sessions/***' \
    "${SOURCE_DIR}/" "${TARGET_DIR}/"

mkdir -p "${TARGET_DIR}/logs" "${TARGET_DIR}/logs/sessions" "${TARGET_DIR}/src"

if [[ -f "${TARGET_DIR}/src/rtls.sqlite" ]]; then
    chgrp "${APACHE_GROUP}" "${TARGET_DIR}/src/rtls.sqlite"
    chmod 664 "${TARGET_DIR}/src/rtls.sqlite"
fi

if [[ -f "${TARGET_DIR}/logs/rtls.log" ]]; then
    chgrp "${APACHE_GROUP}" "${TARGET_DIR}/logs/rtls.log"
    chmod 664 "${TARGET_DIR}/logs/rtls.log"
fi

chgrp "${APACHE_GROUP}" "${TARGET_DIR}/src" "${TARGET_DIR}/logs" "${TARGET_DIR}/logs/sessions"
chmod 775 "${TARGET_DIR}/src" "${TARGET_DIR}/logs" "${TARGET_DIR}/logs/sessions"

if [[ "${RUN_CONFIGTEST}" == "1" && -x "${APACHECTL_BIN}" ]]; then
    "${APACHECTL_BIN}" configtest
fi

echo "Deploy finished into ${TARGET_DIR}"
