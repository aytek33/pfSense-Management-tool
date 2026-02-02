#!/usr/local/bin/php
<?php
/**
 * macbind_sync.php
 * 
 * Root-run sync & cleanup script for automatic MAC binding in pfSense Captive Portal.
 * Processes queue of voucher authentications, maintains active bindings DB,
 * and syncs to pfSense pass-through MAC list with automatic expiry cleanup.
 * 
 * Usage:
 *   /usr/local/sbin/macbind_sync.php [--dry-run] [--selftest]
 * 
 * Must be run as root. Designed for cron execution every 1 minute.
 * 
 * @version 1.0.0
 * @license BSD-2-Clause
 */

declare(strict_types=1);

// ============================================================================
// CONFIGURATION
// ============================================================================

define('QUEUE_FILE', '/var/db/macbind_queue.csv');
define('ACTIVE_DB_FILE', '/var/db/macbind_active.json');
define('LOG_FILE', '/var/log/macbind_sync.log');
define('BACKUP_DIR', '/conf/macbind_backups');
define('LOCK_FILE', '/var/run/macbind_sync.lock');
define('DISABLE_FLAG', '/var/db/macbind_disabled');

// Processing limits
define('MAX_QUEUE_LINES', 2000);
define('LOG_MAX_SIZE', 5 * 1024 * 1024); // 5MB

// Default voucher duration if not provided (30 days = 43200 minutes)
define('ZONE_DEFAULT_DURATION_MINUTES', 43200);

// Tag prefix for managed pass-through MAC entries
define('TAG_PREFIX', 'AUTO_BIND:');

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Log a message to the sync log file with timestamp and rotation
 */
function log_msg(string $level, string $message): void {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $entry = "[{$timestamp}] [{$level}] {$message}\n";
    
    // Rotate log if too large
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_SIZE) {
        $backup = LOG_FILE . '.' . gmdate('Ymd_His');
        @rename(LOG_FILE, $backup);
    }
    
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Normalize MAC address to lowercase colon-separated format
 */
function normalize_mac(string $mac): string {
    // Remove any non-hex characters and convert to lowercase
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac));
    
    if (strlen($hex) !== 12) {
        return '';
    }
    
    // Format as colon-separated
    return implode(':', str_split($hex, 2));
}

/**
 * Parse ISO8601 timestamp to Unix timestamp
 */
function parse_iso8601(string $datetime): ?int {
    $ts = strtotime($datetime);
    return ($ts === false) ? null : $ts;
}

/**
 * Get current UTC time in ISO8601 format
 */
function iso8601_now(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Check if running as root
 */
function ensure_root(): void {
    if (posix_getuid() !== 0) {
        fwrite(STDERR, "ERROR: This script must be run as root.\n");
        exit(1);
    }
}

/**
 * Acquire exclusive lock to prevent concurrent runs
 * @return resource|false Lock file handle or false on failure
 */
function acquire_lock() {
    $fp = @fopen(LOCK_FILE, 'c');
    if (!$fp) {
        log_msg('ERROR', "Cannot open lock file: " . LOCK_FILE);
        return false;
    }
    
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Another instance is running
        fclose($fp);
        return false;
    }
    
    // Write PID for debugging
    ftruncate($fp, 0);
    fwrite($fp, (string)getmypid());
    
    return $fp;
}

/**
 * Release lock
 */
function release_lock($fp): void {
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ============================================================================
// QUEUE PROCESSING
// ============================================================================

/**
 * Read and parse queue file, returning up to MAX_QUEUE_LINES entries
 * @return array{entries: array, lines_read: int, lines_remaining: int}
 */
function read_queue(): array {
    $result = [
        'entries' => [],
        'lines_read' => 0,
        'lines_remaining' => 0
    ];

    if (!file_exists(QUEUE_FILE)) {
        return $result;
    }

    $fp = @fopen(QUEUE_FILE, 'r');
    if (!$fp) {
        log_msg('ERROR', "Cannot open queue file for reading");
        return $result;
    }

    // Acquire SHARED lock for reading - allows other readers but blocks writers
    // This prevents race condition with portal_mac_to_voucher.php writing to queue
    // LOCK_SH: Multiple readers OK, writers must wait
    // LOCK_NB: Non-blocking - if can't get lock immediately, skip this cycle
    if (!flock($fp, LOCK_SH | LOCK_NB)) {
        log_msg('DEBUG', "Queue file busy (writer active), will retry next cycle");
        fclose($fp);
        return $result;
    }

    $lines_read = 0;
    $all_lines = [];

    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $all_lines[] = $line;
    }

    // Release lock before closing
    flock($fp, LOCK_UN);
    fclose($fp);
    
    $total = count($all_lines);
    $to_process = array_slice($all_lines, 0, MAX_QUEUE_LINES);
    
    foreach ($to_process as $line) {
        $lines_read++;
        
        // Parse CSV: ts_iso,zone,mac,expires_at_iso,voucher_hash,src_ip
        $parts = str_getcsv($line);
        if (count($parts) !== 6) {
            log_msg('WARN', "Malformed queue line (wrong field count): {$line}");
            continue;
        }
        
        list($ts_iso, $zone, $mac_raw, $expires_iso, $voucher_hash, $src_ip) = $parts;
        
        // Validate and normalize
        $mac = normalize_mac($mac_raw);
        if ($mac === '') {
            log_msg('WARN', "Invalid MAC address in queue: {$mac_raw}");
            continue;
        }
        
        $expires_ts = parse_iso8601($expires_iso);
        if ($expires_ts === null) {
            log_msg('WARN', "Invalid expires_at in queue: {$expires_iso}");
            continue;
        }
        
        $result['entries'][] = [
            'ts' => $ts_iso,
            'zone' => strtolower(trim($zone)),
            'mac' => $mac,
            'expires_at' => $expires_iso,
            'expires_ts' => $expires_ts,
            'voucher_hash' => trim($voucher_hash),
            'src_ip' => trim($src_ip)
        ];
    }
    
    $result['lines_read'] = $lines_read;
    $result['lines_remaining'] = max(0, $total - MAX_QUEUE_LINES);
    
    return $result;
}

