#!/bin/sh
# One-time, on jtbot: sudo sh install.sh
# Adds the banner to the MOTD and disables the stock one-line "Welcome to Ubuntu"
# header (the banner's host row carries the same info).
set -e
d=$(cd "$(dirname "$0")" && pwd)
install -m 0755 "$d/00-jtbot-banner" /etc/update-motd.d/00-jtbot-banner
chmod -x /etc/update-motd.d/00-header
