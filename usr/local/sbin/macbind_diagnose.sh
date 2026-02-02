#!/bin/sh
#
# macbind_diagnose.sh
#
# MAC Binding System Diagnostic Script
# Checks all components of the MAC binding system to diagnose issues
#
# Usage: /usr/local/sbin/macbind_diagnose.sh [MAC_ADDRESS]
# Example: /usr/local/sbin/macbind_diagnose.sh a4:f6:e8:ed:c2:69
#

MAC_TO_CHECK="${1:-}"
QUEUE_FILE="/var/db/macbind_queue.csv"
ACTIVE_DB_FILE="/var/db/macbind_active.json"
CONFIG_FILE="/conf/config.xml"
SYNC_LOG="/var/log/macbind_sync.log"
PHP_ERROR_LOG="/var/log/php_errors.log"
LOCK_FILE="/var/run/macbind_sync.lock"
DISABLE_FLAG="/var/db/macbind_disabled"

echo "=========================================="
echo "MAC Binding System Diagnostic"
echo "=========================================="
echo ""

# Check if running as root
if [ "$(id -u)" != "0" ]; then
    echo "WARNING: Not running as root. Some checks may fail."
    echo ""
fi

# 1. Queue File Check
echo "=== 1. Queue File Check ==="
if [ -f "$QUEUE_FILE" ]; then
    echo "✓ Queue file exists: $QUEUE_FILE"
    ls -lh "$QUEUE_FILE" | awk '{print "  Size: " $5 ", Permissions: " $1 ", Owner: " $3 ":" $4}'
    
    if [ -s "$QUEUE_FILE" ]; then
        LINE_COUNT=$(wc -l < "$QUEUE_FILE" 2>/dev/null | tr -d '[:space:]' || echo "0")
    else
        LINE_COUNT="0"
    fi
    echo "  Total lines: $LINE_COUNT"
    
    if [ -n "$MAC_TO_CHECK" ]; then
        echo "  Searching for MAC: $MAC_TO_CHECK"
        if grep -qi "$MAC_TO_CHECK" "$QUEUE_FILE" 2>/dev/null; then
            echo "  ✓ MAC found in queue:"
            grep -i "$MAC_TO_CHECK" "$QUEUE_FILE" | head -3 | sed 's/^/    /'
        else
            echo "  ✗ MAC NOT found in queue"
        fi
    else
        echo "  Last 5 entries:"
        if [ -s "$QUEUE_FILE" ]; then
            tail -5 "$QUEUE_FILE" 2>/dev/null | sed 's/^/    /'
        else
            echo "    (empty)"
        fi
    fi
else
    echo "✗ Queue file NOT found: $QUEUE_FILE"
    echo "  This means hook is not writing to queue or file doesn't exist yet"
fi
echo ""

# 2. Active DB Check
echo "=== 2. Active Database Check ==="
if [ -f "$ACTIVE_DB_FILE" ]; then
    echo "✓ Active DB file exists: $ACTIVE_DB_FILE"
    ls -lh "$ACTIVE_DB_FILE" | awk '{print "  Size: " $5 ", Permissions: " $1 ", Owner: " $3 ":" $4}'
    
    if command -v python3 >/dev/null 2>&1; then
        BINDING_COUNT=$(python3 -c "import json, sys; data=json.load(open('$ACTIVE_DB_FILE')); print(len(data.get('bindings', {})))" 2>/dev/null || echo "0")
        echo "  Total bindings: $BINDING_COUNT"
        
        if [ -n "$MAC_TO_CHECK" ]; then
            echo "  Searching for MAC: $MAC_TO_CHECK"
            if grep -qi "$MAC_TO_CHECK" "$ACTIVE_DB_FILE" 2>/dev/null; then
                echo "  ✓ MAC found in active DB"
                python3 -c "
import json, sys
data = json.load(open('$ACTIVE_DB_FILE'))
mac_lower = '$MAC_TO_CHECK'.lower()
for key, binding in data.get('bindings', {}).items():
    if mac_lower in binding.get('mac', '').lower():
        print('    Zone:', binding.get('zone', 'N/A'))
        print('    MAC:', binding.get('mac', 'N/A'))
        print('    Expires:', binding.get('expires_at', 'N/A'))
        print('    Last Seen:', binding.get('last_seen', 'N/A'))
        break