/**
 * Remove processed lines from queue file (keep remainder)
 * Uses EXCLUSIVE lock to prevent race condition with writers
 */
function truncate_queue(int $lines_to_remove): void {
    if (!file_exists(QUEUE_FILE) || $lines_to_remove <= 0) {
        return;
    }

    // Open for read+write to get exclusive lock
    $fp = @fopen(QUEUE_FILE, 'r+');
    if (!$fp) {
        log_msg('WARN', "Cannot open queue file for truncation");
        return;
    }

    // Acquire EXCLUSIVE lock - blocks both readers and writers
    // This ensures no new entries are added while we're truncating
    // Use blocking lock here since we need to complete the truncation
    if (!flock($fp, LOCK_EX)) {
        log_msg('WARN', "Cannot acquire exclusive lock on queue file");
        fclose($fp);
        return;
    }

    $remaining = [];
    $count = 0;

    while (($line = fgets($fp)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') {
            continue;
        }
        $count++;
        if ($count > $lines_to_remove) {
            $remaining[] = $line;
        }
    }

    // Truncate and rewrite the file while holding the lock
    ftruncate($fp, 0);
    rewind($fp);
    if (!empty($remaining)) {
        fwrite($fp, implode('', $remaining));
    }

    // Release lock and close
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ============================================================================
// ACTIVE DB MANAGEMENT
// ============================================================================

/**
 * Load active bindings database
 */
function load_active_db(): array {
    $default = [
        'version' => 1,
        'updated_at' => iso8601_now(),
        'bindings' => []
    ];
    
    if (!file_exists(ACTIVE_DB_FILE)) {
        return $default;
    }
    
    $json = @file_get_contents(ACTIVE_DB_FILE);
    if ($json === false) {
        log_msg('ERROR', "Cannot read active DB file");
        return $default;
    }
    
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['bindings']) || !is_array($data['bindings'])) {
        log_msg('WARN', "Active DB file malformed, resetting");
        return $default;
    }
    
    return $data;
}

/**
 * Save active bindings database atomically
 */
function save_active_db(array $data): bool {
    $data['version'] = 1;
    $data['updated_at'] = iso8601_now();
    
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        log_msg('ERROR', "Failed to encode active DB as JSON");
        return false;
    }
    
    $tmp = ACTIVE_DB_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        log_msg('ERROR', "Failed to write active DB temp file");
        return false;
    }
    
    if (!rename($tmp, ACTIVE_DB_FILE)) {
        log_msg('ERROR', "Failed to rename active DB temp file");
        @unlink($tmp);
        return false;
    }
    
    return true;
}

// ============================================================================
// CAPTIVE PORTAL SESSION MONITORING
// ============================================================================

/**
 * Read active sessions from captive portal database
 * Returns array of active MAC addresses with their session info
 *
 * @param string $zone Zone name
 * @return array{mac => session_info}
 */
