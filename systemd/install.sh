#!/bin/sh
# Install and start the pinba-server systemd unit.
# Usage: sudo ./install.sh [pinba-server|pinba-server-loki]
set -eu

UNIT="${1:-pinba-server}.service"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -f "$SRC_DIR/$UNIT" ]; then
    echo "unknown unit: $UNIT" >&2
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root: sudo $0" >&2
    exit 1
fi

cp "$SRC_DIR/$UNIT" "/etc/systemd/system/$UNIT"
systemctl daemon-reload
systemctl enable --now "$UNIT"
systemctl --no-pager status "$UNIT"
