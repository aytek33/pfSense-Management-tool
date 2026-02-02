#!/usr/local/bin/php
<?php
/**
 * macbind_manage.php
 * 
 * Management CLI for MAC Binding automation.
 * View, search, and remove MAC bindings.
 * 
 * Usage:
 *   /usr/local/sbin/macbind_manage.php <command> [options]
 * 
 * Commands:
 *   list       List all active MAC bindings
 *   search     Search for a specific MAC or IP
 *   remove     Remove a MAC binding
 *   stats      Show statistics
 *   export     Export bindings to CSV
 *   purge      Remove all expired entries
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
define('ACTIVE_DB_FILE', '/var/db/macbind_active.json');
define('LOG_FILE', '/var/log/macbind_sync.log');
define('TAG_PREFIX', 'AUTO_BIND:');

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function log_msg(string $level, string $message): void {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $entry = "[{$timestamp}] [{$level}] [manage] {$message}\n";
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

function load_active_db(): array {
    if (!file_exists(ACTIVE_DB_FILE)) {
        return ['version' => 1, 'updated_at' => '', 'bindings' => []];
    }
    
    $json = @file_get_contents(ACTIVE_DB_FILE);
    if ($json === false) {
        return ['version' => 1, 'updated_at' => '', 'bindings' => []];
    }
    
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['bindings'])) {
        return ['version' => 1, 'updated_at' => '', 'bindings' => []];
    }
    
    return $data;
}

function save_active_db(array $data): bool {
    $data['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    $tmp = ACTIVE_DB_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    
    return rename($tmp, ACTIVE_DB_FILE);
}

function format_duration(int $seconds): string {
    if ($seconds < 0) {
        return 'EXPIRED';
    }
    
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $mins = floor(($seconds % 3600) / 60);
    
    if ($days > 0) {
        return "{$days}d {$hours}h";
    } elseif ($hours > 0) {
        return "{$hours}h {$mins}m";
    } else {
        return "{$mins}m";
    }
}

function normalize_mac(string $mac): string {
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac));
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

function show_help(): void {
    echo <<<HELP
macbind_manage.php - MAC Binding Management Tool

Usage:
  /usr/local/sbin/macbind_manage.php <command> [options]

Commands:
  list [--zone=NAME] [--format=table|csv|json]
      List all active MAC bindings
      
  search <MAC|IP> [--zone=NAME]
      Search for a specific MAC address or IP
      
  remove <MAC> [--zone=NAME] [--force]
      Remove a MAC binding from active DB and pfSense config
      
  stats
      Show statistics about MAC bindings
      
  export [--file=PATH]
      Export all bindings to CSV file
      
  purge [--dry-run]
      Remove all expired entries from active DB

Options:
  --zone=NAME     Filter by captive portal zone
  --format=FMT    Output format: table (default), csv, json
  --force         Skip confirmation prompts
  --dry-run       Show what would be done without making changes
  --help          Show this help message

Examples:
  # List all bindings
  /usr/local/sbin/macbind_manage.php list
  
  # List bindings for specific zone
  /usr/local/sbin/macbind_manage.php list --zone=myzone
  
  # Search for a MAC
  /usr/local/sbin/macbind_manage.php search aa:bb:cc:dd:ee:ff
  
  # Remove a binding
  /usr/local/sbin/macbind_manage.php remove aa:bb:cc:dd:ee:ff
  
  # Export to CSV
  /usr/local/sbin/macbind_manage.php export --file=/tmp/bindings.csv

HELP;
}

// ============================================================================
// COMMANDS
// ============================================================================

function cmd_list(array $args): int {
    $zone_filter = $args['zone'] ?? null;
    $format = $args['format'] ?? 'table';
    
    $db = load_active_db();
    $bindings = $db['bindings'];
    
    if (empty($bindings)) {
        echo "No active MAC bindings found.\n";
        return 0;
    }
    
    // Filter by zone if specified
    if ($zone_filter !== null) {
        $zone_filter = strtolower($zone_filter);
        $bindings = array_filter($bindings, function($b) use ($zone_filter) {
            return strtolower($b['zone']) === $zone_filter;
        });
    }
    
    // Sort by zone, then MAC
    uasort($bindings, function($a, $b) {
        $cmp = strcmp($a['zone'], $b['zone']);
        return $cmp !== 0 ? $cmp : strcmp($a['mac'], $b['mac']);
    });
    
    $now = time();
    
    switch ($format) {
        case 'json':
            echo json_encode(array_values($bindings), JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'csv':
            echo "zone,mac,expires_at,remaining,src_ip,last_seen\n";
            foreach ($bindings as $b) {
                $expires_ts = strtotime($b['expires_at']);
                $remaining = $expires_ts - $now;
                echo sprintf(
                    "%s,%s,%s,%s,%s,%s\n",
                    $b['zone'],
                    $b['mac'],
                    $b['expires_at'],
                    format_duration($remaining),
                    $b['src_ip'] ?? '',
                    $b['last_seen'] ?? ''
                );
            }
            break;
            
        case 'table':
        default:
            // Table header
            echo "\n";
            echo str_pad("ZONE", 15) . " | ";
            echo str_pad("MAC ADDRESS", 19) . " | ";
            echo str_pad("EXPIRES", 22) . " | ";
            echo str_pad("REMAINING", 12) . " | ";
            echo "SRC IP\n";
            echo str_repeat('-', 90) . "\n";
            
            foreach ($bindings as $b) {
                $expires_ts = strtotime($b['expires_at']);
                $remaining = $expires_ts - $now;
                $remaining_str = format_duration($remaining);
                
                // Highlight expired entries
                if ($remaining < 0) {
                    $remaining_str = "\033[31m" . $remaining_str . "\033[0m"; // Red
                } elseif ($remaining < 3600) {
                    $remaining_str = "\033[33m" . $remaining_str . "\033[0m"; // Yellow
                }
                
                echo str_pad($b['zone'], 15) . " | ";
                echo str_pad($b['mac'], 19) . " | ";
                echo str_pad($b['expires_at'], 22) . " | ";
                echo str_pad($remaining_str, 20) . " | "; // Extra padding for color codes
                echo ($b['src_ip'] ?? '-') . "\n";
            }
            
            echo str_repeat('-', 90) . "\n";
            echo "Total: " . count($bindings) . " binding(s)\n";
            echo "Last updated: " . ($db['updated_at'] ?? 'unknown') . "\n\n";
            break;
    }
    
    return 0;
}

function cmd_search(array $args): int {
    $search = $args['search'] ?? '';
    $zone_filter = $args['zone'] ?? null;
    
    if (empty($search)) {
        echo "ERROR: Please specify a MAC address or IP to search for.\n";
        echo "Usage: macbind_manage.php search <MAC|IP>\n";
        return 1;
    }
    
    // Normalize if it looks like a MAC
    $search_mac = normalize_mac($search);
    $search_lower = strtolower($search);
    
    $db = load_active_db();
    $found = [];
    
    foreach ($db['bindings'] as $key => $binding) {
        $match = false;
        
        // Match by MAC
        if ($search_mac !== '' && $binding['mac'] === $search_mac) {
            $match = true;
        }
        
        // Match by partial MAC
        if (!$match && strpos($binding['mac'], $search_lower) !== false) {
            $match = true;
        }
        
        // Match by IP
        if (!$match && isset($binding['src_ip']) && $binding['src_ip'] === $search) {
            $match = true;
        }
        
        // Filter by zone
        if ($match && $zone_filter !== null && strtolower($binding['zone']) !== strtolower($zone_filter)) {
            $match = false;
        }
        
        if ($match) {
            $found[$key] = $binding;
        }
    }
    
    if (empty($found)) {
        echo "No bindings found matching: {$search}\n";
        return 0;
    }
    
    echo "\nFound " . count($found) . " matching binding(s):\n\n";
    
    $now = time();
    foreach ($found as $key => $b) {
        $expires_ts = strtotime($b['expires_at']);
        $remaining = $expires_ts - $now;
        
        echo "Key:        {$key}\n";
        echo "Zone:       {$b['zone']}\n";
        echo "MAC:        {$b['mac']}\n";
        echo "Expires:    {$b['expires_at']}\n";
        echo "Remaining:  " . format_duration($remaining) . "\n";
        echo "Source IP:  " . ($b['src_ip'] ?? 'unknown') . "\n";
        echo "Last seen:  " . ($b['last_seen'] ?? 'unknown') . "\n";
        echo "Voucher:    " . substr($b['voucher_hash'] ?? '', 0, 16) . "...\n";
        echo str_repeat('-', 50) . "\n";
    }
    
    return 0;
}

function cmd_remove(array $args): int {
    $mac_input = $args['mac'] ?? '';
    $zone_filter = $args['zone'] ?? null;
    $force = $args['force'] ?? false;
    
    if (empty($mac_input)) {
        echo "ERROR: Please specify a MAC address to remove.\n";
        echo "Usage: macbind_manage.php remove <MAC> [--zone=NAME]\n";
        return 1;
    }
    
    $mac = normalize_mac($mac_input);
    if ($mac === '') {
        echo "ERROR: Invalid MAC address format: {$mac_input}\n";
        return 1;
    }
    
    $db = load_active_db();
    $to_remove = [];
    
    // Find matching bindings
    foreach ($db['bindings'] as $key => $binding) {
        if ($binding['mac'] === $mac) {
            if ($zone_filter === null || strtolower($binding['zone']) === strtolower($zone_filter)) {
                $to_remove[$key] = $binding;
            }
        }
    }
    
    if (empty($to_remove)) {
        echo "No binding found for MAC: {$mac}";
        if ($zone_filter) {
            echo " in zone: {$zone_filter}";
        }
        echo "\n";
        return 0;
    }
    
    echo "\nFound " . count($to_remove) . " binding(s) to remove:\n\n";
    
    foreach ($to_remove as $key => $b) {
        echo "  Zone: {$b['zone']}, MAC: {$b['mac']}, IP: " . ($b['src_ip'] ?? 'unknown') . "\n";
    }
    
    // Confirm unless --force
    if (!$force) {
        echo "\nAre you sure you want to remove these binding(s)? [y/N]: ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        if (strtolower(trim($line)) !== 'y') {
            echo "Aborted.\n";
            return 0;
        }
    }
    
    // Remove from active DB
    foreach (array_keys($to_remove) as $key) {
        unset($db['bindings'][$key]);
    }
    
    if (!save_active_db($db)) {
        echo "ERROR: Failed to save active database.\n";
        return 1;
    }
    
    log_msg('INFO', "Manually removed " . count($to_remove) . " binding(s) for MAC {$mac}");
    
    echo "\nRemoved " . count($to_remove) . " binding(s) from active database.\n";
    echo "\nNOTE: Run sync to apply changes to pfSense config:\n";
    echo "  /usr/local/sbin/macbind_sync.php\n\n";
    
    // Also try to remove from pfSense config directly
    if (file_exists('/etc/inc/config.inc')) {
        echo "Attempting to remove from pfSense config...\n";
        
        require_once('/etc/inc/config.inc');
        require_once('/etc/inc/captiveportal.inc');
        
        global $config;
        $config_changed = false;
        
        foreach ($to_remove as $binding) {
            $zone = $binding['zone'];
            
            if (isset($config['captiveportal'])) {
                foreach ($config['captiveportal'] as $zone_id => &$zone_cfg) {
                    $zone_name = $zone_cfg['zone'] ?? $zone_id;
                    
                    if (strtolower($zone_name) !== strtolower($zone)) {
                        continue;
                    }
                    
                    if (!isset($zone_cfg['passthrumac']) || !is_array($zone_cfg['passthrumac'])) {
                        continue;
                    }
                    
                    $new_list = [];
                    foreach ($zone_cfg['passthrumac'] as $entry) {
                        // Check if this is a voucher-based passthrumac entry
                        // pfSense 2.7.2 uses: descr="Auto-added for voucher {username}" and logintype="voucher"
                        // Also check for our legacy TAG_PREFIX for backward compatibility
                        $is_voucher_entry = false;
                        if (isset($entry['logintype']) && $entry['logintype'] === 'voucher') {
                            $is_voucher_entry = true;
                        } elseif (isset($entry['descr'])) {
                            $descr = $entry['descr'];
                            if (strpos($descr, 'Auto-added for voucher') === 0 ||
                                strpos($descr, TAG_PREFIX) === 0) {
                                $is_voucher_entry = true;
                            }
                        }
                        
                        // Only remove voucher-based entries matching our MAC
                        if ($is_voucher_entry && strtolower($entry['mac']) === $mac) {
                            echo "  Removed from pfSense zone {$zone_name}\n";
                            $config_changed = true;
                            continue;
                        }
                        $new_list[] = $entry;
                    }
                    $zone_cfg['passthrumac'] = $new_list;
                }
                unset($zone_cfg);
            }
        }
        
        if ($config_changed) {
            write_config("macbind_manage: manually removed MAC {$mac}");
            echo "\npfSense config updated.\n";
        }
    }
    
    return 0;
}

function cmd_stats(array $args): int {
    $db = load_active_db();
    $bindings = $db['bindings'];
    
    $now = time();
    $total = count($bindings);
    $by_zone = [];
    $expired = 0;
    $expiring_soon = 0; // Within 1 hour
    $expiring_today = 0; // Within 24 hours
    
    foreach ($bindings as $b) {
        $zone = $b['zone'];
        $by_zone[$zone] = ($by_zone[$zone] ?? 0) + 1;
        
        $expires_ts = strtotime($b['expires_at']);
        $remaining = $expires_ts - $now;
        
        if ($remaining < 0) {
            $expired++;
        } elseif ($remaining < 3600) {
            $expiring_soon++;
        } elseif ($remaining < 86400) {
            $expiring_today++;
        }
    }
    
    echo "\n=== MAC Binding Statistics ===\n\n";
    echo "Total active bindings: {$total}\n";
    echo "Expired (pending cleanup): {$expired}\n";
    echo "Expiring within 1 hour: {$expiring_soon}\n";
    echo "Expiring within 24 hours: {$expiring_today}\n";
    echo "\nBindings by zone:\n";
    
    foreach ($by_zone as $zone => $count) {
        echo "  {$zone}: {$count}\n";
    }
    
    // Queue stats
    if (file_exists(QUEUE_FILE)) {
        $queue_lines = count(file(QUEUE_FILE, FILE_SKIP_EMPTY_LINES));
        echo "\nQueue file: {$queue_lines} pending entries\n";
    } else {
        echo "\nQueue file: empty\n";
    }
    
    echo "\nLast database update: " . ($db['updated_at'] ?? 'unknown') . "\n\n";
    
    return 0;
}

function cmd_export(array $args): int {
    $file = $args['file'] ?? '/tmp/macbind_export_' . date('Ymd_His') . '.csv';
    
    $db = load_active_db();
    $bindings = $db['bindings'];
    
    if (empty($bindings)) {
        echo "No bindings to export.\n";
        return 0;
    }
    
    $csv = "zone,mac,expires_at,voucher_hash,src_ip,last_seen\n";
    
    foreach ($bindings as $b) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s,%s\n",
            $b['zone'],
            $b['mac'],
            $b['expires_at'],
            $b['voucher_hash'] ?? '',
            $b['src_ip'] ?? '',
            $b['last_seen'] ?? ''
        );
    }
    
    if (file_put_contents($file, $csv) === false) {
        echo "ERROR: Failed to write to {$file}\n";
        return 1;
    }
    
    echo "Exported " . count($bindings) . " binding(s) to: {$file}\n";
    return 0;
}

function cmd_purge(array $args): int {
    $dry_run = $args['dry-run'] ?? false;
    
    $db = load_active_db();
    $now = time();
    $expired = [];
    
    foreach ($db['bindings'] as $key => $b) {
        $expires_ts = strtotime($b['expires_at']);
        if ($expires_ts < $now) {
            $expired[$key] = $b;
        }
    }
    
    if (empty($expired)) {
        echo "No expired entries to purge.\n";
        return 0;
    }
    
    echo "Found " . count($expired) . " expired binding(s):\n\n";
    
    foreach ($expired as $key => $b) {
        echo "  {$b['zone']} | {$b['mac']} | expired: {$b['expires_at']}\n";
    }
    
    if ($dry_run) {
        echo "\n*** DRY RUN - No changes made ***\n";
        return 0;
    }
    
    // Remove expired entries
    foreach (array_keys($expired) as $key) {
        unset($db['bindings'][$key]);
    }
    
    if (!save_active_db($db)) {
        echo "\nERROR: Failed to save database.\n";
        return 1;
    }
    
    log_msg('INFO', "Manually purged " . count($expired) . " expired binding(s)");
    
    echo "\nPurged " . count($expired) . " expired binding(s).\n";
    echo "Run sync to update pfSense config: /usr/local/sbin/macbind_sync.php\n";
    
    return 0;
}

// ============================================================================
// MAIN
// ============================================================================

// Ensure CLI
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "ERROR: This script must be run from command line.\n");
    exit(1);
}

// Check root
if (posix_getuid() !== 0) {
    fwrite(STDERR, "ERROR: This script must be run as root.\n");
    exit(1);
}

// Parse arguments
$command = null;
$args = [];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    
    if ($arg === '--help' || $arg === '-h') {
        show_help();
        exit(0);
    }
    
    if (strpos($arg, '--') === 0) {
        // Parse --key=value or --flag
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        $value = $parts[1] ?? true;
        $args[$key] = $value;
    } elseif ($command === null) {
        $command = $arg;
    } else {
        // Positional arguments based on command
        switch ($command) {
            case 'search':
                $args['search'] = $arg;
                break;
            case 'remove':
                $args['mac'] = $arg;
                break;
        }
    }
}

if ($command === null) {
    show_help();
    exit(0);
}

// Execute command
switch ($command) {
    case 'list':
    case 'ls':
        exit(cmd_list($args));
        
    case 'search':
    case 'find':
        exit(cmd_search($args));
        
    case 'remove':
    case 'rm':
    case 'delete':
        exit(cmd_remove($args));
        
    case 'stats':
    case 'status':
        exit(cmd_stats($args));
        
    case 'export':
        exit(cmd_export($args));
        
    case 'purge':
    case 'cleanup':
        exit(cmd_purge($args));
        
    default:
        echo "Unknown command: {$command}\n";
        echo "Run with --help for usage information.\n";
        exit(1);
}