function read_captiveportal_sessions(string $zone): array {
    $sessions = [];
    $db_path = "/var/db/captiveportal{$zone}.db";

    if (!file_exists($db_path)) {
        log_msg('DEBUG', "Captive portal DB not found for zone {$zone}: {$db_path}");
        return $sessions;
    }

    // SAFETY: We use SQLITE3_OPEN_READONLY to ensure we NEVER modify pfSense's DB
    // This is critical - pfSense may delete and recreate the DB on errors
    // We must not interfere with its locking or cause any write conflicts

    $db = null;
    try {
        // Open in read-only mode - this only acquires a SHARED lock, not EXCLUSIVE
        // SQLite allows multiple readers simultaneously
        $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);

        // Short timeout - if pfSense is writing, we wait briefly then give up
        // This prevents us from blocking pfSense operations
        // pfSense uses 60000ms, we use 1000ms to be non-intrusive
        $db->busyTimeout(1000);

        // Quick read - minimize time holding the shared lock
        $result = $db->query("SELECT mac, ip, username, sessionid, allow_time FROM captiveportal");

        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $mac = strtolower($row['mac']);
                if ($mac !== '') {
                    $sessions[$mac] = [
                        'ip' => $row['ip'],
                        'username' => $row['username'],
                        'sessionid' => $row['sessionid'],
                        'allow_time' => $row['allow_time']
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Log but don't fail - if we can't read, we simply skip session-based removal
        // This is safe because expires_at cleanup still works as backup
        log_msg('WARN', "Could not read captive portal DB for zone {$zone}: " . $e->getMessage());
    } finally {
        // ALWAYS close the DB connection to release the lock
        if ($db !== null) {
            try {
                $db->close();
            } catch (Exception $e) {
                // Ignore close errors
            }
        }
    }

    return $sessions;
}

/**
 * Check if passthrumacadd is enabled for a zone
 * When enabled, authenticated users are added to passthrumac and bypass the portal
 * on subsequent connections (they won't appear in captiveportal.db)
 *
 * @param string $zone Zone name
 * @return bool True if passthrumacadd is enabled
 */
function is_passthrumacadd_enabled(string $zone): bool {
    global $config;

    if (!isset($config['captiveportal'][$zone])) {
        return false;
    }

    return isset($config['captiveportal'][$zone]['passthrumacadd']);
}

/**
 * Check if a MAC is already in pfSense passthrumac config
 * MACs in passthrumac bypass the portal and won't appear in session DB
 *
 * @param string $zone Zone name
 * @param string $mac MAC address (lowercase, colon-separated)
 * @return bool True if MAC is in passthrumac list
 */
function is_mac_in_passthrumac(string $zone, string $mac): bool {
    global $config;

    if (!isset($config['captiveportal'][$zone]['passthrumac'])) {
        return false;
    }

    $passthrumacs = $config['captiveportal'][$zone]['passthrumac'];

    // Handle single entry (not wrapped in array)
    if (isset($passthrumacs['mac'])) {
        $passthrumacs = [$passthrumacs];
    }

    foreach ($passthrumacs as $entry) {
        if (isset($entry['mac']) && strtolower($entry['mac']) === $mac) {
            return true;
        }
    }

    return false;
}

/**
 * Check all active MAC bindings against captive portal sessions
 * Remove bindings where the session no longer exists (user logged out or timed out)
 *
 * IMPORTANT: Our MAC binding system uses its own expiry mechanism (expires_at field).
 * Session-based removal is DISABLED by default because:
 * 1. When passthrumacadd is disabled, users appear in session DB only while connected
 * 2. When user disconnects from WiFi, session disappears but MAC binding should persist
 * 3. Our system relies on expires_at for cleanup, not pfSense session state
 *
 * Session-based removal is only used when explicitly enabled via config flag.
 *
 * @param array &$active_bindings Reference to active bindings array
 * @return int Number of bindings removed due to session termination
 */
function sync_sessions_with_bindings(array &$active_bindings): int {
    global $config;
    $removed_count = 0;

    // DISABLED: Session-based removal causes premature MAC deletion
    // Our system uses expires_at for cleanup instead of pfSense session state
    //
    // When user authenticates with voucher:
    // 1. Hook writes MAC to queue with expires_at
    // 2. Sync adds MAC to active_bindings and pfSense passthrumac
    // 3. MAC stays until expires_at, regardless of session state
    //
    // If we enable session-based removal, MACs get deleted when:
    // - User disconnects from WiFi (session disappears)
    // - User closes browser (session may timeout)
    // - pfSense restarts (sessions cleared)
    //
    // This is NOT what we want - MAC should persist until voucher expires.

    log_msg('DEBUG', "Session-based removal is disabled - using expires_at for cleanup");
    return $removed_count;

    // LEGACY CODE BELOW - kept for reference but not executed
    // To re-enable, remove the return statement above

    // Group bindings by zone
    $bindings_by_zone = [];
    foreach ($active_bindings as $key => $binding) {
        $zone = $binding['zone'];
        if (!isset($bindings_by_zone[$zone])) {
            $bindings_by_zone[$zone] = [];
        }
        $bindings_by_zone[$zone][$key] = $binding;
    }

    // Check each zone's sessions
    foreach ($bindings_by_zone as $zone => $zone_bindings) {
        // Skip session-based removal if passthrumacadd is enabled
        // Users with pass-through MACs bypass the portal and don't appear in session DB
        if (is_passthrumacadd_enabled($zone)) {
            log_msg('DEBUG', "Zone {$zone} has passthrumacadd enabled - skipping session-based removal (relying on expires_at)");
            continue;
        }

        $active_sessions = read_captiveportal_sessions($zone);

        // If we couldn't read sessions (DB error), don't remove anything
        if (empty($active_sessions) && count($zone_bindings) > 0) {
            // Check if DB file exists - if not, this might be a config issue
            $db_path = "/var/db/captiveportal{$zone}.db";
            if (!file_exists($db_path)) {
                log_msg('WARN', "Zone {$zone}: No session DB found, skipping session-based removal");
                continue;
            }
            // DB exists but is empty - could be legitimate (no active sessions)
            // or could indicate a read error. Be conservative.
            log_msg('DEBUG', "Zone {$zone}: Session DB empty, proceeding with session-based removal");
        }

        foreach ($zone_bindings as $key => $binding) {
            $mac = $binding['mac'];

            // Skip if MAC is already in pfSense passthrumac config
            // These MACs bypass the portal and won't appear in session DB
            // They should only be removed via expires_at, not session-based removal
            if (is_mac_in_passthrumac($zone, $mac)) {
                log_msg('DEBUG', "MAC {$mac} is in passthrumac for zone {$zone} - skipping session check (using expires_at only)");
                continue;
            }

            // Check if this MAC has an active session
            if (!isset($active_sessions[$mac])) {
                // No active session for this MAC - user logged out or session timed out
                unset($active_bindings[$key]);
                $removed_count++;
                log_msg('INFO', "Session-based removal: {$mac} in zone {$zone} (session no longer active)");
            }
        }
    }

    return $removed_count;
}

// ============================================================================
// PFSENSE AUTO-ADDED MAC CLEANUP
// ============================================================================

/**
 * Scan pfSense passthrumac for auto-added voucher MACs and remove expired ones
 *
 * When "Pass-through MAC Auto Entry" is enabled, pfSense adds MACs with description:
 * - "Auto-added for voucher XXXX"
 *
 * This function:
 * 1. Finds all auto-added voucher MACs in pfSense config
 * 2. Checks if they exist in our active_bindings DB with expires_at
 * 3. Removes MACs that are expired from pfSense config
 *
 * @param array $active_bindings Our active bindings with expires_at
 * @param bool $dry_run If true, don't make changes
 * @return array{removed: int, errors: int}
 */
function cleanup_expired_pfsense_macs(array $active_bindings, bool $dry_run = false): array {
    global $config, $cpzone;

    $result = ['removed' => 0, 'errors' => 0];
    $config_changed = false;
    $zones_to_reload = [];
    $macs_to_disconnect = [];  // Track MACs that need firewall rules removed
    $now_ts = time();

    if (!isset($config['captiveportal']) || !is_array($config['captiveportal'])) {
        return $result;
    }

    // Build lookup map: mac -> binding (for quick expiry check)
    // Normalize MAC to lowercase colon-separated format for consistent matching
    $bindings_by_mac = [];
    foreach ($active_bindings as $binding) {
        $mac = normalize_mac($binding['mac']);
        if (!empty($mac)) {
            $bindings_by_mac[$mac] = $binding;
        }
    }

    // Process each captive portal zone
    foreach ($config['captiveportal'] as $zone_id => &$zone_cfg) {
        $zone_name = $zone_cfg['zone'] ?? $zone_id;

        if (!isset($zone_cfg['passthrumac']) || !is_array($zone_cfg['passthrumac'])) {
            continue;
        }

        $new_passthrumac = [];

        foreach ($zone_cfg['passthrumac'] as $entry) {
            $keep = true;
            $mac_raw = $entry['mac'] ?? '';
            $mac = normalize_mac($mac_raw);  // Normalize for consistent matching
            $descr = $entry['descr'] ?? '';

            // Check if this is a pfSense auto-added voucher MAC
            // pfSense uses: "Auto-added for voucher XXXX"
            $is_pfsense_auto_added = (strpos($descr, 'Auto-added for voucher') !== false);

            // Also check our own tag: "AUTO_BIND:hash"
            $is_our_managed = (strpos($descr, TAG_PREFIX) === 0);

            if (($is_pfsense_auto_added || $is_our_managed) && !empty($mac)) {
                // Check if this MAC has an active binding with valid expiry
                if (isset($bindings_by_mac[$mac])) {
                    $binding = $bindings_by_mac[$mac];
                    $expires_ts = parse_iso8601($binding['expires_at']);

                    if ($expires_ts !== null && $expires_ts <= $now_ts) {
                        // Expired - remove from pfSense
                        $keep = false;
                        $result['removed']++;
                        log_msg('INFO', "Removing expired MAC {$mac} from zone {$zone_name} (expired: {$binding['expires_at']})");

                        if (!$dry_run) {
                            $config_changed = true;
                            $zones_to_reload[$zone_name] = true;
                            // Track for firewall rule removal
                            $macs_to_disconnect[] = [
                                'zone' => $zone_name,
                                'entry' => $entry  // Original entry with MAC format pfSense expects
                            ];
                        }
                    }
                    // Not expired - keep it
                } else {
                    // MAC is in pfSense but NOT in our DB
                    // This could be:
                    // 1. pfSense auto-added it but hook didn't run (queue issue)
                    // 2. Our DB was cleared
                    //
                    // For safety, we DON'T remove it automatically
                    // Admin should manually clean these up or they'll persist
                    log_msg('DEBUG', "MAC {$mac} in pfSense (zone {$zone_name}) but not in our DB - keeping (manual cleanup needed)");
                }
            }

            if ($keep) {
                $new_passthrumac[] = $entry;
            }
        }

        if (!$dry_run && $config_changed) {
            $zone_cfg['passthrumac'] = $new_passthrumac;
        }
    }
    unset($zone_cfg);

    // Write config and reload if changes occurred
    if ($config_changed && !$dry_run) {
        // FIRST: Remove firewall rules for expired MACs (disconnect users immediately)
        foreach ($macs_to_disconnect as $item) {
            $zone = $item['zone'];
            $macent = $item['entry'];

            // Set global cpzone for pfSense functions
            $cpzone = $zone;

            // Use pfSense function to properly remove firewall rules
            if (function_exists('captiveportal_passthrumac_delete_entry')) {
                captiveportal_passthrumac_delete_entry($macent);
                log_msg('INFO', "Disconnected MAC {$macent['mac']} from zone {$zone} (firewall rules removed)");
            } else {
                // Fallback: try to flush manually
                $mac_clean = str_replace(":", "", $macent['mac']);
                $mac_clean = str_replace("/", "_", $mac_clean);
                if (isset($config['captiveportal'][$zone]['zoneid'])) {
                    $zoneid = $config['captiveportal'][$zone]['zoneid'];
                    $cpzoneprefix = "cpzone" . $zoneid;
                    // Try to flush using pfSense function
                    if (function_exists('pfSense_pf_cp_flush')) {
                        pfSense_pf_cp_flush("{$cpzoneprefix}_passthrumac/{$mac_clean}", "ether");
                        log_msg('INFO', "Flushed firewall rules for MAC {$macent['mac']} in zone {$zone}");
                    }
                }
            }
        }

        // THEN: Save config
        macbind_backup_config();
        write_config("macbind_sync: removing expired auto-added MACs (-{$result['removed']})");

        // Reload zones to ensure consistency
        foreach (array_keys($zones_to_reload) as $zone_name) {
            log_msg('INFO', "Reloading captive portal zone after cleanup: {$zone_name}");
            $cpzone = $zone_name;  // Set global for pfSense functions
            if (function_exists('captiveportal_configure_zone')) {
                captiveportal_configure_zone($zone_name);
            } elseif (function_exists('captiveportal_configure')) {
                captiveportal_configure();
            }
        }
    }

    return $result;
}

/**
 * Scan pfSense passthrumac for auto-added voucher MACs and import to our DB
 *
 * When "Pass-through MAC Auto Entry" is enabled, pfSense adds MACs automatically.
 * This function finds those MACs and adds them to our active_bindings DB so we can
 * track their expiry.
 *
 * @param array &$active_bindings Reference to active bindings array
 * @param string $zone Zone name to scan
 * @return int Number of MACs imported
 */
function import_pfsense_auto_added_macs(array &$active_bindings, string $zone): int {
    global $config;

    $imported = 0;
    $zone_lower = strtolower($zone);

    if (!isset($config['captiveportal'][$zone]['passthrumac'])) {
        return $imported;
    }

    $passthrumac = $config['captiveportal'][$zone]['passthrumac'];

    // Handle single entry case
    if (isset($passthrumac['mac'])) {
        $passthrumac = [$passthrumac];
    }

    // Get voucher roll duration for this zone (default expiry)
    $default_duration_minutes = ZONE_DEFAULT_DURATION_MINUTES;
    if (isset($config['voucher'][$zone]['rollbits'])) {
        // Try to get from voucher config
        $rolls = $config['voucher'][$zone]['roll'] ?? [];
        if (!empty($rolls)) {
            foreach ($rolls as $roll) {
                if (isset($roll['minutes'])) {
                    $default_duration_minutes = (int)$roll['minutes'];
                    break;
                }
            }
        }
    }

    foreach ($passthrumac as $entry) {
        $mac_raw = $entry['mac'] ?? '';
        $descr = $entry['descr'] ?? '';

        // Normalize MAC using standard function
        $mac_normalized = normalize_mac($mac_raw);
        if (empty($mac_normalized)) {
            continue;
        }

        // Check if pfSense auto-added this for a voucher
        if (strpos($descr, 'Auto-added for voucher') === false) {
            continue;
        }

        // Check if already in our DB
        $key = $zone_lower . '|' . $mac_normalized;
        if (isset($active_bindings[$key])) {
            continue; // Already tracked
        }

        // Extract voucher code from description
        // Format: "Auto-added for voucher XXXX"
        $voucher_code = '';
        if (preg_match('/Auto-added for voucher\s+(\S+)/', $descr, $matches)) {
            $voucher_code = $matches[1];
        }

        // Calculate expiry - since we don't know when it was added, use current time + duration
        // This is a best-effort estimate
        $expires_ts = time() + ($default_duration_minutes * 60);
        $expires_iso = gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
        $now_iso = gmdate('Y-m-d\TH:i:s\Z');

        // Add to our active bindings
        $active_bindings[$key] = [
            'zone' => $zone_lower,
            'mac' => $mac_normalized,
            'expires_at' => $expires_iso,
            'voucher_hash' => hash('sha256', $voucher_code ?: "imported_{$mac_normalized}"),
            'last_seen' => $now_iso,
            'src_ip' => '',
            'imported_from_pfsense' => true
        ];

        $imported++;
        log_msg('INFO', "Imported pfSense auto-added MAC {$mac_normalized} to zone {$zone}, expires: {$expires_iso}");
    }

    return $imported;
}

// ============================================================================
// PFSENSE CONFIG SYNC
// ============================================================================

/**
 * Load pfSense configuration includes
 */
function load_pfsense_includes(): bool {
    $includes = [
        '/etc/inc/config.inc',
        '/etc/inc/captiveportal.inc',
        '/etc/inc/util.inc'
    ];
    
    foreach ($includes as $inc) {
        if (!file_exists($inc)) {
            log_msg('ERROR', "pfSense include not found: {$inc}");
            return false;
        }
    }
    
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/captiveportal.inc');
    require_once('/etc/inc/util.inc');
    
    return true;
}

/**
 * Create backup of captive portal config section
 */
function macbind_backup_config(): bool {
    global $config;
    
    if (!is_dir(BACKUP_DIR)) {
        if (!@mkdir(BACKUP_DIR, 0755, true)) {
            log_msg('ERROR', "Cannot create backup directory: " . BACKUP_DIR);
            return false;
        }
    }
    
    // Check if we already have a backup today
    $today = gmdate('Ymd');
    $pattern = BACKUP_DIR . "/cp_passthru_{$today}_*.json";
    $existing = glob($pattern);
    
    if (!empty($existing)) {
        // Already have today's backup
        return true;
    }
    
    $backup_file = BACKUP_DIR . "/cp_passthru_{$today}_" . gmdate('His') . ".json";
    
    // Extract only captiveportal passthrumac sections
    $backup_data = [
        'timestamp' => iso8601_now(),
        'zones' => []
    ];
    
    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
            $zone_name = $zone_cfg['zone'] ?? $zone_id;
            $backup_data['zones'][$zone_name] = [
                'passthrumac' => $zone_cfg['passthrumac'] ?? []
            ];
        }
    }
    
    $json = json_encode($backup_data, JSON_PRETTY_PRINT);
    if (file_put_contents($backup_file, $json, LOCK_EX) === false) {
        log_msg('ERROR', "Failed to write backup file: {$backup_file}");
        return false;
    }
    
    log_msg('INFO', "Created config backup: {$backup_file}");
    return true;
}