" 2>/dev/null || echo "    (could not parse)"
            else
                echo "  ✗ MAC NOT found in active DB"
            fi
        fi
    else
        echo "  (python3 not available, skipping detailed check)"
        if [ -n "$MAC_TO_CHECK" ]; then
            if grep -qi "$MAC_TO_CHECK" "$ACTIVE_DB_FILE" 2>/dev/null; then
                echo "  ✓ MAC found in active DB (basic check)"
            else
                echo "  ✗ MAC NOT found in active DB"
            fi
        fi
    fi
else
    echo "✗ Active DB file NOT found: $ACTIVE_DB_FILE"
    echo "  This means sync has never run or database was not created"
fi
echo ""

# 3. pfSense Config Check
echo "=== 3. pfSense Config Check ==="
if [ -f "$CONFIG_FILE" ]; then
    echo "✓ Config file exists: $CONFIG_FILE"
    
    if [ -s "$CONFIG_FILE" ]; then
        AUTO_BIND_COUNT=$(grep -c "AUTO_BIND" "$CONFIG_FILE" 2>/dev/null | tr -d '[:space:]' || echo "0")
        if [ -z "$AUTO_BIND_COUNT" ] || [ "$AUTO_BIND_COUNT" = "" ]; then
            AUTO_BIND_COUNT="0"
        fi
    else
        AUTO_BIND_COUNT="0"
    fi
    echo "  AUTO_BIND entries: $AUTO_BIND_COUNT"
    
    if [ "$AUTO_BIND_COUNT" -gt 0 ]; then
        echo "  Sample AUTO_BIND entries:"
        grep -A 2 "AUTO_BIND" "$CONFIG_FILE" | head -6 | sed 's/^/    /'
    fi
    
    if [ -n "$MAC_TO_CHECK" ]; then
        echo "  Searching for MAC: $MAC_TO_CHECK"
        if grep -qi "$MAC_TO_CHECK" "$CONFIG_FILE" 2>/dev/null; then
            echo "  ✓ MAC found in config"
            echo "  Context:"
            grep -B 2 -A 2 -i "$MAC_TO_CHECK" "$CONFIG_FILE" | head -10 | sed 's/^/    /'
            
            # Check if it has AUTO_BIND tag (check in the same context block)
            MAC_CONTEXT=$(grep -B 5 -A 5 -i "$MAC_TO_CHECK" "$CONFIG_FILE" 2>/dev/null | head -15)
            if echo "$MAC_CONTEXT" | grep -q "AUTO_BIND"; then
                echo "  ✓ MAC has AUTO_BIND tag"
            else
                echo "  ✗ MAC does NOT have AUTO_BIND tag (may be manually added)"
            fi
        else
            echo "  ✗ MAC NOT found in config"
        fi
    fi
else
    echo "✗ Config file NOT found: $CONFIG_FILE"
fi
echo ""

# 4. Cron Job Check
echo "=== 4. Cron Job Check ==="
CRON_FOUND=0

# Check crontab
if crontab -l 2>/dev/null | grep -q "macbind"; then
    echo "✓ Cron job found in crontab:"
    crontab -l 2>/dev/null | grep "macbind" | sed 's/^/    /'
    CRON_FOUND=1
fi

# Check crontab.local
if [ -f "/etc/crontab.local" ] && grep -q "macbind" "/etc/crontab.local" 2>/dev/null; then
    echo "✓ Cron job found in /etc/crontab.local:"
    grep "macbind" "/etc/crontab.local" | sed 's/^/    /'
    CRON_FOUND=1
fi

if [ "$CRON_FOUND" -eq 0 ]; then
    echo "✗ Cron job NOT found"
    echo "  Expected: * * * * * root /usr/local/sbin/macbind_sync.sh"
    echo "  Add it via: Services > Cron (GUI) or /etc/crontab.local"
fi
echo ""

