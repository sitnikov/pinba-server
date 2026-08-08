#!/bin/sh
# Stop and remove the pinba-server systemd unit.
# Usage: sudo ./uninstall.sh [pinba-server|pinba-server-loki]
set -eu

UNIT="${1:-pinba-server}.service"

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root: sudo $0" >&2
    exit 1
fi

systemctl disable --now "$UNIT" || true
rm -f "/etc/systemd/system/$UNIT"
systemctl daemon-reload
echo "$UNIT removed"
