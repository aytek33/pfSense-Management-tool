#!/bin/sh
#
# macbind_install.sh
# Installer script for MAC Binding automation on pfSense CE 2.7.2
#
# This script is idempotent - safe to run multiple times.
# Must be executed as root.
#
# Usage:
#   /usr/local/sbin/macbind_install.sh
#
# Actions performed:
#   1. Creates required directories
#   2. Creates queue file with web user write access
#   3. Creates active DB file with root-only access
#   4. Creates log file
#   5. Sets correct permissions on scripts
#   6. Runs self-test
#

set -e

# ============================================================================
# Configuration
# ============================================================================

QUEUE_FILE="/var/db/macbind_queue.csv"
ACTIVE_DB_FILE="/var/db/macbind_active.json"
LOG_FILE="/var/log/macbind_sync.log"
BACKUP_DIR="/conf/macbind_backups"
DISABLE_FLAG="/var/db/macbind_disabled"

SYNC_SCRIPT="/usr/local/sbin/macbind_sync.php"
SHELL_WRAPPER="/usr/local/sbin/macbind_sync.sh"
INSTALL_SCRIPT="/usr/local/sbin/macbind_install.sh"
IMPORT_SCRIPT="/usr/local/sbin/macbind_import.php"
MANAGE_SCRIPT="/usr/local/sbin/macbind_manage.php"

# Web server user (pfSense uses 'www')
WEB_USER="www"
WEB_GROUP="www"

# ============================================================================
# Helper Functions
# ============================================================================

log_info() {
    echo "[INFO] $1"
}

log_error() {
    echo "[ERROR] $1" >&2
}

log_warn() {
    echo "[WARN] $1"
}

# ============================================================================
# Root Check
# ============================================================================

if [ "$(id -u)" -ne 0 ]; then
    log_error "This installer must be run as root."
    echo "Usage: sudo ${INSTALL_SCRIPT}"
    exit 1
fi

log_info "=== MAC Binding Automation Installer ==="
log_info "Target: pfSense CE 2.7.2"
log_info ""

# ============================================================================
# Verify pfSense Environment
# ============================================================================

log_info "Checking pfSense environment..."

if [ ! -f "/etc/inc/config.inc" ]; then
    log_warn "pfSense config.inc not found - may not be running on pfSense"
fi

if [ ! -f "/etc/inc/captiveportal.inc" ]; then
    log_warn "pfSense captiveportal.inc not found"
fi

# Check for web user
if ! id "${WEB_USER}" >/dev/null 2>&1; then
    log_warn "Web user '${WEB_USER}' not found, trying 'nobody'"
    WEB_USER="nobody"
    WEB_GROUP="nobody"
fi

log_info "Web user: ${WEB_USER}:${WEB_GROUP}"

# ============================================================================
# Create Directories
# ============================================================================

log_info "Creating directories..."

# Backup directory
if [ ! -d "${BACKUP_DIR}" ]; then
    mkdir -p "${BACKUP_DIR}"
    log_info "Created: ${BACKUP_DIR}"
fi
chmod 0755 "${BACKUP_DIR}"
chown root:wheel "${BACKUP_DIR}"

# Ensure /var/db exists (should always exist on FreeBSD)
if [ ! -d "/var/db" ]; then
    mkdir -p "/var/db"
fi

# ============================================================================
# Create Queue File
# ============================================================================

log_info "Setting up queue file..."

# Create queue file if not exists
if [ ! -f "${QUEUE_FILE}" ]; then
    touch "${QUEUE_FILE}"
    log_info "Created: ${QUEUE_FILE}"
fi

# Set ownership to web user for append access
chown "root:${WEB_GROUP}" "${QUEUE_FILE}"
# Mode 0664: owner rw, group rw (web user can append), others read
chmod 0664 "${QUEUE_FILE}"

log_info "Queue file permissions: root:${WEB_GROUP} 0664"

# ============================================================================
# Create Active DB File
# ============================================================================

log_info "Setting up active database file..."

if [ ! -f "${ACTIVE_DB_FILE}" ]; then
    # Initialize with empty structure
    echo '{"version":1,"updated_at":"","bindings":{}}' > "${ACTIVE_DB_FILE}"
    log_info "Created: ${ACTIVE_DB_FILE}"
fi

# Root-only access
chown root:wheel "${ACTIVE_DB_FILE}"
chmod 0600 "${ACTIVE_DB_FILE}"

log_info "Active DB permissions: root:wheel 0600"

# ============================================================================
# Create Log File
# ============================================================================

log_info "Setting up log file..."

if [ ! -f "${LOG_FILE}" ]; then
    touch "${LOG_FILE}"
    log_info "Created: ${LOG_FILE}"
fi

chown root:wheel "${LOG_FILE}"
chmod 0644 "${LOG_FILE}"

log_info "Log file permissions: root:wheel 0644"

# ============================================================================
# Set Script Permissions
# ============================================================================

log_info "Setting script permissions..."

# Main sync script
if [ -f "${SYNC_SCRIPT}" ]; then
    chown root:wheel "${SYNC_SCRIPT}"
    chmod 0755 "${SYNC_SCRIPT}"
    log_info "Set permissions on: ${SYNC_SCRIPT}"