# 5. Sync Log Check
echo "=== 5. Sync Log Check ==="
if [ -f "$SYNC_LOG" ]; then
    echo "✓ Sync log exists: $SYNC_LOG"
    ls -lh "$SYNC_LOG" | awk '{print "  Size: " $5 ", Last modified: " $6 " " $7 " " $8}'
    
    echo "  Last 10 log entries:"
    if [ -s "$SYNC_LOG" ]; then
        tail -10 "$SYNC_LOG" 2>/dev/null | sed 's/^/    /'
    else
        echo "    (empty or file is empty)"
    fi
    
    # Check for errors
    if [ -s "$SYNC_LOG" ]; then
        ERROR_COUNT=$(grep -c "\[ERROR\]" "$SYNC_LOG" 2>/dev/null | tr -d '[:space:]' || echo "0")
        if [ -z "$ERROR_COUNT" ] || [ "$ERROR_COUNT" = "" ]; then
            ERROR_COUNT="0"
        fi
        # Compare as numbers (handle empty string)
        if [ "$ERROR_COUNT" -gt 0 ] 2>/dev/null; then
            echo "  ⚠ Found $ERROR_COUNT error(s) in log"
            echo "  Recent errors:"
            grep "\[ERROR\]" "$SYNC_LOG" 2>/dev/null | tail -3 | sed 's/^/    /'
        fi
        
        # Check last run time (FreeBSD compatible - no -P flag, use sed instead)
        LAST_RUN=$(tail -1 "$SYNC_LOG" 2>/dev/null | sed -n 's/.*\[\([^]]*\)\].*/\1/p' | head -1)
        if [ -n "$LAST_RUN" ] && [ "$LAST_RUN" != "" ]; then
            echo "  Last log entry: $LAST_RUN"
        fi
    else
        echo "  (log file is empty)"
    fi
else
    echo "✗ Sync log NOT found: $SYNC_LOG"
    echo "  This means sync has never run or logging is disabled"
fi
echo ""

# 6. PHP Error Log Check
echo "=== 6. PHP Error Log Check ==="
if [ -f "$PHP_ERROR_LOG" ] && [ -s "$PHP_ERROR_LOG" ]; then
    MACBIND_ERRORS=$(grep -i "macbind" "$PHP_ERROR_LOG" 2>/dev/null | wc -l | tr -d '[:space:]' || echo "0")
    if [ -z "$MACBIND_ERRORS" ] || [ "$MACBIND_ERRORS" = "" ]; then
        MACBIND_ERRORS="0"
    fi
    if [ "$MACBIND_ERRORS" -gt 0 ] 2>/dev/null; then
        echo "⚠ Found $MACBIND_ERRORS macbind-related error(s) in PHP log"
        echo "  Recent macbind errors:"
        grep -i "macbind" "$PHP_ERROR_LOG" 2>/dev/null | tail -5 | sed 's/^/    /'
    else
        echo "✓ No macbind errors in PHP log"
    fi
else
    echo "  PHP error log not found or not accessible: $PHP_ERROR_LOG"
fi
echo ""

# 7. Lock File Check
echo "=== 7. Lock File Check ==="
if [ -f "$LOCK_FILE" ]; then
    echo "⚠ Lock file exists: $LOCK_FILE"
    echo "  This means sync may be running or was interrupted"
    ls -lh "$LOCK_FILE" | awk '{print "  Created: " $6 " " $7 " " $8}'
    
    # Check if process is actually running
    if pgrep -f "macbind_sync" >/dev/null 2>&1; then
        echo "  ✓ macbind_sync process is running"
    else
        echo "  ✗ macbind_sync process NOT running (stale lock?)"
        echo "  You may need to remove: rm $LOCK_FILE"
    fi
else
    echo "✓ No lock file (sync can run)"
fi
echo ""

# 8. Disable Flag Check
echo "=== 8. Disable Flag Check ==="
if [ -f "$DISABLE_FLAG" ]; then
    echo "⚠ DISABLE flag is set: $DISABLE_FLAG"
    echo "  Sync is currently disabled"
    echo "  To enable: rm $DISABLE_FLAG"
else
    echo "✓ Disable flag not set (sync enabled)"
fi
echo ""

# 9. Script Files Check
echo "=== 9. Script Files Check ==="
SCRIPTS_OK=1

