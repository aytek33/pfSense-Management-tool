#!/usr/local/bin/php
<?php
/**
 * macbind_import.php
 * 
 * Import currently connected captive portal users into MAC binding system.
 * Run this ONCE after initial installation to capture existing sessions.
 * 
 * This script reads the captive portal session database and creates
 * queue entries for all currently authenticated users.
 * 
 * Usage:
 *   /usr/local/sbin/macbind_import.php [--zone=ZONENAME] [--dry-run]
 * 
 * Options:
 *   --zone=NAME    Import only from specified zone (default: all zones)
 *   --dry-run      Show what would be imported without writing
 *   --help         Show this help message
 * 
 * Must be run as root.
 * 
 * @version 1.0.0
 */

declare(strict_types=1);

// ============================================================================
// CONFIGURATION
// ============================================================================

define('QUEUE_FILE', '/var/db/macbind_queue.csv');
define('LOG_FILE', '/var/log/macbind_sync.log');
define('ZONE_DEFAULT_DURATION_MINUTES', 43200); // 30 days default

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function log_msg(string $level, string $message): void {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $entry = "[{$timestamp}] [{$level}] [import] {$message}\n";
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

function show_help(): void {
    echo <<<HELP
macbind_import.php - Import existing captive portal sessions

Usage:
  /usr/local/sbin/macbind_import.php [OPTIONS]

Options:
  --zone=NAME    Import only from specified zone (default: all zones)
  --dry-run      Show what would be imported without writing
  --help         Show this help message

Description:
  This script reads the pfSense captive portal session database and
  creates MAC binding queue entries for all currently authenticated users.
  
  Run this ONCE after initial installation to ensure existing sessions
  get their MAC bindings created.

Examples:
  # Import all zones (dry run first)
  /usr/local/sbin/macbind_import.php --dry-run
  
  # Import all zones
  /usr/local/sbin/macbind_import.php
  
  # Import specific zone only
  /usr/local/sbin/macbind_import.php --zone=myzone

HELP;
}

function normalize_mac(string $mac): string {
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac));
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

// ============================================================================
// MAIN
// ============================================================================

// Ensure CLI
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "ERROR: This script must be run from command line.\n");
    exit(1);
}

// Parse arguments
$options = [];
$zone_filter = null;
$dry_run = false;

foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    
    if ($arg === '--help' || $arg === '-h') {
        show_help();
        exit(0);
    }
    
    if ($arg === '--dry-run') {
        $dry_run = true;
        continue;
    }
    
    if (strpos($arg, '--zone=') === 0) {
        $zone_filter = strtolower(substr($arg, 7));
        continue;
    }
}

// Check root
if (posix_getuid() !== 0) {
    fwrite(STDERR, "ERROR: This script must be run as root.\n");
    exit(1);
}

echo "=== MAC Binding Import Tool ===\n\n";

if ($dry_run) {
    echo "*** DRY RUN MODE - No changes will be made ***\n\n";
}

// Load pfSense includes
$pfsense_includes = [
    '/etc/inc/config.inc',
    '/etc/inc/captiveportal.inc',
    '/etc/inc/util.inc'
];

foreach ($pfsense_includes as $inc) {
    if (!file_exists($inc)) {
        fwrite(STDERR, "ERROR: pfSense include not found: {$inc}\n");
        fwrite(STDERR, "This script must be run on a pfSense system.\n");
        exit(1);
    }
}

require_once('/etc/inc/config.inc');
require_once('/etc/inc/captiveportal.inc');
require_once('/etc/inc/util.inc');

global $config;

// Get captive portal zones
if (!isset($config['captiveportal']) || !is_array($config['captiveportal'])) {
    echo "No captive portal zones configured.\n";
    exit(0);
}

$zones = $config['captiveportal'];
$total_imported = 0;
$total_skipped = 0;
$total_errors = 0;

