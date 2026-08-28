#!/usr/bin/env bash
#
# One-shot, idempotent installer for the environmental sensors module's
# runtime dependencies: php-snmp, fping, VictoriaMetrics, and both systemd
# units (VictoriaMetrics itself + the nStructure collector daemon).
#
# Run as root on the production host, from the deployed application
# directory (so deploy/*.service can be found relative to this script):
#
#   sudo bash deploy/install.sh
#
# Safe to re-run: every step checks whether it already applied before
# doing anything. Does not touch the application code, database, or
# nginx/PHP-FPM configuration — see docs/DEPLOYMENT.md for those.

set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must be run as root (sudo bash deploy/install.sh)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "${SCRIPT_DIR}")"
VM_USER="victoriametrics"
VM_DATA_DIR="/var/lib/victoria-metrics-data"
VM_BINARY="/usr/local/bin/victoria-metrics-prod"

echo "==> Installing php-snmp and fping (apt packages)"
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "")"
if [[ -z "${PHP_VERSION}" ]]; then
    echo "Could not detect the installed PHP version (php -v failed)." >&2
    exit 1
fi
apt-get update -qq
apt-get install -y "php${PHP_VERSION}-snmp" fping

if ! php -m | grep -qi '^snmp$'; then
    echo "php-snmp installed but not loaded — restarting php${PHP_VERSION}-fpm" >&2
    systemctl restart "php${PHP_VERSION}-fpm" 2>/dev/null || true
fi

echo "==> Setting up the VictoriaMetrics system account and data directory"
if ! getent passwd "${VM_USER}" > /dev/null; then
    useradd --system --no-create-home --shell /usr/sbin/nologin "${VM_USER}"
fi
mkdir -p "${VM_DATA_DIR}"
chown "${VM_USER}:${VM_USER}" "${VM_DATA_DIR}"

echo "==> Installing the VictoriaMetrics binary"
if [[ -x "${VM_BINARY}" ]]; then
    echo "Already present at ${VM_BINARY}, skipping download."
else
    ARCH="$(uname -m)"
    case "${ARCH}" in
        x86_64) VM_ARCH="amd64" ;;
        aarch64) VM_ARCH="arm64" ;;
        *)
            echo "Unsupported architecture: ${ARCH}. Download manually from" >&2
            echo "https://github.com/VictoriaMetrics/VictoriaMetrics/releases" >&2
            exit 1
            ;;
    esac
    VERSION="$(curl -fsSL https://api.github.com/repos/VictoriaMetrics/VictoriaMetrics/releases/latest \
        | grep -oP '"tag_name":\s*"\K[^"]+')"
    TMP_DIR="$(mktemp -d)"
    trap 'rm -rf "${TMP_DIR}"' EXIT
    curl -fsSL -o "${TMP_DIR}/vm.tar.gz" \
        "https://github.com/VictoriaMetrics/VictoriaMetrics/releases/download/${VERSION}/victoria-metrics-linux-${VM_ARCH}-${VERSION}.tar.gz"
    tar xzf "${TMP_DIR}/vm.tar.gz" -C "${TMP_DIR}"
    install -o root -g root -m 755 "${TMP_DIR}/victoria-metrics-prod" "${VM_BINARY}"
    echo "Installed VictoriaMetrics ${VERSION}."
fi

echo "==> Installing systemd units"
install -m 644 "${SCRIPT_DIR}/victoriametrics.service" /etc/systemd/system/victoriametrics.service
install -m 644 "${SCRIPT_DIR}/nstructure-sensors-daemon.service" /etc/systemd/system/nstructure-sensors-daemon.service
systemctl daemon-reload

echo "==> Enabling and (re)starting services"
systemctl enable --now victoriametrics
systemctl enable --now nstructure-sensors-daemon
systemctl restart nstructure-sensors-daemon

echo "==> Done. Current status:"
systemctl --no-pager status victoriametrics || true
systemctl --no-pager status nstructure-sensors-daemon || true
echo
echo "Verify VictoriaMetrics is receiving data with:"
echo "  curl -s 'http://127.0.0.1:8428/api/v1/label/__name__/values'"
