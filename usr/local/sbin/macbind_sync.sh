#!/bin/sh
#
# macbind_sync.sh
# Shell wrapper for macbind_sync.php
#
# This wrapper ensures a safe PATH and environment for running the
# MAC binding sync script. Designed for cron execution.
#
# Usage:
#   /usr/local/sbin/macbind_sync.sh [--dry-run] [--selftest]
#
# Exit codes:
#   0 - Success
#   1 - Error (check logs)
#   2 - PHP binary not found
#   3 - Sync script not found
#

# Set safe PATH for FreeBSD/pfSense
PATH="/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin"
export PATH

# Ensure clean environment
unset CDPATH
umask 022

# Script locations
PHP_BIN="/usr/local/bin/php"
SYNC_SCRIPT="/usr/local/sbin/macbind_sync.php"

# Verify PHP binary exists
if [ ! -x "${PHP_BIN}" ]; then
    echo "ERROR: PHP binary not found or not executable: ${PHP_BIN}" >&2
    exit 2
fi

# Verify sync script exists
if [ ! -f "${SYNC_SCRIPT}" ]; then
    echo "ERROR: Sync script not found: ${SYNC_SCRIPT}" >&2
    exit 3
fi

# Execute the PHP script with all arguments passed through
exec "${PHP_BIN}" -q "${SYNC_SCRIPT}" "$@"