foreach ($zones as $zone_id => $zone_cfg) {
    $zone_name = $zone_cfg['zone'] ?? $zone_id;
    $zone_name_lower = strtolower($zone_name);
    
    // Filter by zone if specified
    if ($zone_filter !== null && $zone_name_lower !== $zone_filter) {
        continue;
    }
    
    echo "Processing zone: {$zone_name}\n";
    echo str_repeat('-', 50) . "\n";
    
    // Get connected users from captive portal database
    // pfSense stores session data in SQLite: /var/db/captiveportal_<zone>.db
    $db_file = "/var/db/captiveportal_{$zone_name}.db";
    
    if (!file_exists($db_file)) {
        echo "  No session database found: {$db_file}\n";
        echo "  (Zone may have no active sessions)\n\n";
        continue;
    }
    
    // Open SQLite database
    try {
        $db = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
    } catch (Exception $e) {
        echo "  ERROR: Cannot open database: {$e->getMessage()}\n\n";
        $total_errors++;
        continue;
    }
    
    // Query active sessions
    // Captive portal schema typically has: sessionid, username, ip, mac, allow_time, etc.
    $query = "SELECT * FROM captiveportal WHERE allow_time > 0";
    
    try {
        $result = $db->query($query);
    } catch (Exception $e) {
        // Try alternative query (schema may vary)
        try {
            $result = $db->query("SELECT * FROM captiveportal");
        } catch (Exception $e2) {
            echo "  ERROR: Cannot query database: {$e2->getMessage()}\n\n";
            $db->close();
            $total_errors++;
            continue;
        }
    }
    
    $sessions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sessions[] = $row;
    }
    $db->close();
    
    echo "  Found " . count($sessions) . " active session(s)\n\n";
    
    if (empty($sessions)) {
        continue;
    }
    
    // Get voucher duration from zone config (if voucher auth is used)
    $voucher_duration = ZONE_DEFAULT_DURATION_MINUTES;
    if (isset($zone_cfg['voucher']) && isset($zone_cfg['voucher']['roll'])) {
        foreach ($zone_cfg['voucher']['roll'] as $roll) {
            if (isset($roll['minutes'])) {
                $voucher_duration = (int)$roll['minutes'];
                break; // Use first roll's duration
            }
        }
    }
    
    $queue_entries = [];
    
    foreach ($sessions as $session) {
        $mac = $session['mac'] ?? '';
        $ip = $session['ip'] ?? '';
        $username = $session['username'] ?? '';
        $allow_time = $session['allow_time'] ?? 0;
        $session_id = $session['sessionid'] ?? '';
        
        // Normalize MAC
        $mac = normalize_mac($mac);
        if (empty($mac)) {
            echo "  SKIP: Invalid MAC for session {$session_id}\n";
            $total_skipped++;
            continue;
        }
        
        // Calculate expiry
        // If allow_time exists, use it as start time and add duration
        // Otherwise use current time
        if ($allow_time > 0) {
            $expires_ts = $allow_time + ($voucher_duration * 60);
        } else {
            $expires_ts = time() + ($voucher_duration * 60);
        }
        
        // Skip if already expired
        if ($expires_ts <= time()) {
            echo "  SKIP: Session already expired for MAC {$mac}\n";
            $total_skipped++;
            continue;
        }
        
        $now_iso = gmdate('Y-m-d\TH:i:s\Z');
        $expires_iso = gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
        
        // Generate voucher hash from session ID (since we don't have original voucher)
        $voucher_hash = hash('sha256', "imported_{$session_id}_{$mac}");
        
        echo "  IMPORT: MAC={$mac}, IP={$ip}, User={$username}\n";
        echo "          Expires: {$expires_iso}\n";
        
        // Build CSV entry
        $csv_line = sprintf(
            "%s,%s,%s,%s,%s,%s\n",
            $now_iso,
            $zone_name_lower,
            $mac,
            $expires_iso,
            $voucher_hash,
            $ip
        );
        
        $queue_entries[] = $csv_line;
        $total_imported++;
    }
    
    // Write to queue file (unless dry run)
    if (!empty($queue_entries) && !$dry_run) {
        $queue_data = implode('', $queue_entries);
        
        if (is_writable(QUEUE_FILE) || (!file_exists(QUEUE_FILE) && is_writable(dirname(QUEUE_FILE)))) {
            if (file_put_contents(QUEUE_FILE, $queue_data, FILE_APPEND | LOCK_EX) !== false) {
                echo "\n  Written " . count($queue_entries) . " entries to queue\n";
                log_msg('INFO', "Imported " . count($queue_entries) . " sessions from zone {$zone_name}");
            } else {
                echo "\n  ERROR: Failed to write to queue file\n";
                $total_errors++;
            }
        } else {
            echo "\n  ERROR: Queue file not writable: " . QUEUE_FILE . "\n";
            $total_errors++;
        }
    }
    
    echo "\n";
}

// Summary
echo str_repeat('=', 50) . "\n";
echo "IMPORT SUMMARY\n";
echo str_repeat('=', 50) . "\n";
echo "Total imported: {$total_imported}\n";
echo "Total skipped:  {$total_skipped}\n";
echo "Total errors:   {$total_errors}\n";

if ($dry_run) {
    echo "\n*** DRY RUN - No changes were made ***\n";
    echo "Run without --dry-run to actually import.\n";
} else if ($total_imported > 0) {
    echo "\nNext step: Run sync to process imported entries:\n";
    echo "  /usr/local/sbin/macbind_sync.php\n";
}

log_msg('INFO', "Import completed: imported={$total_imported}, skipped={$total_skipped}, errors={$total_errors}");

exit($total_errors > 0 ? 1 : 0);