/**
 * Sync active bindings to pfSense pass-through MAC config
 * @return array{added: int, removed: int, errors: int}
 */
function sync_to_pfsense(array $active_bindings, bool $dry_run = false): array {
    global $config, $cpzone;

    $result = ['added' => 0, 'removed' => 0, 'errors' => 0];
    $config_changed = false;
    $zones_to_reload = [];
    $macs_to_disconnect = [];  // Track MACs that need firewall rules removed
    
    if (!isset($config['captiveportal']) || !is_array($config['captiveportal'])) {
        log_msg('ERROR', "No captiveportal section in pfSense config");
        $result['errors']++;
        return $result;
    }
    
    // Build per-zone desired MAC sets from active bindings
    $desired_by_zone = [];
    foreach ($active_bindings as $key => $binding) {
        $zone = $binding['zone'];
        if (!isset($desired_by_zone[$zone])) {
            $desired_by_zone[$zone] = [];
        }
        $desired_by_zone[$zone][$binding['mac']] = $binding;
    }
    
    // Process each captive portal zone
    foreach ($config['captiveportal'] as $zone_id => &$zone_cfg) {
        $zone_name = $zone_cfg['zone'] ?? $zone_id;
        $zone_name_lower = strtolower($zone_name);
        
        // Ensure passthrumac array exists
        if (!isset($zone_cfg['passthrumac']) || !is_array($zone_cfg['passthrumac'])) {
            $zone_cfg['passthrumac'] = [];
        }
        
        // Build map of currently managed entries (those with our tag)
        // Also build map of ALL pfSense auto-added voucher MACs (to avoid duplicates)
        $current_managed = [];
        $pfsense_auto_added = [];
        foreach ($zone_cfg['passthrumac'] as $idx => $entry) {
            $mac = normalize_mac($entry['mac'] ?? '');
            if (empty($mac)) {
                continue;
            }

            $descr = $entry['descr'] ?? '';
            if (strpos($descr, TAG_PREFIX) === 0) {
                $current_managed[$mac] = $idx;
            }
            // Also track pfSense auto-added MACs
            if (strpos($descr, 'Auto-added for voucher') !== false) {
                $pfsense_auto_added[$mac] = $idx;
            }
        }
        
        // Get desired MACs for this zone
        $desired = $desired_by_zone[$zone_name_lower] ?? [];
        
        // Determine MACs to add
        foreach ($desired as $mac => $binding) {
            // Skip if already managed by us OR already auto-added by pfSense
            // (passthrumacadd enabled means pfSense adds MACs automatically)
            if (isset($current_managed[$mac]) || isset($pfsense_auto_added[$mac])) {
                continue;
            }
            // Add new entry (only if not already in pfSense)
            if (!$dry_run) {
                $zone_cfg['passthrumac'][] = [
                    'mac' => $mac,
                    'action' => 'pass',
                    'descr' => TAG_PREFIX . $binding['voucher_hash']
                ];
                $config_changed = true;
                $zones_to_reload[$zone_name] = true;
            }
            $result['added']++;
            log_msg('INFO', "Adding MAC {$mac} to zone {$zone_name}");
        }
        
        // Determine MACs to remove (managed entries not in desired set)
        $new_passthrumac = [];
        foreach ($zone_cfg['passthrumac'] as $entry) {
            $keep = true;
            $descr = $entry['descr'] ?? '';

            if (strpos($descr, TAG_PREFIX) === 0) {
                $mac = normalize_mac($entry['mac'] ?? '');
                if (!empty($mac) && !isset($desired[$mac])) {
                    // Remove this managed entry
                    if (!$dry_run) {
                        $config_changed = true;
                        $zones_to_reload[$zone_name] = true;
                        // Track for firewall rule removal
                        $macs_to_disconnect[] = [
                            'zone' => $zone_name,
                            'entry' => $entry
                        ];
                    }
                    $result['removed']++;
                    log_msg('INFO', "Removing MAC {$mac} from zone {$zone_name}");
                    $keep = false;
                }
            }
            
            if ($keep) {
                $new_passthrumac[] = $entry;
            }
        }
        
        if (!$dry_run) {
            $zone_cfg['passthrumac'] = $new_passthrumac;
        }
    }
    unset($zone_cfg); // break reference
    
    // Write config and reload zones if changes occurred
    if ($config_changed && !$dry_run) {
        // Create backup before first change
        macbind_backup_config();

        // FIRST: Remove firewall rules for removed MACs (disconnect users immediately)
        // This must happen BEFORE write_config to ensure users are disconnected
        foreach ($macs_to_disconnect as $item) {
            $zone = $item['zone'];
            $macent = $item['entry'];

            // Set global cpzone for pfSense functions
            $cpzone = $zone;

            // Use pfSense function to properly remove firewall rules
            if (function_exists('captiveportal_passthrumac_delete_entry')) {
                captiveportal_passthrumac_delete_entry($macent);
                log_msg('INFO', "Disconnected MAC {$macent['mac']} from zone {$zone} (firewall rules removed)");
            } else {
                // Fallback: try to flush manually using pfSense pf functions
                $mac_clean = str_replace(":", "", $macent['mac']);
                $mac_clean = str_replace("/", "_", $mac_clean);
                if (isset($config['captiveportal'][$zone]['zoneid'])) {
                    $zoneid = $config['captiveportal'][$zone]['zoneid'];
                    $cpzoneprefix = "cpzone" . $zoneid;
                    // Try to flush using pfSense function
                    if (function_exists('pfSense_pf_cp_flush')) {
                        pfSense_pf_cp_flush("{$cpzoneprefix}_passthrumac/{$mac_clean}", "ether");
                        log_msg('INFO', "Flushed firewall rules for MAC {$macent['mac']} in zone {$zone}");
                    }
                }
            }
        }

        // THEN: Write pfSense config
        write_config("macbind_sync: auto-updating pass-through MAC entries (+{$result['added']}/-{$result['removed']})");

        // Reload affected captive portal zones
        foreach (array_keys($zones_to_reload) as $zone_name) {
            log_msg('INFO', "Reloading captive portal zone: {$zone_name}");
            $cpzone = $zone_name;  // Set global for pfSense functions
            if (function_exists('captiveportal_configure_zone')) {
                captiveportal_configure_zone($zone_name);
            } elseif (function_exists('captiveportal_configure')) {
                // Older pfSense versions
                captiveportal_configure();
            }
        }
    }

    return $result;
}