else
    log_error "Sync script not found: ${SYNC_SCRIPT}"
    log_error "Please copy macbind_sync.php to ${SYNC_SCRIPT}"
fi

# Shell wrapper
if [ -f "${SHELL_WRAPPER}" ]; then
    chown root:wheel "${SHELL_WRAPPER}"
    chmod 0755 "${SHELL_WRAPPER}"
    log_info "Set permissions on: ${SHELL_WRAPPER}"
else
    log_error "Shell wrapper not found: ${SHELL_WRAPPER}"
    log_error "Please copy macbind_sync.sh to ${SHELL_WRAPPER}"
fi

# Import script
if [ -f "${IMPORT_SCRIPT}" ]; then
    chown root:wheel "${IMPORT_SCRIPT}"
    chmod 0755 "${IMPORT_SCRIPT}"
    log_info "Set permissions on: ${IMPORT_SCRIPT}"
else
    log_warn "Import script not found: ${IMPORT_SCRIPT}"
fi

# Management script
if [ -f "${MANAGE_SCRIPT}" ]; then
    chown root:wheel "${MANAGE_SCRIPT}"
    chmod 0755 "${MANAGE_SCRIPT}"
    log_info "Set permissions on: ${MANAGE_SCRIPT}"
else
    log_warn "Management script not found: ${MANAGE_SCRIPT}"
fi

# This installer script
if [ -f "${INSTALL_SCRIPT}" ]; then
    chown root:wheel "${INSTALL_SCRIPT}"
    chmod 0755 "${INSTALL_SCRIPT}"
fi

# ============================================================================
# Remove Disable Flag if Present (fresh install)
# ============================================================================

if [ -f "${DISABLE_FLAG}" ]; then
    log_warn "Disable flag exists: ${DISABLE_FLAG}"
    log_warn "Remove it manually to enable sync: rm ${DISABLE_FLAG}"
fi

# ============================================================================
# Summary
# ============================================================================

log_info ""
log_info "=== Installation Summary ==="
log_info ""
log_info "Files created/configured:"
log_info "  Queue file:    ${QUEUE_FILE} (${WEB_USER} writable)"
log_info "  Active DB:     ${ACTIVE_DB_FILE} (root only)"
log_info "  Log file:      ${LOG_FILE}"
log_info "  Backup dir:    ${BACKUP_DIR}"
log_info ""
log_info "Scripts:"
log_info "  Sync script:   ${SYNC_SCRIPT}"
log_info "  Shell wrapper: ${SHELL_WRAPPER}"
log_info "  Import tool:   ${IMPORT_SCRIPT}"
log_info "  Manage tool:   ${MANAGE_SCRIPT}"
log_info ""

# ============================================================================
# Run Self-Test
# ============================================================================

log_info "Running self-test..."
log_info ""

if [ -f "${SYNC_SCRIPT}" ] && [ -x "${SYNC_SCRIPT}" ]; then
    /usr/local/bin/php -q "${SYNC_SCRIPT}" --selftest
    SELFTEST_RESULT=$?
    log_info ""
    if [ ${SELFTEST_RESULT} -eq 0 ]; then
        log_info "Self-test: PASSED"
    else
        log_error "Self-test: FAILED (exit code ${SELFTEST_RESULT})"
        log_error "Please review the errors above and fix any issues."
        exit 1
    fi
else
    log_warn "Cannot run self-test - sync script not found or not executable"
fi

# ============================================================================
# Next Steps
# ============================================================================

log_info ""
log_info "=== Installation Complete ==="
log_info ""
log_info "Next steps:"
log_info ""
log_info "1. Import existing connected users (RECOMMENDED for first install):"
log_info "   ${IMPORT_SCRIPT} --dry-run    # Preview what will be imported"
log_info "   ${IMPORT_SCRIPT}              # Actually import"
log_info ""
log_info "2. Set up cron job (run as root every minute):"
log_info ""
log_info "   Option A - Using pfSense Cron package:"
log_info "     Add job: * * * * * root ${SHELL_WRAPPER}"
log_info ""
log_info "   Option B - Using /etc/crontab.local:"
log_info "     echo '* * * * * root ${SHELL_WRAPPER}' >> /etc/crontab.local"
log_info ""
log_info "3. Insert the captive portal hook snippet into your portal page"
log_info "   (see captive_portal_hook.php for the code and instructions)"
log_info ""
log_info "4. Test with dry-run mode:"
log_info "   ${SHELL_WRAPPER} --dry-run"
log_info ""
log_info "5. View and manage MAC bindings:"
log_info "   ${MANAGE_SCRIPT} list         # List all bindings"
log_info "   ${MANAGE_SCRIPT} stats        # Show statistics"
log_info "   ${MANAGE_SCRIPT} search MAC   # Search for a MAC"
log_info "   ${MANAGE_SCRIPT} remove MAC   # Remove a binding"
log_info ""
log_info "6. Monitor logs:"
log_info "   tail -f ${LOG_FILE}"
log_info ""

exit 0