for script in "/usr/local/sbin/macbind_sync.php" "/usr/local/sbin/macbind_sync.sh"; do
    if [ -f "$script" ]; then
        if [ -x "$script" ]; then
            echo "✓ $script (executable)"
        else
            echo "⚠ $script (exists but not executable)"
            SCRIPTS_OK=0
        fi
    else
        echo "✗ $script (NOT found)"
        SCRIPTS_OK=0
    fi
done

if [ "$SCRIPTS_OK" -eq 0 ]; then
    echo "  Run installer: /usr/local/sbin/macbind_install.sh"
fi
echo ""

# 10. Zone Configuration Check
echo "=== 10. Zone Configuration Check ==="
if [ -f "/etc/inc/config.inc" ]; then
    if command -v php >/dev/null 2>&1; then
        ZONES=$(php -r "
require_once('/etc/inc/config.inc');
if (isset(\$config['captiveportal']) && is_array(\$config['captiveportal'])) {
    foreach (\$config['captiveportal'] as \$zone_id => \$zone_cfg) {
        \$zone_name = \$zone_cfg['zone'] ?? \$zone_id;
        echo \$zone_name . PHP_EOL;
    }
}
" 2>/dev/null)
        
        if [ -n "$ZONES" ]; then
            echo "✓ Captive Portal zones found:"
            echo "$ZONES" | sed 's/^/    /'
            
            if [ -n "$MAC_TO_CHECK" ]; then
                echo "  Checking zone 'office' for MAC: $MAC_TO_CHECK"
                php -r "
require_once('/etc/inc/config.inc');
\$zone = 'office';
if (isset(\$config['captiveportal'])) {
    foreach (\$config['captiveportal'] as \$zone_id => \$zone_cfg) {
        \$zone_name = \$zone_cfg['zone'] ?? \$zone_id;
        if (strtolower(\$zone_name) === strtolower(\$zone)) {
            if (isset(\$zone_cfg['passthrumac']) && is_array(\$zone_cfg['passthrumac'])) {
                foreach (\$zone_cfg['passthrumac'] as \$entry) {
                    if (stripos(\$entry['mac'] ?? '', '$MAC_TO_CHECK') !== false) {
                        echo '    ✓ MAC found in zone: ' . \$zone_name . PHP_EOL;
                        echo '      Action: ' . (\$entry['action'] ?? 'N/A') . PHP_EOL;
                        echo '      Description: ' . (\$entry['descr'] ?? 'N/A') . PHP_EOL;
                    }
                }
            }
        }
    }
}
" 2>/dev/null || echo "    (could not check)"
            fi
        else
            echo "  No captive portal zones configured"
        fi
    else
        echo "  PHP not available for zone check"
    fi
else
    echo "  Config.inc not found (not on pfSense?)"
fi
echo ""

# Summary
echo "=========================================="
echo "Diagnostic Summary"
echo "=========================================="
echo ""

ISSUES=0

# Check critical issues
if [ ! -f "$QUEUE_FILE" ]; then
    echo "⚠ CRITICAL: Queue file missing - hook may not be working"
    ISSUES=$((ISSUES + 1))
fi

if [ "$CRON_FOUND" -eq 0 ]; then
    echo "⚠ CRITICAL: Cron job not configured - queue will not be processed"
    ISSUES=$((ISSUES + 1))
fi

if [ -f "$DISABLE_FLAG" ]; then
    echo "⚠ WARNING: Sync is disabled via flag file"
    ISSUES=$((ISSUES + 1))
fi

if [ -f "$LOCK_FILE" ] && ! pgrep -f "macbind_sync" >/dev/null 2>&1; then
    echo "⚠ WARNING: Stale lock file detected"
    ISSUES=$((ISSUES + 1))
fi

if [ "$ISSUES" -eq 0 ]; then
    echo "✓ No critical issues detected"
    echo ""
    echo "If MAC binding still doesn't work:"
    echo "1. Verify custom portal page is being used (Services > Captive Portal > [Zone] > Portal Page Contents)"
    echo "2. Check if hook code is in the portal page"
    echo "3. Try a fresh voucher login and check queue file immediately"
    echo "4. Manually run sync: /usr/local/sbin/macbind_sync.php"
else
    echo ""
    echo "Found $ISSUES issue(s) that need attention"
fi

echo ""
echo "For detailed help, see: README.md or run: /usr/local/sbin/macbind_sync.php --help"