// ============================================================================
// SELF-TEST
// ============================================================================

/**
 * Run self-test diagnostics
 */
function run_selftest(): void {
    echo "=== macbind_sync.php Self-Test ===\n\n";
    $errors = 0;
    
    // Check root
    echo "1. Running as root: ";
    if (posix_getuid() === 0) {
        echo "YES\n";
    } else {
        echo "NO (FAIL)\n";
        $errors++;
    }
    
    // Check lock file
    echo "2. Lock file test: ";
    $lock = acquire_lock();
    if ($lock) {
        echo "OK\n";
        release_lock($lock);
    } else {
        echo "FAIL (another instance running or cannot create)\n";
        $errors++;
    }
    
    // Check queue file permissions
    echo "3. Queue file ({" . QUEUE_FILE . "}): ";
    if (file_exists(QUEUE_FILE)) {
        if (is_readable(QUEUE_FILE)) {
            echo "exists, readable\n";
        } else {
            echo "exists but NOT readable (FAIL)\n";
            $errors++;
        }
    } else {
        if (is_writable(dirname(QUEUE_FILE))) {
            echo "does not exist, directory writable (OK)\n";
        } else {
            echo "does not exist, directory NOT writable (FAIL)\n";
            $errors++;
        }
    }
    
    // Check active DB
    echo "4. Active DB ({" . ACTIVE_DB_FILE . "}): ";
    if (file_exists(ACTIVE_DB_FILE)) {
        if (is_readable(ACTIVE_DB_FILE) && is_writable(ACTIVE_DB_FILE)) {
            echo "exists, readable/writable\n";
        } else {
            echo "exists but permission issue (FAIL)\n";
            $errors++;
        }
    } else {
        if (is_writable(dirname(ACTIVE_DB_FILE))) {
            echo "does not exist, directory writable (OK)\n";
        } else {
            echo "does not exist, directory NOT writable (FAIL)\n";
            $errors++;
        }
    }
    
    // Check backup directory
    echo "5. Backup directory ({" . BACKUP_DIR . "}): ";
    if (is_dir(BACKUP_DIR)) {
        if (is_writable(BACKUP_DIR)) {
            echo "exists, writable\n";
        } else {
            echo "exists but NOT writable (FAIL)\n";
            $errors++;
        }
    } else {
        $parent = dirname(BACKUP_DIR);
        if (is_writable($parent)) {
            echo "does not exist, parent writable (OK)\n";
        } else {
            echo "does not exist, parent NOT writable (FAIL)\n";
            $errors++;
        }
    }
    
    // Check pfSense includes
    echo "6. pfSense config.inc: ";
    if (file_exists('/etc/inc/config.inc')) {
        echo "found\n";
    } else {
        echo "NOT found (FAIL - not running on pfSense?)\n";
        $errors++;
    }
    
    echo "7. pfSense captiveportal.inc: ";
    if (file_exists('/etc/inc/captiveportal.inc')) {
        echo "found\n";
    } else {
        echo "NOT found (FAIL)\n";
        $errors++;
    }
    
    // Try loading config
    echo "8. Loading pfSense config: ";
    if (file_exists('/etc/inc/config.inc')) {
        ob_start();  // Suppress any output from config.inc
        require_once('/etc/inc/config.inc');
        ob_end_clean();
        global $config;
        if (isset($config) && is_array($config)) {
            echo "OK\n";
            
            echo "9. Captive portal zones: ";
            if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
                $zones = array_keys($config['captiveportal']);
                echo implode(', ', $zones) . "\n";
            } else {
                echo "none configured\n";
            }
        } else {
            echo "FAIL (config not loaded)\n";
            $errors++;
        }
    } else {
        echo "SKIP (not on pfSense)\n";
        echo "9. Captive portal zones: SKIP\n";
    }
    
    // Check disable flag
    echo "10. Disable flag ({" . DISABLE_FLAG . "}): ";
    if (file_exists(DISABLE_FLAG)) {
        echo "PRESENT (sync disabled)\n";
    } else {
        echo "not present (sync enabled)\n";
    }
    
    echo "\n=== Self-Test Complete ===\n";
    if ($errors > 0) {
        echo "RESULT: {$errors} error(s) found\n";
        exit(1);
    } else {
        echo "RESULT: All checks passed\n";
        exit(0);
    }
}

// ============================================================================
// MAIN
// ============================================================================

// Parse command line options
$options = getopt('', ['dry-run', 'selftest', 'help']);

if (isset($options['help'])) {
    echo "Usage: macbind_sync.php [OPTIONS]\n";
    echo "\nOptions:\n";
    echo "  --selftest    Run diagnostic self-test\n";
    echo "  --dry-run     Compute changes without applying\n";
    echo "  --help        Show this help message\n";
    exit(0);
}

// Ensure CLI execution
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "ERROR: This script must be run from command line.\n");
    exit(1);
}

// Self-test mode
if (isset($options['selftest'])) {
    ensure_root();
    run_selftest();
    exit(0);
}

$dry_run = isset($options['dry-run']);

// Ensure root
ensure_root();

// Acquire lock
$lock_handle = acquire_lock();
if (!$lock_handle) {
    // Another instance running, exit silently
    exit(0);
}

// Check disable flag
if (file_exists(DISABLE_FLAG)) {
    log_msg('INFO', "Sync disabled via flag file, exiting");
    release_lock($lock_handle);
    exit(0);
}

// Initialize metrics
$metrics = [
    'processed_queue' => 0,
    'active_count' => 0,
    'added' => 0,
    'removed' => 0,
    'expired_removed' => 0,
    'pfsense_expired_removed' => 0,  // MACs removed from pfSense config
    'pfsense_imported' => 0,          // MACs imported from pfSense auto-add
    'errors' => 0,
    'start_time' => microtime(true)
];

// Load pfSense includes
if (!load_pfsense_includes()) {
    log_msg('ERROR', "Failed to load pfSense includes");
    $metrics['errors']++;
    release_lock($lock_handle);
    echo "ERROR: Failed to load pfSense configuration\n";
    exit(1);
}

// Load active database
$active_db = load_active_db();

// Read and process queue
$queue_data = read_queue();
$metrics['processed_queue'] = $queue_data['lines_read'];

$now_ts = time();

// Process queue entries - merge into active bindings
foreach ($queue_data['entries'] as $entry) {
    $key = $entry['zone'] . '|' . $entry['mac'];
    
    // Skip if already expired
    if ($entry['expires_ts'] <= $now_ts) {
        continue;
    }
    
    // Add or update binding (keep latest/longest expiry)
    if (!isset($active_db['bindings'][$key])) {
        // New binding
        $active_db['bindings'][$key] = [
            'zone' => $entry['zone'],
            'mac' => $entry['mac'],
            'expires_at' => $entry['expires_at'],
            'voucher_hash' => $entry['voucher_hash'],
            'last_seen' => $entry['ts'],
            'src_ip' => $entry['src_ip']
        ];
    } else {
        // Update if new expiry is later
        $existing_ts = parse_iso8601($active_db['bindings'][$key]['expires_at']);
        if ($entry['expires_ts'] > $existing_ts) {
            $active_db['bindings'][$key]['expires_at'] = $entry['expires_at'];
            $active_db['bindings'][$key]['voucher_hash'] = $entry['voucher_hash'];
        }
        $active_db['bindings'][$key]['last_seen'] = $entry['ts'];
        $active_db['bindings'][$key]['src_ip'] = $entry['src_ip'];
    }
}

// Import pfSense auto-added MACs to our DB (if passthrumacadd is enabled)
// This ensures we track expiry for MACs that pfSense added automatically
if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
    foreach (array_keys($config['captiveportal']) as $zone_id) {
        $imported = import_pfsense_auto_added_macs($active_db['bindings'], $zone_id);
        $metrics['pfsense_imported'] += $imported;
    }
}

// CRITICAL: Clean up expired MACs from pfSense config BEFORE removing from our DB
// We need the binding info (expires_at) to determine if MAC should be removed from pfSense
// After this, we can safely remove expired entries from our DB
$cleanup_result = cleanup_expired_pfsense_macs($active_db['bindings'], $dry_run);
$metrics['pfsense_expired_removed'] = $cleanup_result['removed'];
$metrics['errors'] += $cleanup_result['errors'];

// NOW remove expired bindings from our DB (voucher time ran out)
foreach ($active_db['bindings'] as $key => $binding) {
    $expires_ts = parse_iso8601($binding['expires_at']);
    if ($expires_ts === null || $expires_ts <= $now_ts) {
        unset($active_db['bindings'][$key]);
        $metrics['expired_removed']++;
        log_msg('INFO', "Expired binding removed from DB: {$key}");
    }
}

$metrics['active_count'] = count($active_db['bindings']);

// Sync our active bindings to pfSense config (add new ones if needed)
// Note: With passthrumacadd enabled, pfSense already adds MACs automatically
// This sync is mainly for adding our AUTO_BIND tag for tracking
$sync_result = sync_to_pfsense($active_db['bindings'], $dry_run);
$metrics['added'] = $sync_result['added'];
$metrics['removed'] = $sync_result['removed'];
$metrics['errors'] += $sync_result['errors'];

// Save active database (unless dry-run)
if (!$dry_run) {
    if (!save_active_db($active_db)) {
        $metrics['errors']++;
    }
    
    // Remove processed queue entries
    if ($queue_data['lines_read'] > 0) {
        truncate_queue($queue_data['lines_read']);
    }
}

// Calculate duration
$duration_ms = round((microtime(true) - $metrics['start_time']) * 1000, 2);

// Log summary
$summary = sprintf(
    "queue=%d active=%d added=%d removed=%d expired_db=%d expired_pfsense=%d imported=%d errors=%d ms=%.2f",
    $metrics['processed_queue'],
    $metrics['active_count'],
    $metrics['added'],
    $metrics['removed'],
    $metrics['expired_removed'],
    $metrics['pfsense_expired_removed'],
    $metrics['pfsense_imported'],
    $metrics['errors'],
    $duration_ms
);

log_msg('INFO', ($dry_run ? "[DRY-RUN] " : "") . "Sync complete: {$summary}");

// Output summary to stdout (for manual runs)
if ($dry_run) {
    echo "=== DRY RUN MODE (no changes applied) ===\n";
}
echo "processed_queue={$metrics['processed_queue']}\n";
echo "active_count={$metrics['active_count']}\n";
echo "added={$metrics['added']}\n";
echo "removed={$metrics['removed']}\n";
echo "expired_removed_db={$metrics['expired_removed']}\n";
echo "expired_removed_pfsense={$metrics['pfsense_expired_removed']}\n";
echo "pfsense_imported={$metrics['pfsense_imported']}\n";
echo "errors={$metrics['errors']}\n";
echo "duration_ms={$duration_ms}\n";

if ($queue_data['lines_remaining'] > 0) {
    echo "queue_lines_remaining={$queue_data['lines_remaining']}\n";
}

// Release lock and exit
release_lock($lock_handle);
exit($metrics['errors'] > 0 ? 1 : 0);
