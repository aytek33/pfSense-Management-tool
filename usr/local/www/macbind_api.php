<?php
/**
 * macbind_api.php
 * 
 * REST API endpoint for remote MAC binding management on pfSense.
 * Designed to be called from Google Apps Script or other remote systems.
 * 
 * Installation:
 *   1. Copy to /usr/local/www/macbind_api.php on pfSense
 *   2. Generate API key: openssl rand -hex 32
 *   3. Set API key in /usr/local/etc/macbind_api.conf
 *   4. Access via: https://your-pfsense/macbind_api.php
 * 
 * Endpoints:
 *   GET  ?action=status     - Get system status and binding counts
 *   GET  ?action=bindings   - List all active bindings
 *   GET  ?action=zones      - List captive portal zones
 *   POST ?action=add        - Add MAC binding (JSON body)
 *   POST ?action=remove     - Remove MAC binding (JSON body)
 *   POST ?action=sync       - Trigger sync immediately
 * 
 * Authentication:
 *   Header: X-API-Key: <your-api-key>
 * 
 * @version 1.0.0
 * @license BSD-2-Clause
 */

declare(strict_types=1);

// ============================================================================
// CONFIGURATION
// ============================================================================

define('API_CONFIG_FILE', '/usr/local/etc/macbind_api.conf');
define('ACTIVE_DB_FILE', '/var/db/macbind_active.json');
define('QUEUE_FILE', '/var/db/macbind_queue.csv');
define('LOG_FILE', '/var/log/macbind_api.log');
define('SYNC_SCRIPT', '/usr/local/sbin/macbind_sync.php');
define('RATE_LIMIT_FILE', '/var/run/macbind_api_rate.json');

// Rate limiting: max requests per minute
define('RATE_LIMIT_MAX', 60);
define('RATE_LIMIT_WINDOW', 60); // seconds

// Tag prefix for macbind-managed entries in pfSense config
define('TAG_PREFIX', 'AUTO_BIND:');

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Send JSON response and exit
 */
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response
 */
function error_response(string $message, int $status = 400): void {
    log_api('ERROR', $message);
    json_response(['success' => false, 'error' => $message], $status);
}

/**
 * Log API request/response
 */
function log_api(string $level, string $message): void {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $entry = "[{$timestamp}] [{$level}] [{$client_ip}] {$message}\n";
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Load API configuration
 */
function load_config(): array {
    $default = [
        'api_key' => '',
        'allowed_ips' => [],
        'rate_limit_enabled' => true
    ];
    
    if (!file_exists(API_CONFIG_FILE)) {
        return $default;
    }
    
    $content = @file_get_contents(API_CONFIG_FILE);
    if ($content === false) {
        return $default;
    }
    
    // Parse simple key=value format
    $config = $default;
    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === 'api_key') {
                $config['api_key'] = $value;
            } elseif ($key === 'allowed_ips') {
                $config['allowed_ips'] = array_filter(array_map('trim', explode(',', $value)));
            } elseif ($key === 'rate_limit_enabled') {
                $config['rate_limit_enabled'] = strtolower($value) !== 'false' && $value !== '0';
            }
        }
    }
    
    return $config;
}

/**
 * Validate API key from request header or JSON body
 * 
 * Checks in order of priority:
 * 1. X-API-Key header (primary - for Google Apps Script)
 * 2. api_key in JSON body (fallback - for proxies that strip headers)
 */
function validate_api_key(string $expected_key): bool {
    $headers = getallheaders();
    
    // Check header first (works when proxy forwards headers)
    $provided_key = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? '';
    
    // Fallback: check JSON body for api_key (secure alternative when headers are stripped)
    if (empty($provided_key)) {
        $body = get_json_body();
        $provided_key = $body['api_key'] ?? '';
    }
    
    if (empty($expected_key)) {
        error_response('API key not configured on server', 500);
    }
    
    if (empty($provided_key)) {
        error_response('Missing API key (provide X-API-Key header or api_key in JSON body)', 401);
    }
    
    // Constant-time comparison to prevent timing attacks
    if (!hash_equals($expected_key, $provided_key)) {
        error_response('Invalid API key', 401);
    }
    
    return true;
}

/**
 * Check if client IP is allowed
 */
function validate_ip(array $allowed_ips): bool {
    if (empty($allowed_ips)) {
        return true; // No restriction if not configured
    }
    
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    foreach ($allowed_ips as $allowed) {
        if ($allowed === $client_ip) {
            return true;
        }
        // Support CIDR notation
        if (strpos($allowed, '/') !== false) {
            if (ip_in_cidr($client_ip, $allowed)) {
                return true;
            }
        }
    }
    
    error_response('IP address not allowed', 403);
    return false;
}

/**
 * Check if IP is in CIDR range
 */
function ip_in_cidr(string $ip, string $cidr): bool {
    list($subnet, $mask) = explode('/', $cidr);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask_long = -1 << (32 - (int)$mask);
    return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
}

/**
 * Check rate limit
 */
function check_rate_limit(): bool {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    
    $rate_data = [];
    if (file_exists(RATE_LIMIT_FILE)) {
        $content = @file_get_contents(RATE_LIMIT_FILE);
        if ($content) {
            $rate_data = json_decode($content, true) ?? [];
        }
    }
    
    // Clean old entries
    foreach ($rate_data as $ip => $data) {
        if ($data['window_start'] < $now - RATE_LIMIT_WINDOW) {
            unset($rate_data[$ip]);
        }
    }
    
    // Check/update current IP
    if (!isset($rate_data[$client_ip])) {
        $rate_data[$client_ip] = ['count' => 0, 'window_start' => $now];
    }
    
    // Reset window if expired
    if ($rate_data[$client_ip]['window_start'] < $now - RATE_LIMIT_WINDOW) {
        $rate_data[$client_ip] = ['count' => 0, 'window_start' => $now];
    }
    
    $rate_data[$client_ip]['count']++;
    
    // Save rate data
    @file_put_contents(RATE_LIMIT_FILE, json_encode($rate_data), LOCK_EX);
    
    if ($rate_data[$client_ip]['count'] > RATE_LIMIT_MAX) {
        error_response('Rate limit exceeded', 429);
    }
    
    return true;
}

/**
 * Normalize MAC address to lowercase colon-separated format
 */
function normalize_mac(string $mac): string {
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac));
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

/**
 * Get JSON request body
 */
function get_json_body(): array {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

// ============================================================================
// ACTION HANDLERS
// ============================================================================

/**
 * GET /status - System status and statistics
 */
function action_status(): void {
    $status = [
        'success' => true,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'hostname' => gethostname(),
        'version' => '1.0.0',
        'sync_enabled' => !file_exists('/var/db/macbind_disabled'),
        'bindings' => [
            'total' => 0,
            'by_zone' => []
        ],
        'queue' => [
            'pending' => 0
        ]
    ];
    
    // Count active bindings
    if (file_exists(ACTIVE_DB_FILE)) {
        $json = @file_get_contents(ACTIVE_DB_FILE);
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['bindings']) && is_array($data['bindings'])) {
                $status['bindings']['total'] = count($data['bindings']);
                
                // Count by zone
                foreach ($data['bindings'] as $binding) {
                    $zone = $binding['zone'] ?? 'unknown';
                    if (!isset($status['bindings']['by_zone'][$zone])) {
                        $status['bindings']['by_zone'][$zone] = 0;
                    }
                    $status['bindings']['by_zone'][$zone]++;
                }
                
                $status['bindings']['last_updated'] = $data['updated_at'] ?? null;
            }
        }
    }
    
    // Count queue entries
    if (file_exists(QUEUE_FILE)) {
        $lines = @file(QUEUE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $status['queue']['pending'] = count($lines);
        }
    }
    
    log_api('INFO', 'status request');
    json_response($status);
}

/**
 * GET /bindings - List all active bindings
 *
 * Returns bindings from BOTH:
 * 1. macbind-managed entries (from /var/db/macbind_active.json)
 * 2. pfSense pass-through MACs (from config.xml passthrumac)
 *
 * Each binding includes a 'source' field:
 * - 'macbind' = Created/managed by macbind system
 * - 'pfsense' = Manually configured or from other sources
 * - 'pfsense_auto' = pfSense's built-in "Pass-through MAC Auto Entry" for vouchers
 */
function action_bindings(): void {
    $zone_filter = $_GET['zone'] ?? null;
    $body = get_json_body();
    if (isset($body['zone'])) {
        $zone_filter = $body['zone'];
    }

    // Use associative array keyed by MAC to deduplicate
    $bindings = [];

    // 1. Read macbind-managed bindings from active database
    if (file_exists(ACTIVE_DB_FILE)) {
        $json = @file_get_contents(ACTIVE_DB_FILE);
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['bindings']) && is_array($data['bindings'])) {
                foreach ($data['bindings'] as $binding) {
                    // Apply zone filter if specified
                    if ($zone_filter !== null && $binding['zone'] !== strtolower($zone_filter)) {
                        continue;
                    }
                    $mac = strtolower($binding['mac'] ?? '');
                    if (!empty($mac)) {
                        $binding['source'] = 'macbind';
                        $bindings[$mac] = $binding;
                    }
                }
            }
        }
    }

    // 2. Build voucher session lookup map for pfSense auto-added MACs
    // This allows us to find expiry for "Auto-added for voucher XXX" entries
    $voucher_session_map = build_voucher_session_map();

    // 3. Read pfSense pass-through MACs from config
    require_once('/etc/inc/config.inc');
    global $config;

    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
            $zone_name = strtolower($zone_cfg['zone'] ?? $zone_id);

            // Apply zone filter if specified
            if ($zone_filter !== null && $zone_name !== strtolower($zone_filter)) {
                continue;
            }

            if (isset($zone_cfg['passthrumac']) && is_array($zone_cfg['passthrumac'])) {
                foreach ($zone_cfg['passthrumac'] as $entry) {
                    $mac_raw = $entry['mac'] ?? '';
                    if (empty($mac_raw)) continue;

                    // Normalize MAC to colon-separated format
                    $mac = normalize_mac($mac_raw);
                    if (empty($mac)) continue;
                    $mac_lower = strtolower($mac);

                    // Only add if not already in macbind list (macbind takes priority)
                    if (!isset($bindings[$mac_lower])) {
                        $expires_at = null;
                        $voucher_code = null;
                        $source = 'pfsense';
                        $descr = $entry['descr'] ?? '';

                        // Check if this is a macbind-managed entry (AUTO_BIND: prefix)
                        if (strpos($descr, 'AUTO_BIND:') === 0) {
                            $source = 'macbind';
                            // Try to find expiration in active DB
                            $expires_at = find_expiry_in_active_db($mac_lower, $zone_name);
                        }
                        // Check if this is pfSense's auto-added voucher entry
                        elseif (preg_match('/^Auto-added for voucher\s+(.+)$/i', $descr, $matches)) {
                            $source = 'pfsense_auto';
                            $voucher_code = trim($matches[1]);

                            // Try to find expiry from captive portal session or voucher DB
                            $expires_at = find_voucher_expiry($zone_id, $zone_name, $mac_lower, $voucher_code, $voucher_session_map);
                        }
                        // Manual entry - check if we have it in our active DB
                        else {
                            $expires_at = find_expiry_in_active_db($mac_lower, $zone_name);
                        }

                        $bindings[$mac_lower] = [
                            'mac' => $mac,
                            'zone' => $zone_name,
                            'description' => $descr,
                            'action' => $entry['action'] ?? 'pass',
                            'source' => $source,
                            'voucher_code' => $voucher_code,
                            'created_at' => null,
                            'expires_at' => $expires_at,
                            'src_ip' => null
                        ];
                    }
                }
            }
        }
    }

    // Convert to indexed array for JSON response
    $bindings_array = array_values($bindings);

    log_api('INFO', "bindings request, count=" . count($bindings_array));
    json_response([
        'success' => true,
        'count' => count($bindings_array),
        'bindings' => $bindings_array
    ]);
}

/**
 * Build a map of voucher sessions from captive portal database
 * Returns array keyed by "zone:mac" or "zone:username(voucher)" with session info
 */
function build_voucher_session_map(): array {
    $map = [];

    require_once('/etc/inc/config.inc');
    global $config;

    if (!isset($config['captiveportal']) || !is_array($config['captiveportal'])) {
        return $map;
    }

    foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
        $zone_name = strtolower($zone_cfg['zone'] ?? $zone_id);

        // pfSense captive portal DB path
        $db_file = "/var/db/captiveportal{$zone_id}.db";
        if (!file_exists($db_file)) {
            continue;
        }

        try {
            $db = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(5000);

            // Query all sessions - pfSense schema:
            // allow_time, pipeno, ip, mac, username, sessionid, bpassword,
            // session_timeout, idle_timeout, session_terminate_time, ...
            $result = $db->query("SELECT * FROM captiveportal");

            if ($result) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $mac = strtolower($row['mac'] ?? '');
                    $username = $row['username'] ?? '';
                    $allow_time = (int)($row['allow_time'] ?? 0);
                    $session_timeout = (int)($row['session_timeout'] ?? 0);

                    if (empty($mac) || $allow_time === 0) {
                        continue;
                    }

                    // Calculate expiry
                    $expires_ts = $allow_time + $session_timeout;
                    $expires_at = gmdate('Y-m-d\TH:i:s\Z', $expires_ts);

                    $session_info = [
                        'mac' => $mac,
                        'username' => $username,
                        'allow_time' => $allow_time,
                        'session_timeout' => $session_timeout,
                        'expires_ts' => $expires_ts,
                        'expires_at' => $expires_at,
                        'ip' => $row['ip'] ?? '',
                        'zone' => $zone_name
                    ];

                    // Key by zone:mac
                    $map["{$zone_name}:{$mac}"] = $session_info;

                    // Also key by zone:username (voucher code) for lookup
                    if (!empty($username)) {
                        $map["{$zone_name}:voucher:{$username}"] = $session_info;
                    }
                }
            }

            $db->close();
        } catch (Exception $e) {
            log_api('WARN', "Failed to read captive portal DB for zone {$zone_id}: " . $e->getMessage());
        }
    }

    return $map;
}

/**
 * Find expiry for a MAC in the macbind active database
 */
function find_expiry_in_active_db(string $mac_lower, string $zone_name): ?string {
    if (!file_exists(ACTIVE_DB_FILE)) {
        return null;
    }

    $active_json = @file_get_contents(ACTIVE_DB_FILE);
    if (!$active_json) {
        return null;
    }

    $active_data = json_decode($active_json, true);
    if (!isset($active_data['bindings']) || !is_array($active_data['bindings'])) {
        return null;
    }

    foreach ($active_data['bindings'] as $binding) {
        if (strtolower($binding['mac'] ?? '') === $mac_lower &&
            strtolower($binding['zone'] ?? '') === $zone_name) {
            return $binding['expires_at'] ?? null;
        }
    }

    return null;
}

/**
 * Find expiry for a pfSense auto-added voucher MAC
 * Checks multiple sources in order:
 * 1. Active captive portal session (by MAC or voucher username)
 * 2. Voucher active DB
 * 3. Calculates from voucher roll duration (if activation time known)
 */
function find_voucher_expiry(string $zone_id, string $zone_name, string $mac_lower, string $voucher_code, array $session_map): ?string {
    global $config;

    // 1. Check session map by MAC
    $key_mac = "{$zone_name}:{$mac_lower}";
    if (isset($session_map[$key_mac])) {
        $session = $session_map[$key_mac];
        // Verify session is still valid (not expired)
        if ($session['expires_ts'] > time()) {
            return $session['expires_at'];
        }
    }

    // 2. Check session map by voucher code (username)
    $key_voucher = "{$zone_name}:voucher:{$voucher_code}";
    if (isset($session_map[$key_voucher])) {
        $session = $session_map[$key_voucher];
        if ($session['expires_ts'] > time()) {
            return $session['expires_at'];
        }
    }

    // 3. Check voucher active database files
    // pfSense stores active vouchers in /var/db/voucher_{zone}_active_{roll}.db
    $voucher_pattern = "/var/db/voucher_{$zone_id}_active_*.db";
    $voucher_files = glob($voucher_pattern);

    foreach ($voucher_files as $vfile) {
        $content = @file_get_contents($vfile);
        if (!$content) continue;

        // Format: voucher_code,timestamp,minutes per line
        $lines = explode("\n", trim($content));
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = explode(',', $line);
            if (count($parts) >= 3) {
                $v_code = trim($parts[0]);
                $v_timestamp = (int)$parts[1];
                $v_minutes = (int)$parts[2];

                if ($v_code === $voucher_code) {
                    $expires_ts = $v_timestamp + ($v_minutes * 60);
                    if ($expires_ts > time()) {
                        return gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
                    }
                    // Voucher found but expired - return expired time anyway for display
                    return gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
                }
            }
        }
    }

    // 4. Fallback: Check if we have this MAC in our macbind active DB
    // (in case it was also tracked by macbind)
    $macbind_expiry = find_expiry_in_active_db($mac_lower, $zone_name);
    if ($macbind_expiry !== null) {
        return $macbind_expiry;
    }

    return null;
}

/**
 * GET /zones - List captive portal zones
 */
function action_zones(): void {
    // Load pfSense config
    if (!file_exists('/etc/inc/config.inc')) {
        error_response('Not running on pfSense', 500);
    }
    
    require_once('/etc/inc/config.inc');
    global $config;
    
    $zones = [];
    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
            $zone_name = $zone_cfg['zone'] ?? $zone_id;
            $zones[] = [
                'id' => $zone_id,
                'name' => $zone_name,
                'interface' => $zone_cfg['interface'] ?? '',
                'enabled' => !isset($zone_cfg['disabled'])
            ];
        }
    }
    
    log_api('INFO', "zones request, count=" . count($zones));
    json_response([
        'success' => true,
        'count' => count($zones),
        'zones' => $zones
    ]);
}

/**
 * POST /add - Add MAC binding
 * Body: { "zone": "myzone", "mac": "aa:bb:cc:dd:ee:ff", "duration_minutes": 43200, "ip": "192.168.1.100" }
 */
function action_add(): void {
    $body = get_json_body();
    
    // Validate required fields
    if (empty($body['zone'])) {
        error_response('Missing required field: zone');
    }
    if (empty($body['mac'])) {
        error_response('Missing required field: mac');
    }
    
    $zone = strtolower(trim($body['zone']));
    $mac = normalize_mac($body['mac']);
    
    if ($mac === '') {
        error_response('Invalid MAC address format');
    }
    
    // Get duration/expiry
    $duration_minutes = (int)($body['duration_minutes'] ?? 43200);
    if ($duration_minutes <= 0) {
        $duration_minutes = 43200; // 30 days default
    }
    
    $expires_ts = time() + ($duration_minutes * 60);
    if (!empty($body['expires_at'])) {
        $ts = strtotime($body['expires_at']);
        if ($ts !== false) {
            $expires_ts = $ts;
        }
    }
    
    // Build CSV line (for queue/backup purposes)
    $now_iso = gmdate('Y-m-d\TH:i:s\Z');
    $expires_iso = gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
    $src_ip = $body['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Get voucher_hash from body if provided (from Google Apps Script voucher redemption)
    // If not provided, generate a unique hash for API-added bindings
    $voucher_hash = $body['voucher_hash'] ?? null;
    $is_voucher_redemption = !empty($voucher_hash);

    if (!$voucher_hash) {
        $voucher_hash = hash('sha256', 'API_' . $mac . '_' . time()); // Generate unique hash for non-voucher bindings
    }

    // Immediately add to active DB and pfSense config (not just queue)
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/captiveportal.inc');
    require_once('/etc/inc/util.inc');
    global $config;

    $config_changed = false;
    $zone_found = false;
    $zone_id = null;

    // Find the zone in config
    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zid => $zcfg) {
            $zname = strtolower($zcfg['zone'] ?? $zid);
            if ($zname === $zone) {
                $zone_id = $zid;
                $zone_found = true;
                break;
            }
        }
    }

    if (!$zone_found) {
        error_response('Captive portal zone not found: ' . $zone, 404);
    }

    // Add to active DB
    $active_db = [];
    if (file_exists(ACTIVE_DB_FILE)) {
        $json = @file_get_contents(ACTIVE_DB_FILE);
        if ($json) {
            $active_db = json_decode($json, true);
        }
    }

    if (!isset($active_db['bindings']) || !is_array($active_db['bindings'])) {
        $active_db['bindings'] = [];
    }

    // Initialize voucher_usage tracking if not exists
    if (!isset($active_db['voucher_usage']) || !is_array($active_db['voucher_usage'])) {
        $active_db['voucher_usage'] = [];
    }

    // VOUCHER REUSE PREVENTION: Check if this voucher_hash has already been used
    // Only check for actual voucher redemptions (not API-generated hashes)
    if ($is_voucher_redemption) {
        $voucher_usage_key = $zone . '|' . $voucher_hash;

        if (isset($active_db['voucher_usage'][$voucher_usage_key])) {
            $previous_usage = $active_db['voucher_usage'][$voucher_usage_key];
            $previous_mac = $previous_usage['mac'] ?? '';

            // Check if the previous MAC binding is STILL ACTIVE in pfSense passthrumac
            $previous_mac_still_active = false;
            $previous_mac_normalized = strtolower(str_replace(['-', '.'], ':', $previous_mac));

            if (isset($config['captiveportal'][$zone_id]['passthrumac']) &&
                is_array($config['captiveportal'][$zone_id]['passthrumac'])) {
                foreach ($config['captiveportal'][$zone_id]['passthrumac'] as $entry) {
                    $entry_mac = strtolower(str_replace(['-', '.'], ':', $entry['mac'] ?? ''));
                    if ($entry_mac === $previous_mac_normalized) {
                        $previous_mac_still_active = true;
                        break;
                    }
                }
            }

            // If previous MAC binding is STILL ACTIVE AND it's a DIFFERENT MAC, reject
            if ($previous_mac_still_active && strtolower($previous_mac) !== strtolower($mac)) {
                log_api('WARN', "Voucher reuse attempt blocked: voucher_hash={$voucher_hash}, original_mac={$previous_mac}, new_mac={$mac}, zone={$zone}, reason=previous_mac_still_active_in_pfsense");

                // Calculate remaining time for the voucher
                $previous_expires = $previous_usage['expires_at'] ?? '';
                $previous_expires_ts = strtotime($previous_expires);
                $remaining_seconds = max(0, $previous_expires_ts - time());
                $remaining_days = floor($remaining_seconds / 86400);
                $remaining_hours = floor(($remaining_seconds % 86400) / 3600);
                $remaining_minutes = floor(($remaining_seconds % 3600) / 60);

                // Format remaining time string
                $time_parts = [];
                if ($remaining_days > 0) {
                    $time_parts[] = $remaining_days . 'd';
                }
                if ($remaining_hours > 0) {
                    $time_parts[] = $remaining_hours . 'h';
                }
                if ($remaining_minutes > 0 && $remaining_days == 0) {
                    $time_parts[] = $remaining_minutes . 'm';
                }
                $remaining_formatted = !empty($time_parts) ? implode(' ', $time_parts) : '< 1m';

                // Return structured error for better UI handling
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'VOUCHER_ALREADY_IN_USE',
                    'error_code' => 'VOUCHER_ALREADY_IN_USE',
                    'message' => "This voucher is already in use on device {$previous_mac}. Time remaining: {$remaining_formatted}. Each voucher can only be used on one device at a time.",
                    'details' => [
                        'active_device_mac' => $previous_mac,
                        'zone' => $zone,
                        'expires_at' => $previous_expires,
                        'remaining_seconds' => $remaining_seconds,
                        'remaining_formatted' => $remaining_formatted
                    ]
                ], JSON_PRETTY_PRINT);
                exit;
            }

            // If previous MAC binding is NOT active (removed/expired), allow the new MAC
            if (!$previous_mac_still_active && strtolower($previous_mac) !== strtolower($mac)) {
                log_api('INFO', "Voucher reuse allowed: previous MAC binding no longer active, voucher_hash={$voucher_hash}, original_mac={$previous_mac}, new_mac={$mac}");
            }

            // If same MAC is re-using the voucher (e.g., reconnecting), allow it
            if (strtolower($previous_mac) === strtolower($mac)) {
                log_api('INFO', "Voucher re-auth allowed: same MAC reconnecting, voucher_hash={$voucher_hash}, mac={$mac}");
            }
        }

        // Record this voucher usage (update MAC to current one)
        $active_db['voucher_usage'][$voucher_usage_key] = [
            'mac' => $mac,
            'zone' => $zone,
            'first_used_at' => $active_db['voucher_usage'][$voucher_usage_key]['first_used_at'] ?? $now_iso,
            'last_used_at' => $now_iso,
            'expires_at' => $expires_iso,
            'src_ip' => $src_ip
        ];
    }

    $binding_key = $zone . '|' . $mac;
    $existing_binding = $active_db['bindings'][$binding_key] ?? null;
    
    // Add or update binding in active DB
    $active_db['bindings'][$binding_key] = [
        'zone' => $zone,
        'mac' => $mac,
        'expires_at' => $expires_iso,
        'voucher_hash' => $voucher_hash,
        'last_seen' => $now_iso,
        'src_ip' => $src_ip
    ];
    $active_db['updated_at'] = $now_iso;
    
    // Save active DB
    $tmp = ACTIVE_DB_FILE . '.tmp';
    if (@file_put_contents($tmp, json_encode($active_db, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        error_response('Failed to write active database', 500);
    }
    if (!@rename($tmp, ACTIVE_DB_FILE)) {
        @unlink($tmp);
        error_response('Failed to update active database', 500);
    }
    
    // Add to pfSense config
    $description = TAG_PREFIX . ($body['description'] ?? 'API Added');
    $action = ($body['action'] ?? 'pass') === 'block' ? 'block' : 'pass';
    
    if (!isset($config['captiveportal'][$zone_id]['passthrumac'])) {
        $config['captiveportal'][$zone_id]['passthrumac'] = [];
    }
    
    // Check if MAC already exists
    $mac_exists = false;
    foreach ($config['captiveportal'][$zone_id]['passthrumac'] as $idx => $entry) {
        if (normalize_mac($entry['mac'] ?? '') === $mac) {
            // Update existing entry
            $config['captiveportal'][$zone_id]['passthrumac'][$idx]['action'] = $action;
            $config['captiveportal'][$zone_id]['passthrumac'][$idx]['descr'] = $description;
            $mac_exists = true;
            $config_changed = true;
            break;
        }
    }
    
    if (!$mac_exists) {
        // Add new entry
        $config['captiveportal'][$zone_id]['passthrumac'][] = [
            'mac' => $mac,
            'action' => $action,
            'descr' => $description
        ];
        $config_changed = true;
    }
    
    if ($config_changed) {
        write_config("macbind_api: added MAC {$mac} to zone {$zone}");
        
        // Reload captive portal for the zone
        if (function_exists('captiveportal_configure_zone')) {
            captiveportal_configure_zone($zone);
        } elseif (function_exists('captiveportal_configure')) {
            captiveportal_configure();
        }
    }
    
    // Also append to queue for audit trail
    $csv_line = sprintf(
        "%s,%s,%s,%s,%s,%s\n",
        $now_iso,
        $zone,
        $mac,
        $expires_iso,
        $voucher_hash,
        $src_ip
    );
    @file_put_contents(QUEUE_FILE, $csv_line, FILE_APPEND | LOCK_EX);
    
    log_api('INFO', "add binding: zone={$zone}, mac={$mac}, expires={$expires_iso}, immediate_sync=yes");
    json_response([
        'success' => true,
        'message' => 'MAC binding added and synced',
        'binding' => [
            'zone' => $zone,
            'mac' => $mac,
            'expires_at' => $expires_iso,
            'src_ip' => $src_ip,
            'action' => $action
        ],
        'immediate_sync' => true
    ]);
}

/**
 * POST /remove - Remove MAC binding and IMMEDIATELY disconnect user
 * Body: { "zone": "myzone", "mac": "aa:bb:cc:dd:ee:ff" }
 *
 * CRITICAL: This function must:
 * 1. Remove MAC from pfSense passthrumac config
 * 2. Delete PF firewall rules (via captiveportal_passthrumac_delete_entry)
 * 3. Disconnect any active captive portal session for this MAC
 * 4. Flush PF states to immediately cut internet access
 */
function action_remove(): void {
    $body = get_json_body();

    if (empty($body['mac'])) {
        error_response('Missing required field: mac');
    }

    $mac = normalize_mac($body['mac']);
    if ($mac === '') {
        error_response('Invalid MAC address format');
    }

    $zone_filter = isset($body['zone']) ? strtolower(trim($body['zone'])) : null;

    // Load pfSense includes
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/util.inc');
    require_once('/etc/inc/captiveportal.inc');
    global $config, $cpzone, $cpzoneid;

    $removed_from_active_db = 0;
    $removed_from_config = 0;
    $sessions_disconnected = 0;
    $firewall_rules_flushed = 0;

    // =========================================================================
    // STEP 1: Remove from macbind active database
    // =========================================================================
    if (file_exists(ACTIVE_DB_FILE)) {
        $json = @file_get_contents(ACTIVE_DB_FILE);
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['bindings']) && is_array($data['bindings'])) {
                $keys_to_remove = [];
                foreach ($data['bindings'] as $key => $binding) {
                    $binding_mac = strtolower($binding['mac'] ?? '');
                    $binding_zone = strtolower($binding['zone'] ?? '');

                    if ($binding_mac === strtolower($mac)) {
                        if ($zone_filter === null || $binding_zone === $zone_filter) {
                            $keys_to_remove[] = $key;
                        }
                    }
                }

                if (!empty($keys_to_remove)) {
                    foreach ($keys_to_remove as $key) {
                        unset($data['bindings'][$key]);
                        $removed_from_active_db++;
                    }

                    $data['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

                    // Save active DB atomically
                    $tmp = ACTIVE_DB_FILE . '.tmp';
                    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false) {
                        @rename($tmp, ACTIVE_DB_FILE);
                    }
                }
            }
        }
    }

    // =========================================================================
    // STEP 2: Disconnect active captive portal sessions for this MAC
    // This must happen BEFORE removing from config to properly cleanup
    // =========================================================================
    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
            $zone_name = strtolower($zone_cfg['zone'] ?? $zone_id);

            if ($zone_filter !== null && $zone_name !== $zone_filter) {
                continue;
            }

            // Set global cpzone for pfSense functions
            $cpzone = $zone_id;
            $cpzoneid = $zone_cfg['zoneid'] ?? '';

            // Read captive portal database and find sessions for this MAC
            $db_file = "/var/db/captiveportal{$zone_id}.db";
            if (file_exists($db_file)) {
                try {
                    $db = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
                    $db->busyTimeout(5000);

                    $mac_escaped = SQLite3::escapeString(strtolower($mac));
                    $result = $db->query("SELECT * FROM captiveportal WHERE lower(mac) = '{$mac_escaped}'");

                    if ($result) {
                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                            $sessionid = $row['sessionid'] ?? '';
                            if (!empty($sessionid)) {
                                // Disconnect this session using pfSense function
                                // Term cause 1 = User Request
                                if (function_exists('captiveportal_disconnect_client')) {
                                    captiveportal_disconnect_client($sessionid, 1, "DISCONNECT - MAC BINDING REMOVED");
                                    $sessions_disconnected++;
                                    log_api('INFO', "Disconnected session {$sessionid} for MAC {$mac} in zone {$zone_name}");
                                }
                            }
                        }
                    }
                    $db->close();
                } catch (Exception $e) {
                    log_api('WARN', "Failed to read captive portal DB for zone {$zone_id}: " . $e->getMessage());
                }
            }
        }
    }

    // =========================================================================
    // STEP 3: Remove from pfSense passthrumac config AND flush firewall rules
    // =========================================================================
    $config_changed = false;

    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => &$zone_cfg) {
            $zone_name = strtolower($zone_cfg['zone'] ?? $zone_id);

            if ($zone_filter !== null && $zone_name !== $zone_filter) {
                continue;
            }

            if (!isset($zone_cfg['passthrumac']) || !is_array($zone_cfg['passthrumac'])) {
                continue;
            }

            // Set global cpzone for pfSense functions
            $cpzone = $zone_id;
            $cpzoneid = $zone_cfg['zoneid'] ?? '';

            $new_macs = [];
            foreach ($zone_cfg['passthrumac'] as $idx => $entry) {
                $entry_mac = normalize_mac($entry['mac'] ?? '');

                if (strtolower($entry_mac) === strtolower($mac)) {
                    // Found matching MAC - DELETE firewall rules BEFORE removing from config
                    // This is CRITICAL for immediately cutting internet access

                    // Method 1: Use pfSense's built-in function to delete PF anchor rules
                    if (function_exists('captiveportal_passthrumac_delete_entry')) {
                        captiveportal_passthrumac_delete_entry($entry);
                        $firewall_rules_flushed++;
                        log_api('INFO', "Deleted PF rules for MAC {$mac} in zone {$zone_name} via captiveportal_passthrumac_delete_entry");
                    } else {
                        // Method 2: Manual PF flush if function not available
                        $host = str_replace(":", "", $entry_mac);
                        $cpzoneprefix = "cpzone" . ($zone_cfg['zoneid'] ?? '');

                        // Flush ether rules
                        if (function_exists('pfSense_pf_cp_flush')) {
                            pfSense_pf_cp_flush("{$cpzoneprefix}_passthrumac/{$host}", "ether");
                            $firewall_rules_flushed++;
                            log_api('INFO', "Flushed PF rules for MAC {$mac} in zone {$zone_name} via pfSense_pf_cp_flush");
                        }
                    }

                    $config_changed = true;
                    $removed_from_config++;
                    // Don't add to new_macs (effectively removing it)
                } else {
                    // Keep this entry
                    $new_macs[] = $entry;
                }
            }

            $zone_cfg['passthrumac'] = $new_macs;
        }
        unset($zone_cfg);
    }

    // =========================================================================
    // STEP 4: Save config and flush ALL states for this MAC
    // =========================================================================
    if ($config_changed) {
        write_config("macbind_api: removed MAC {$mac} and disconnected user");

        // Additional: Kill any remaining PF states for this MAC address
        // This ensures the user cannot continue using cached connections
        $mac_for_pfctl = strtolower($mac);
        @exec("/sbin/pfctl -k 0.0.0.0/0 -k {$mac_for_pfctl} 2>/dev/null");

        log_api('INFO', "Killed PF states for MAC {$mac}");
    }

    // =========================================================================
    // STEP 5: Verify removal and return result
    // =========================================================================
    $total_removed = $removed_from_active_db + $removed_from_config;

    // Check if MAC was found anywhere
    if ($total_removed === 0 && $sessions_disconnected === 0) {
        // Check if it exists in config but wasn't removed (zone filter mismatch)
        $found_anywhere = false;
        if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
            foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
                if (isset($zone_cfg['passthrumac']) && is_array($zone_cfg['passthrumac'])) {
                    foreach ($zone_cfg['passthrumac'] as $entry) {
                        if (strtolower(normalize_mac($entry['mac'] ?? '')) === strtolower($mac)) {
                            $found_anywhere = true;
                            break 2;
                        }
                    }
                }
            }
        }

        if (!$found_anywhere) {
            error_response('MAC binding not found: ' . $mac, 404);
        }
    }

    log_api('INFO', "remove binding: mac={$mac}, zone=" . ($zone_filter ?? 'all') .
        ", active_db={$removed_from_active_db}, config={$removed_from_config}" .
        ", sessions_disconnected={$sessions_disconnected}, fw_rules_flushed={$firewall_rules_flushed}");

    json_response([
        'success' => true,
        'message' => "Removed binding and disconnected user",
        'mac' => $mac,
        'zone' => $zone_filter,
        'removed_from_active_db' => $removed_from_active_db,
        'removed_from_config' => $removed_from_config,
        'sessions_disconnected' => $sessions_disconnected,
        'firewall_rules_flushed' => $firewall_rules_flushed,
        'total_removed' => $total_removed
    ]);
}

/**
 * POST /update - Update existing MAC binding
 * Body: { "mac": "aa:bb:cc:dd:ee:ff", "zone": "myzone", "description": "...", "action": "pass|block" }
 * 
 * pfSense 2.7.x passthrumac structure:
 * $config['captiveportal'][$zone]['passthrumac'][] = [
 *     'mac' => 'aa:bb:cc:dd:ee:ff',
 *     'action' => 'pass',  // or 'block'
 *     'descr' => 'AUTO_BIND:description'
 * ];
 */
function action_update(): void {
    $body = get_json_body();
    
    if (empty($body['mac'])) {
        error_response('Missing required field: mac');
    }
    
    $mac = normalize_mac($body['mac']);
    if ($mac === '') {
        error_response('Invalid MAC address format');
    }
    
    $zone_filter = isset($body['zone']) ? strtolower(trim($body['zone'])) : null;
    
    // Validate action if provided
    if (isset($body['action']) && !in_array($body['action'], ['pass', 'block'])) {
        error_response('Invalid action. Must be "pass" or "block"');
    }
    
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/captiveportal.inc');
    require_once('/etc/inc/util.inc');
    global $config;
    
    $updated = 0;
    $config_changed = false;
    $updated_zones = [];
    
    if (!isset($config['captiveportal']) || !is_array($config['captiveportal'])) {
        error_response('No captive portal zones configured', 404);
    }
    
    foreach ($config['captiveportal'] as $zone_id => &$zone_cfg) {
        $zone_name = strtolower($zone_cfg['zone'] ?? $zone_id);
        
        if ($zone_filter !== null && $zone_name !== $zone_filter) {
            continue;
        }
        
        if (!isset($zone_cfg['passthrumac']) || !is_array($zone_cfg['passthrumac'])) {
            continue;
        }
        
        foreach ($zone_cfg['passthrumac'] as $idx => &$entry) {
            $entry_mac = normalize_mac($entry['mac'] ?? '');
            if ($entry_mac !== $mac) {
                continue;
            }
            
            // Update action (pass/block) if provided
            if (isset($body['action'])) {
                $entry['action'] = $body['action'];
                $config_changed = true;
            }
            
            // Update description if provided
            if (isset($body['description'])) {
                $descr = $entry['descr'] ?? '';
                // Preserve AUTO_BIND prefix if it was already there
                if (strpos($descr, TAG_PREFIX) === 0) {
                    $entry['descr'] = TAG_PREFIX . $body['description'];
                } else {
                    $entry['descr'] = $body['description'];
                }
                $config_changed = true;
            }
            
            $updated++;
            if (!in_array($zone_name, $updated_zones)) {
                $updated_zones[] = $zone_name;
            }
        }
        unset($entry);
    }
    unset($zone_cfg);
    
    if ($updated === 0) {
        error_response('MAC binding not found', 404);
    }
    
    // Update active binding database if expires_at is provided
    $active_db_updated = false;
    if (isset($body['expires_at']) && !empty($body['expires_at'])) {
        $expires_ts = strtotime($body['expires_at']);
        if ($expires_ts !== false) {
            $expires_iso = gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
            
            // Load active DB
            if (file_exists(ACTIVE_DB_FILE)) {
                $json = @file_get_contents(ACTIVE_DB_FILE);
                if ($json) {
                    $data = json_decode($json, true);
                    if (isset($data['bindings']) && is_array($data['bindings'])) {
                        $found = false;
                        foreach ($data['bindings'] as $key => &$binding) {
                            if (strtolower($binding['mac'] ?? '') === $mac) {
                                if ($zone_filter === null || strtolower($binding['zone'] ?? '') === $zone_filter) {
                                    $binding['expires_at'] = $expires_iso;
                                    $found = true;
                                    $active_db_updated = true;
                                }
                            }
                        }
                        unset($binding);
                        
                        if ($active_db_updated) {
                            $data['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                            
                            // Save active DB
                            $tmp = ACTIVE_DB_FILE . '.tmp';
                            if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false) {
                                if (@rename($tmp, ACTIVE_DB_FILE)) {
                                    log_api('INFO', "Updated expires_at in active DB: mac={$mac}, expires={$expires_iso}");
                                } else {
                                    @unlink($tmp);
                                    log_api('WARNING', "Failed to update active DB file");
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    if ($config_changed) {
        write_config("macbind_api: updated MAC {$mac}");
        
        // Reload captive portal for affected zones (pfSense 2.7.x)
        foreach ($updated_zones as $zone_name) {
            if (function_exists('captiveportal_configure_zone')) {
                captiveportal_configure_zone($zone_name);
            }
        }
        // Fallback to full reload if zone-specific function not available
        if (empty($updated_zones) && function_exists('captiveportal_configure')) {
            captiveportal_configure();
        }
    }
    
    log_api('INFO', "update binding: mac={$mac}, updated={$updated}, expires_updated=" . ($active_db_updated ? 'yes' : 'no'));
    json_response([
        'success' => true, 
        'message' => "Updated {$updated} binding(s)", 
        'mac' => $mac,
        'updated_count' => $updated,
        'zones' => $updated_zones,
        'expires_updated' => $active_db_updated
    ]);
}

/**
 * POST /sync - Trigger immediate sync
 */
function action_sync(): void {
    if (!file_exists(SYNC_SCRIPT)) {
        error_response('Sync script not found', 500);
    }
    
    // Run sync script
    $output = [];
    $return_code = 0;
    exec('/usr/local/bin/php ' . escapeshellarg(SYNC_SCRIPT) . ' 2>&1', $output, $return_code);
    
    log_api('INFO', "sync triggered, return_code={$return_code}");
    json_response([
        'success' => $return_code === 0,
        'message' => $return_code === 0 ? 'Sync completed' : 'Sync failed',
        'return_code' => $return_code,
        'output' => $output
    ]);
}

/**
 * POST /cleanup_expired - Remove expired bindings and DISCONNECT users
 * This function checks bindings in pfSense config against active DB expiration
 * and removes any that are expired, IMMEDIATELY cutting internet access
 *
 * CRITICAL: Must disconnect sessions and flush PF rules for expired MACs
 */
function action_cleanup_expired(): void {
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/captiveportal.inc');
    require_once('/etc/inc/util.inc');
    global $config, $cpzone, $cpzoneid;

    $now_ts = time();
    $removed_count = 0;
    $sessions_disconnected = 0;
    $firewall_rules_flushed = 0;
    $config_changed = false;

    // Load active DB to check expiration times
    $active_db = [];
    if (file_exists(ACTIVE_DB_FILE)) {
        $json = @file_get_contents(ACTIVE_DB_FILE);
        if ($json) {
            $active_db = json_decode($json, true);
        }
    }

    // Build map of MAC -> expiration from active DB
    $expiration_map = [];
    if (isset($active_db['bindings']) && is_array($active_db['bindings'])) {
        foreach ($active_db['bindings'] as $binding) {
            $mac = strtolower($binding['mac'] ?? '');
            if (!empty($mac) && !empty($binding['expires_at'])) {
                $expires_ts = strtotime($binding['expires_at']);
                if ($expires_ts !== false) {
                    $expiration_map[$mac] = $expires_ts;
                }
            }
        }
    }

    // Also check voucher sessions for pfSense auto-added MACs
    $voucher_session_map = build_voucher_session_map();

    // Track MACs to disconnect
    $macs_to_disconnect = [];

    // Check all passthrumac entries in pfSense config
    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => &$zone_cfg) {
            $zone_name = strtolower($zone_cfg['zone'] ?? $zone_id);

            if (!isset($zone_cfg['passthrumac']) || !is_array($zone_cfg['passthrumac'])) {
                continue;
            }

            // Set global cpzone for pfSense functions
            $cpzone = $zone_id;
            $cpzoneid = $zone_cfg['zoneid'] ?? '';

            $new_passthrumac = [];
            foreach ($zone_cfg['passthrumac'] as $entry) {
                $mac = normalize_mac($entry['mac'] ?? '');
                if (empty($mac)) {
                    $new_passthrumac[] = $entry;
                    continue;
                }

                $mac_lower = strtolower($mac);
                $should_remove = false;
                $descr = $entry['descr'] ?? '';

                // Check if this MAC has an expiration in active DB
                if (isset($expiration_map[$mac_lower])) {
                    if ($expiration_map[$mac_lower] <= $now_ts) {
                        $should_remove = true;
                        log_api('INFO', "Removing expired binding: zone={$zone_name}, mac={$mac}, expired_at=" . gmdate('Y-m-d\TH:i:s\Z', $expiration_map[$mac_lower]));
                    }
                }
                // Check pfSense auto-added voucher MACs
                elseif (preg_match('/^Auto-added for voucher\s+(.+)$/i', $descr, $matches)) {
                    $voucher_code = trim($matches[1]);
                    // Check if voucher session is expired
                    $key_voucher = "{$zone_name}:voucher:{$voucher_code}";
                    if (isset($voucher_session_map[$key_voucher])) {
                        if ($voucher_session_map[$key_voucher]['expires_ts'] <= $now_ts) {
                            $should_remove = true;
                            log_api('INFO', "Removing expired pfSense auto voucher MAC: zone={$zone_name}, mac={$mac}, voucher={$voucher_code}");
                        }
                    }
                }
                // AUTO_BIND entry but not in active DB - likely orphaned
                elseif (strpos($descr, TAG_PREFIX) === 0) {
                    $should_remove = true;
                    log_api('INFO', "Removing orphaned AUTO_BIND entry: zone={$zone_name}, mac={$mac}");
                }

                if ($should_remove) {
                    // DELETE firewall rules BEFORE removing from config
                    if (function_exists('captiveportal_passthrumac_delete_entry')) {
                        captiveportal_passthrumac_delete_entry($entry);
                        $firewall_rules_flushed++;
                    } else {
                        // Manual PF flush
                        $host = str_replace(":", "", $mac);
                        $cpzoneprefix = "cpzone" . ($zone_cfg['zoneid'] ?? '');
                        if (function_exists('pfSense_pf_cp_flush')) {
                            pfSense_pf_cp_flush("{$cpzoneprefix}_passthrumac/{$host}", "ether");
                            $firewall_rules_flushed++;
                        }
                    }

                    $macs_to_disconnect[$mac_lower] = $zone_id;
                    $config_changed = true;
                    $removed_count++;
                } else {
                    $new_passthrumac[] = $entry;
                }
            }

            $zone_cfg['passthrumac'] = $new_passthrumac;
        }
        unset($zone_cfg);
    }

    // Disconnect sessions for removed MACs
    foreach ($macs_to_disconnect as $mac_lower => $zone_id) {
        $cpzone = $zone_id;

        // Find and disconnect any active sessions
        $db_file = "/var/db/captiveportal{$zone_id}.db";
        if (file_exists($db_file)) {
            try {
                $db = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
                $db->busyTimeout(5000);

                $mac_escaped = SQLite3::escapeString($mac_lower);
                $result = $db->query("SELECT sessionid FROM captiveportal WHERE lower(mac) = '{$mac_escaped}'");

                if ($result) {
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                        $sessionid = $row['sessionid'] ?? '';
                        if (!empty($sessionid) && function_exists('captiveportal_disconnect_client')) {
                            captiveportal_disconnect_client($sessionid, 1, "DISCONNECT - EXPIRED");
                            $sessions_disconnected++;
                        }
                    }
                }
                $db->close();
            } catch (Exception $e) {
                log_api('WARN', "Failed to disconnect sessions for zone {$zone_id}: " . $e->getMessage());
            }
        }

        // Kill PF states for this MAC
        @exec("/sbin/pfctl -k 0.0.0.0/0 -k {$mac_lower} 2>/dev/null");
    }

    // Write config if changes occurred
    if ($config_changed) {
        write_config("macbind_api: cleanup_expired removed {$removed_count} expired binding(s)");
    }

    // Also cleanup expired voucher_usage records from active DB
    $voucher_usage_cleaned = 0;
    if (file_exists(ACTIVE_DB_FILE)) {
        $active_json = @file_get_contents(ACTIVE_DB_FILE);
        if ($active_json) {
            $active_data = json_decode($active_json, true);
            if (isset($active_data['voucher_usage']) && is_array($active_data['voucher_usage'])) {
                $cleaned_voucher_usage = [];
                foreach ($active_data['voucher_usage'] as $key => $usage) {
                    $expires_ts = strtotime($usage['expires_at'] ?? '');
                    // Keep if not expired or expiry is unknown
                    if ($expires_ts === false || $expires_ts > $now_ts) {
                        $cleaned_voucher_usage[$key] = $usage;
                    } else {
                        $voucher_usage_cleaned++;
                    }
                }
                // Only write if we actually cleaned something
                if ($voucher_usage_cleaned > 0) {
                    $active_data['voucher_usage'] = $cleaned_voucher_usage;
                    $active_data['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $tmp = ACTIVE_DB_FILE . '.tmp';
                    if (@file_put_contents($tmp, json_encode($active_data, JSON_PRETTY_PRINT), LOCK_EX)) {
                        @rename($tmp, ACTIVE_DB_FILE);
                    }
                    log_api('INFO', "cleanup_expired: cleaned {$voucher_usage_cleaned} expired voucher_usage records");
                }
            }
        }
    }

    log_api('INFO', "cleanup_expired: removed={$removed_count}, sessions_disconnected={$sessions_disconnected}, fw_rules_flushed={$firewall_rules_flushed}, voucher_usage_cleaned={$voucher_usage_cleaned}");
    json_response([
        'success' => true,
        'message' => "Removed {$removed_count} expired binding(s) and disconnected users",
        'removed_count' => $removed_count,
        'sessions_disconnected' => $sessions_disconnected,
        'firewall_rules_flushed' => $firewall_rules_flushed,
        'voucher_usage_cleaned' => $voucher_usage_cleaned
    ]);
}

/**
 * GET /search - Search for MAC or IP
 */
function action_search(): void {
    $query = $_GET['q'] ?? '';
    
    if (empty($query)) {
        error_response('Missing search query parameter: q');
    }
    
    $results = [];
    
    if (file_exists(ACTIVE_DB_FILE)) {
        $json = @file_get_contents(ACTIVE_DB_FILE);
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['bindings']) && is_array($data['bindings'])) {
                $query_lower = strtolower($query);
                foreach ($data['bindings'] as $binding) {
                    $mac = $binding['mac'] ?? '';
                    $ip = $binding['src_ip'] ?? '';
                    
                    if (strpos($mac, $query_lower) !== false || strpos($ip, $query_lower) !== false) {
                        $results[] = $binding;
                    }
                }
            }
        }
    }
    
    log_api('INFO', "search request, query={$query}, results=" . count($results));
    json_response([
        'success' => true,
        'query' => $query,
        'count' => count($results),
        'results' => $results
    ]);
}

/**
 * GET/POST /backup - Export pfSense configuration backup with advanced options
 * Returns base64-encoded encrypted XML or plain XML based on parameters
 * 
 * Parameters (via GET query string or POST JSON body):
 *   encrypt=true         - Encrypt backup with AES-256-CBC
 *   password=xxx         - Password for encryption (required if encrypt=true)
 *   include_extra=true   - Include extra data (DHCP leases, captive portal db, etc.)
 *   skip_packages=true   - Exclude package information from backup
 *   skip_rrd=true        - Exclude RRD/graph data from backup (reduces size significantly)
 *   include_ssh_keys=true - Include SSH host keys in backup
 *   backup_area=all      - Backup area: 'all', 'captiveportal', 'aliases', 'nat', 'filter', etc.
 * 
 * Response includes metadata and base64-encoded config XML.
 */
function action_backup(): void {
    // Load pfSense backup functions
    if (!file_exists('/etc/inc/config.inc')) {
        error_response('Not running on pfSense', 500);
    }
    
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/util.inc');
    
    // Include backup.inc to get access to rrd_data_xml() function
    // This is needed to generate RRD data on-the-fly during backup
    // The function reads .rrd files from /var/db/rrd/ and converts them to XML
    // Path varies by pfSense version - try common locations
    $backup_inc_paths = [
        '/usr/local/pfSense/include/www/backup.inc',  // pfSense 2.7+
        '/usr/local/www/backup.inc',                   // Alternative location
        '/etc/inc/backup.inc'                          // Fallback
    ];

    // CRITICAL: Define these variables in GLOBAL scope BEFORE loading backup.inc
    // backup.inc defines these at file scope, but when require_once is called from
    // within a function, the variables go into local scope instead of global.
    // The rrd_data_xml() function uses "global $rrddbpath" which looks in global scope.
    // By defining them globally here first, the function will find them.
    global $rrddbpath, $rrdtool;
    $rrddbpath = "/var/db/rrd";
    $rrdtool = "/usr/bin/nice -n20 /usr/local/bin/rrdtool";

    $backup_inc_loaded = false;
    foreach ($backup_inc_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $backup_inc_loaded = true;
            log_api('DEBUG', 'Loaded backup.inc from: ' . $path . ' (rrddbpath=' . $rrddbpath . ')');
            break;
        }
    }
    
    // If backup.inc not found, define rrd_data_xml() function ourselves
    // This matches the implementation from pfSense backup.inc
    if (!$backup_inc_loaded || !function_exists('rrd_data_xml')) {
        $rrddbpath = "/var/db/rrd";
        $rrdtool = "/usr/bin/nice -n20 /usr/local/bin/rrdtool";
        
        if (!function_exists('rrd_data_xml')) {
            function rrd_data_xml() {
                global $rrddbpath, $rrdtool;
                log_api('DEBUG', 'rrd_data_xml() called - rrddbpath: ' . $rrddbpath . ', rrdtool: ' . $rrdtool);
                $result = "\t<rrddata>\n";
                $rrd_files = glob("{$rrddbpath}/*.rrd");
                
                log_api('DEBUG', 'RRD files found: ' . count($rrd_files) . ' in ' . $rrddbpath);
                
                if (empty($rrd_files)) {
                    log_api('WARNING', 'No RRD files found in ' . $rrddbpath . ' - RRD data will be empty');
                    $result .= "\t</rrddata>\n";
                    return $result;
                }
                
                foreach ($rrd_files as $rrd_file) {
                    $basename = basename($rrd_file);
                    $xml_file = preg_replace('/\.rrd$/', ".xml", $rrd_file);
                    
                    log_api('DEBUG', "Processing RRD file: {$basename}");
                    
                    // Execute rrdtool dump command - matches pfSense backup.inc line 54
                    $output = array();
                    $return_var = 0;
                    $cmd = "{$rrdtool} dump " . escapeshellarg($rrd_file) . ' ' . escapeshellarg($xml_file) . ' 2>&1';
                    log_api('DEBUG', "Executing: {$cmd}");
                    exec($cmd, $output, $return_var);
                    
                    if ($return_var !== 0) {
                        log_api('WARNING', "rrdtool dump failed for {$basename} (exit code: {$return_var}): " . implode("\n", $output));
                        continue;
                    }
                    
                    log_api('DEBUG', "rrdtool dump succeeded for {$basename}");
                    
                    $xml_data = @file_get_contents($xml_file);
                    if ($xml_data === false || empty($xml_data)) {
                        log_api('WARNING', "Failed to read XML dump for {$basename}");
                        @unlink($xml_file);
                        continue;
                    }
                    
                    // Clean up temp XML file
                    @unlink($xml_file);
                    
                    // Compress and encode - matches pfSense backup.inc line 60
                    $compressed = @gzdeflate($xml_data);
                    if ($compressed === false) {
                        log_api('WARNING', "gzdeflate failed for {$basename}, using uncompressed data");
                        $compressed = $xml_data;
                    }
                    
                    $result .= "\t\t<rrddatafile>\n";
                    $result .= "\t\t\t<filename>{$basename}</filename>\n";
                    $result .= "\t\t\t<xmldata>" . base64_encode($compressed) . "</xmldata>\n";
                    $result .= "\t\t</rrddatafile>\n";
                }
                $result .= "\t</rrddata>\n";
                return $result;
            }
            log_api('DEBUG', 'Defined rrd_data_xml() function locally');
        }
    }
    
    // Check options from GET or POST body
    $body = get_json_body();
    
    // Helper to get option from GET or body
    // Explicitly handles both true and false boolean values to ensure proper parameter handling
    $get_option = function($key, $default = false) use ($body) {
        // Check GET parameters first
        if (isset($_GET[$key])) {
            $val = $_GET[$key];
            // Explicitly check for false values first
            if ($val === 'false' || $val === '0' || $val === false || $val === 0) {
                return false;
            }
            // Then check for true values
            return $val === 'true' || $val === '1' || $val === true || $val === 1;
        }
        // Check POST body (JSON)
        if (isset($body[$key])) {
            $val = $body[$key];
            // Explicitly check for false values first (important for JSON boolean false)
            if ($val === false || $val === 'false' || $val === 0 || $val === '0') {
                return false;
            }
            // Then check for true values
            return $val === true || $val === 'true' || $val === 1 || $val === '1';
        }
        return $default;
    };
    
    $get_string_option = function($key, $default = '') use ($body) {
        return $_GET[$key] ?? $body[$key] ?? $default;
    };
    
    // Parse all options
    // IMPORTANT: Defaults match pfSense behavior - RRD data is INCLUDED by default (skip_rrd=false)
    // This matches pfSense backup.inc line 213: RRD is included when donotbackuprrd is NOT checked
    $encrypt = $get_option('encrypt');
    $password = $get_string_option('password');
    $include_extra = $get_option('include_extra');
    $skip_packages = $get_option('skip_packages');
    $skip_rrd = $get_option('skip_rrd', false);  // Explicitly default to false = include RRD data
    $include_ssh_keys = $get_option('include_ssh_keys');
    $backup_area = $get_string_option('backup_area', 'all');
    
    // Log backup options for verification
    // skip_rrd=false means RRD data WILL be included (matches pfSense backup.inc line 213)
    log_api('INFO', sprintf('backup request: skip_rrd=%s (RRD data will be %s), skip_packages=%s, include_extra=%s, include_ssh_keys=%s, backup_area=%s, encrypt=%s',
        $skip_rrd ? 'true' : 'false',
        $skip_rrd ? 'EXCLUDED' : 'INCLUDED',
        $skip_packages ? 'true' : 'false',
        $include_extra ? 'true' : 'false',
        $include_ssh_keys ? 'true' : 'false',
        $backup_area,
        $encrypt ? 'true' : 'false'
    ));
    
    if ($encrypt && empty($password)) {
        error_response('Password required for encrypted backup');
    }
    
    // Get config file path
    $config_file = '/cf/conf/config.xml';
    if (!file_exists($config_file)) {
        $config_file = '/conf/config.xml';
    }
    
    if (!file_exists($config_file)) {
        error_response('Config file not found', 500);
    }
    
    // Read the config file
    $config_content = @file_get_contents($config_file);
    if ($config_content === false) {
        error_response('Failed to read config file', 500);
    }
    
    // Get system info for metadata
    global $config;
    $hostname = $config['system']['hostname'] ?? gethostname();
    $domain = $config['system']['domain'] ?? 'local';
    $version = file_exists('/etc/version') ? trim(@file_get_contents('/etc/version')) : 'unknown';
    
    // Apply backup area filter if not 'all'
    $filtered_areas = [];
    if ($backup_area !== 'all' && $backup_area !== '') {
        // Parse XML to filter specific sections
        $xml = @simplexml_load_string($config_content);
        if ($xml !== false) {
            $areas = array_map('trim', explode(',', $backup_area));
            $filtered_areas = $areas;
            
            // Create a new filtered XML with only requested areas
            $filtered_xml = new SimpleXMLElement('<?xml version="1.0"?><pfsense></pfsense>');
            
            // Always include version and revision info
            if (isset($xml->version)) {
                $filtered_xml->addChild('version', (string)$xml->version);
            }
            if (isset($xml->revision)) {
                $revision = $filtered_xml->addChild('revision');
                foreach ($xml->revision->children() as $child) {
                    $revision->addChild($child->getName(), (string)$child);
                }
            }
            
            // Add requested areas
            foreach ($areas as $area) {
                if (isset($xml->$area)) {
                    // Deep copy the XML node
                    $dom = dom_import_simplexml($filtered_xml);
                    $dom_area = dom_import_simplexml($xml->$area);
                    $dom->appendChild($dom->ownerDocument->importNode($dom_area, true));
                }
            }
            
            $config_content = $filtered_xml->asXML();
        }
    }
    
    // ============================================================================
    // RRD DATA HANDLING (pfSense 2.7.2 compatible)
    // ============================================================================
    // IMPORTANT: RRD data is NOT stored in config.xml - it must be generated on-the-fly
    // using the rrd_data_xml() function from backup.inc which reads .rrd files from /var/db/rrd/
    // This matches pfSense's native backup behavior (see backup.inc line 213-217)
    //
    // Default behavior: skip_rrd=false means RRD data WILL be included (matches pfSense)
    // Only when skip_rrd=true OR backup_area != 'all' will RRD data be excluded
    // ============================================================================
    
    // First, remove any existing rrddata/sshdata tags from config (they shouldn't be there, but clean up)
    // This matches pfSense backup.inc lines 207-211 behavior exactly
    foreach (['rrd', 'ssh'] as $tag) {
        $config_content = preg_replace("/[[:blank:]]*<{$tag}data>.*<\\/{$tag}data>[[:blank:]]*\n*/s", "", $config_content);
        $config_content = preg_replace("/[[:blank:]]*<{$tag}data\\/>[[:blank:]]*\n*/", "", $config_content);
    }
    
    log_api('DEBUG', sprintf('RRD handling: skip_rrd=%s, backup_area=%s, rrd_data_xml() exists=%s',
        $skip_rrd ? 'true' : 'false',
        $backup_area,
        function_exists('rrd_data_xml') ? 'yes' : 'no'
    ));
    
    if ($skip_rrd === true) {
        // Skip RRD data - already removed above
        log_api('INFO', 'RRD data excluded from backup (skip_rrd=true)');
    } else {
        // Include RRD data - generate it on-the-fly using pfSense's rrd_data_xml() function
        // This matches the behavior in backup.inc line 213-217
        // Only include RRD data when backing up entire config (backup_area === 'all')
        if ($backup_area === 'all' || $backup_area === '') {
            if (function_exists('rrd_data_xml')) {
                log_api('DEBUG', 'Generating RRD data for backup...');
                $rrd_data_xml = rrd_data_xml();
                
                $rrd_data_size = strlen($rrd_data_xml);
                $rrd_file_count = substr_count($rrd_data_xml, '<rrddatafile>');
                
                // Check if we actually got RRD data (more than just opening/closing tags)
                $has_rrd_content = ($rrd_data_size > 20); // More than just "<rrddata>\n\t</rrddata>\n"
                
                if ($has_rrd_content) {
                    // Insert RRD data before the closing </pfsense> tag
                    // Matches pfSense backup.inc line 215-216: str_replace($closing_tag, $rrd_data_xml . $closing_tag, $data)
                    $closing_tag = '</pfsense>';
                    $pos = strrpos($config_content, $closing_tag);
                    
                    if ($pos !== false) {
                        // Insert RRD data before closing tag
                        $config_content = substr_replace($config_content, $rrd_data_xml . $closing_tag, $pos, strlen($closing_tag));
                        
                        // Validate that RRD data was actually inserted
                        $final_has_rrd = (strpos($config_content, '<rrddata>') !== false);
                        if ($final_has_rrd) {
                            log_api('INFO', sprintf('RRD data included in backup: %d bytes, %d RRD files',
                                $rrd_data_size,
                                $rrd_file_count
                            ));
                        } else {
                            log_api('ERROR', 'RRD data generation succeeded but insertion failed - backup may be incomplete');
                        }
                    } else {
                        log_api('ERROR', 'Could not find </pfsense> closing tag to insert RRD data');
                    }
                } else {
                    log_api('INFO', sprintf('RRD data generation returned empty (no RRD files found or all failed). Size: %d bytes, Files: %d',
                        $rrd_data_size,
                        $rrd_file_count
                    ));
                }
            } else {
                log_api('WARNING', 'rrd_data_xml() function not available - RRD data cannot be included in backup');
            }
        } else {
            // When backing up a specific area (not "all"), don't include RRD data
            // This matches pfSense backup.inc behavior (line 185 check: $post['backuparea'] != "rrddata")
            log_api('DEBUG', 'RRD data not included (backup_area is "' . $backup_area . '", not "all")');
        }
    }
    
    // Remove package information if requested
    if ($skip_packages) {
        $config_content = preg_replace('/<installedpackages>.*?<\/installedpackages>/s', '<installedpackages></installedpackages>', $config_content);
    }

    // ============================================================================
    // EXTRA DATA HANDLING (pfSense 2.7.2 compatible)
    // ============================================================================
    // pfSense embeds extra data INSIDE the relevant config sections, not at root level.
    // This matches pfSense's backup.inc behavior (lines 189-198):
    //   - <captiveportaldata> goes inside <captiveportal> section
    //   - <dhcpddata> goes inside <dhcpd> section
    //   - <voucherdata> goes inside <voucher> section
    //   - <sshdata> goes before </pfsense> (root level - this is correct)
    //
    // Supported DHCP backends (pfSense 2.7.2):
    //   - ISC DHCP: /var/dhcpd/var/db/dhcpd.leases
    //   - Kea DHCP: /var/lib/kea/dhcp4.leases
    // ============================================================================

    // Define backup paths matching pfSense globals.inc 'backuppath' array
    $backuppath = [
        'captiveportal' => '/var/db/captiveportal*.db',  // Includes usedmacs files
        'dhcpd' => '{/var/dhcpd/var/db/dhcpd.leases,/var/lib/kea/dhcp4.leases}',  // ISC + Kea
        'voucher' => '/var/db/voucher_*.db'
    ];

    /**
     * Generate XML data for backup files - matches pfSense backup_xmldatafile()
     * @param string $type - The backup type (captiveportal, dhcpd, voucher)
     * @param bool $tab - Whether to add extra indentation (for full backup)
     * @return string - XML fragment or empty string
     */
    $backup_xmldatafile = function($type, $tab = false) use ($backuppath) {
        if (!isset($backuppath[$type])) {
            return '';
        }

        // Use GLOB_BRACE to support {path1,path2} syntax for Kea DHCP
        $xmldata_files = glob($backuppath[$type], GLOB_BRACE);
        if (empty($xmldata_files)) {
            log_api('DEBUG', "No backup files found for type '{$type}' with pattern: {$backuppath[$type]}");
            return '';
        }

        // Remove duplicates (in case patterns overlap)
        $xmldata_files = array_unique($xmldata_files);

        $t = $tab ? "\t" : "";
        $result = "{$t}\t<{$type}data>\n";
        $file_count = 0;

        foreach ($xmldata_files as $xmldata_file) {
            if (!file_exists($xmldata_file) || !is_readable($xmldata_file)) {
                continue;
            }

            $data = @file_get_contents($xmldata_file);
            if ($data === false) {
                log_api('WARNING', "Failed to read backup file: {$xmldata_file}");
                continue;
            }

            $basename = basename($xmldata_file);
            $dirname = dirname($xmldata_file);

            // Compress data - matches pfSense backup.inc line 85
            $compressed = @gzdeflate($data);
            if ($compressed === false) {
                log_api('WARNING', "gzdeflate failed for {$basename}, using uncompressed");
                $compressed = $data;
            }

            $result .= "{$t}\t\t<xmldatafile>\n";
            $result .= "{$t}\t\t\t<filename>{$basename}</filename>\n";
            $result .= "{$t}\t\t\t<path>{$dirname}</path>\n";
            $result .= "{$t}\t\t\t<data>" . base64_encode($compressed) . "</data>\n";
            $result .= "{$t}\t\t</xmldatafile>\n";
            $file_count++;
        }

        $result .= "{$t}\t</{$type}data>\n";

        log_api('DEBUG', "backup_xmldatafile({$type}): {$file_count} files processed");
        return $result;
    };

    // Remove any existing extra data tags to avoid duplicates
    // Matches pfSense backup.inc lines 192 (clear_tagdata call before adding new data)
    // Note: rrd and ssh tags are already cleaned above (lines 1387-1390)
    foreach (['captiveportal', 'dhcpd', 'voucher'] as $tag) {
        $config_content = preg_replace("/[[:blank:]]*<{$tag}data>.*<\\/{$tag}data>[[:blank:]]*\n*/s", "", $config_content);
        $config_content = preg_replace("/[[:blank:]]*<{$tag}data\\/>[[:blank:]]*\n*/", "", $config_content);
    }

    $extra_data_log = [];

    if ($include_extra) {
        // ============================================================================
        // FULL BACKUP with extra data (backup_area = 'all')
        // pfSense embeds extra data INSIDE each config section
        // See backup.inc lines 189-198
        // ============================================================================
        if ($backup_area === 'all' || $backup_area === '') {
            foreach ($backuppath as $bk => $path) {
                // Check if this section exists in config
                if (!empty($config[$bk])) {
                    $dataxml = $backup_xmldatafile($bk, true);  // $tab=true for full backup
                    if (!empty($dataxml) && strlen($dataxml) > 30) {  // More than empty tags
                        // Insert data INSIDE the section, before closing tag
                        // Matches pfSense: str_replace($closing_tag, $dataxml . $closing_tag, $data)
                        $closing_tag = "\t</{$bk}>";
                        if (strpos($config_content, $closing_tag) !== false) {
                            $config_content = str_replace($closing_tag, $dataxml . $closing_tag, $config_content);
                            $extra_data_log[] = strtoupper($bk);
                            log_api('INFO', "Embedded {$bk}data inside <{$bk}> section");
                        } else {
                            log_api('WARNING', "Could not find closing tag for <{$bk}> section");
                        }
                    }
                } else {
                    log_api('DEBUG', "Section '{$bk}' not found in config, skipping extra data");
                }
            }
        }
        // ============================================================================
        // SECTION-SPECIFIC BACKUP with extra data
        // When backing up a specific area (e.g., captiveportal), include its extra data
        // See backup.inc lines 171-177
        // ============================================================================
        else if (array_key_exists($backup_area, $backuppath)) {
            $dataxml = $backup_xmldatafile($backup_area, false);  // $tab=false for section backup
            if (!empty($dataxml) && strlen($dataxml) > 30) {
                $closing_tag = "</{$backup_area}>";
                if (strpos($config_content, $closing_tag) !== false) {
                    $config_content = str_replace($closing_tag, $dataxml . $closing_tag, $config_content);
                    $extra_data_log[] = strtoupper($backup_area);
                    log_api('INFO', "Embedded {$backup_area}data in section backup");
                }
            }
        }
    }

    // SSH keys - always goes before </pfsense> (root level)
    // This is correct per pfSense backup.inc lines 219-223
    if ($include_ssh_keys) {
        $ssh_key_files = glob('/etc/ssh/ssh_host_*_key');
        $ssh_keys_xml = '';
        $all_keys_found = true;

        foreach ($ssh_key_files as $key_file) {
            if (!file_exists($key_file) || filesize($key_file) == 0) {
                $all_keys_found = false;
                break;
            }
        }

        if ($all_keys_found && !empty($ssh_key_files)) {
            $ssh_keys_xml = "\t<sshdata>\n";
            foreach ($ssh_key_files as $key_file) {
                $key_content = @file_get_contents($key_file);
                $pub_file = $key_file . '.pub';
                $pub_content = file_exists($pub_file) ? @file_get_contents($pub_file) : false;

                if ($key_content !== false) {
                    $basename = basename($key_file);
                    $compressed = @gzdeflate($key_content);
                    if ($compressed === false) {
                        $compressed = $key_content;
                    }
                    $ssh_keys_xml .= "\t\t<sshkeyfile>\n";
                    $ssh_keys_xml .= "\t\t\t<filename>{$basename}</filename>\n";
                    $ssh_keys_xml .= "\t\t\t<xmldata>" . base64_encode($compressed) . "</xmldata>\n";
                    $ssh_keys_xml .= "\t\t</sshkeyfile>\n";
                }

                if ($pub_content !== false) {
                    $basename = basename($pub_file);
                    $compressed = @gzdeflate($pub_content);
                    if ($compressed === false) {
                        $compressed = $pub_content;
                    }
                    $ssh_keys_xml .= "\t\t<sshkeyfile>\n";
                    $ssh_keys_xml .= "\t\t\t<filename>{$basename}</filename>\n";
                    $ssh_keys_xml .= "\t\t\t<xmldata>" . base64_encode($compressed) . "</xmldata>\n";
                    $ssh_keys_xml .= "\t\t</sshkeyfile>\n";
                }
            }
            $ssh_keys_xml .= "\t</sshdata>\n";

            // Insert before </pfsense>
            $closing_tag = '</pfsense>';
            $config_content = str_replace($closing_tag, $ssh_keys_xml . $closing_tag, $config_content);
            $extra_data_log[] = 'SSH';
            log_api('INFO', 'SSH keys embedded before </pfsense>');
        }
    }

    // Log summary of extra data
    if (!empty($extra_data_log)) {
        log_api('INFO', 'Extra data embedded into backup XML: ' . implode(', ', $extra_data_log));
    }
    
    // Validate that RRD data was included (if it should have been)
    $has_rrddata = (strpos($config_content, '<rrddata>') !== false);
    $rrddata_info = null;
    if (!$skip_rrd && ($backup_area === 'all' || $backup_area === '')) {
        if ($has_rrddata) {
            // Count RRD files in the backup
            $rrd_file_count = substr_count($config_content, '<rrddatafile>');
            $rrddata_info = [
                'included' => true,
                'file_count' => $rrd_file_count
            ];
        } else {
            // RRD data should have been included but wasn't
            $rrddata_info = [
                'included' => false,
                'file_count' => 0,
                'warning' => 'RRD data was expected but not found in backup'
            ];
            log_api('WARNING', 'RRD data was expected in backup but validation failed - backup may be incomplete');
        }
    } else {
        $rrddata_info = [
            'included' => false,
            'file_count' => 0,
            'reason' => $skip_rrd ? 'skip_rrd=true' : 'backup_area=' . $backup_area
        ];
    }
    
    // Build extra data info for response
    $extradata_info = [
        'included' => !empty($extra_data_log),
        'sections' => $extra_data_log,
        'kea_dhcp_supported' => true  // Flag that this version supports Kea DHCP
    ];

    $backup_data = [
        'success' => true,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'hostname' => $hostname,
        'fqdn' => $hostname . '.' . $domain,
        'version' => $version,
        'encrypted' => $encrypt,
        'options' => [
            'include_extra' => $include_extra,
            'skip_packages' => $skip_packages,
            'skip_rrd' => $skip_rrd,
            'include_ssh_keys' => $include_ssh_keys,
            'backup_area' => $backup_area,
            'filtered_areas' => $filtered_areas
        ],
        'rrddata' => $rrddata_info,
        'extradata' => $extradata_info,
        'size_bytes' => strlen($config_content)
    ];
    
    if ($encrypt) {
        // Encrypt the backup using AES-256-CBC
        $iv = openssl_random_pseudo_bytes(16);
        $key = hash('sha256', $password, true);
        $encrypted = openssl_encrypt($config_content, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            error_response('Encryption failed', 500);
        }
        
        // Combine IV + encrypted data and base64 encode
        $backup_data['config'] = base64_encode($iv . $encrypted);
        $backup_data['encryption'] = 'AES-256-CBC';
    } else {
        // Plain backup (base64 encoded for JSON transport)
        // Note: Extra data is already embedded in $config_content at this point
        $backup_data['config'] = base64_encode($config_content);
    }
    
    // Update size after embedding extra data
    $backup_data['size_bytes'] = strlen($config_content);
    
    // Build log message with RRD and extra data status
    $options_str = "skip_pkg=" . ($skip_packages ? 'yes' : 'no') .
                   ", skip_rrd=" . ($skip_rrd ? 'yes' : 'no') .
                   ", area=" . $backup_area;
    $rrd_status = $rrddata_info['included'] ?
        sprintf("RRD: %d files", $rrddata_info['file_count']) :
        "RRD: excluded";
    $extra_status = !empty($extra_data_log) ?
        "Extra: " . implode(',', $extra_data_log) :
        "Extra: none";
    log_api('INFO', sprintf("backup exported, encrypted=%s, size=%d bytes, %s, %s, %s",
        $encrypt ? 'yes' : 'no',
        $backup_data['size_bytes'],
        $rrd_status,
        $extra_status,
        $options_str
    ));
    json_response($backup_data);
}

// ============================================================================
// SYSTEM INFORMATION HELPER FUNCTIONS (pfSense 2.7.x / FreeBSD compatible)
// ============================================================================

/**
 * Get system uptime from kern.boottime (FreeBSD/pfSense 2.7.x)
 */
function get_system_uptime(): array {
    $boottime = 0;
    exec('/sbin/sysctl -n kern.boottime', $output);
    if (!empty($output[0]) && preg_match('/sec = (\d+)/', $output[0], $matches)) {
        $boottime = (int)$matches[1];
    }
    
    $uptime_seconds = time() - $boottime;
    $days = floor($uptime_seconds / 86400);
    $hours = floor(($uptime_seconds % 86400) / 3600);
    $minutes = floor(($uptime_seconds % 3600) / 60);
    
    return [
        'seconds' => $uptime_seconds,
        'formatted' => "{$days}d {$hours}h {$minutes}m",
        'boot_time' => gmdate('Y-m-d\TH:i:s\Z', $boottime)
    ];
}

/**
 * Get NTP sync status (pfSense 2.7.x compatible)
 */
function get_ntp_status(): array {
    $status = ['synced' => false, 'server' => null, 'offset' => null, 'stratum' => null];
    
    // Check if ntpd is running
    exec('/bin/pgrep -x ntpd', $pgrep_output, $pgrep_code);
    if ($pgrep_code !== 0) {
        $status['error'] = 'ntpd not running';
        return $status;
    }
    
    // Query NTP peers - use -p for peer list
    exec('/usr/bin/ntpq -pn 2>/dev/null', $ntpq_output);
    foreach ($ntpq_output as $line) {
        // Active peer starts with * (synced)
        if (preg_match('/^\*(\S+)\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+([\d.-]+)/', $line, $m)) {
            $status['synced'] = true;
            $status['server'] = $m[1];
            $status['stratum'] = (int)$m[2];
            $status['offset'] = $m[3] . ' ms';
            break;
        }
    }
    
    return $status;
}

/**
 * Get CPU usage (FreeBSD compatible)
 */
function get_cpu_usage(): array {
    $cpu = ['user' => 0, 'system' => 0, 'idle' => 100, 'total_used' => 0];
    
    // Use vmstat for CPU stats (run twice, use second sample)
    exec('/usr/bin/vmstat 1 2 | tail -1', $vmstat_output);
    if (!empty($vmstat_output[0])) {
        $parts = preg_split('/\s+/', trim($vmstat_output[0]));
        // vmstat output: r b w avm fre flt re pi po fr sr ad0 in sy cs us sy id
        // Position 16=us (user), 17=sy (system), 18=id (idle) in FreeBSD vmstat
        if (count($parts) >= 19) {
            $cpu['user'] = (int)$parts[16];
            $cpu['system'] = (int)$parts[17];
            $cpu['idle'] = (int)$parts[18];
            $cpu['total_used'] = $cpu['user'] + $cpu['system'];
        }
    }
    
    return $cpu;
}

/**
 * Get memory usage (FreeBSD/pfSense 2.7.x)
 */
function get_memory_usage(): array {
    $mem = ['total_mb' => 0, 'used_mb' => 0, 'free_mb' => 0, 'percent' => 0];
    
    // Get physical memory
    exec('/sbin/sysctl -n hw.physmem', $physmem);
    $total_bytes = (int)($physmem[0] ?? 0);
    $mem['total_mb'] = round($total_bytes / 1024 / 1024);
    
    // Get page size and free pages
    exec('/sbin/sysctl -n vm.stats.vm.v_page_size', $page_size_output);
    exec('/sbin/sysctl -n vm.stats.vm.v_free_count', $free_count_output);
    
    $page_size = (int)($page_size_output[0] ?? 4096);
    $free_pages = (int)($free_count_output[0] ?? 0);
    
    $mem['free_mb'] = round(($page_size * $free_pages) / 1024 / 1024);
    $mem['used_mb'] = $mem['total_mb'] - $mem['free_mb'];
    $mem['percent'] = $mem['total_mb'] > 0 
        ? round(($mem['used_mb'] / $mem['total_mb']) * 100) 
        : 0;
    
    return $mem;
}

/**
 * Get disk usage for root filesystem
 */
function get_disk_usage(): array {
    $disk = ['total' => 'unknown', 'used' => 'unknown', 'available' => 'unknown', 'percent' => 0];
    
    exec('/bin/df -h /', $df_output);
    if (isset($df_output[1])) {
        $parts = preg_split('/\s+/', $df_output[1]);
        if (count($parts) >= 5) {
            $disk['total'] = $parts[1] ?? 'unknown';
            $disk['used'] = $parts[2] ?? 'unknown';
            $disk['available'] = $parts[3] ?? 'unknown';
            $disk['percent'] = (int)str_replace('%', '', $parts[4] ?? '0');
        }
    }
    
    return $disk;
}

/**
 * Get last admin logins from auth.log
 */
function get_admin_logins(int $limit = 5): array {
    $logins = [];
    $auth_log = '/var/log/auth.log';
    
    if (!file_exists($auth_log)) {
        return $logins;
    }
    
    // Parse auth.log for successful logins (SSH and web UI)
    exec("grep -E '(Accepted|authentication success)' " . 
         escapeshellarg($auth_log) . " 2>/dev/null | tail -" . (int)$limit, $log_lines);
    
    foreach ($log_lines as $line) {
        // SSH: "Accepted publickey for admin from 192.168.1.5"
        if (preg_match('/(\w+\s+\d+\s+[\d:]+).*Accepted\s+\S+\s+for\s+(\S+)\s+from\s+([\d.]+)/', $line, $m)) {
            $logins[] = [
                'user' => $m[2],
                'ip' => $m[3],
                'time' => $m[1],
                'method' => 'ssh'
            ];
        }
        // Web UI auth patterns
        elseif (preg_match('/(\w+\s+\d+\s+[\d:]+).*authentication success.*user[=:]?\s*(\S+)/', $line, $m)) {
            $logins[] = [
                'user' => trim($m[2], '"\''),
                'ip' => 'web',
                'time' => $m[1],
                'method' => 'webui'
            ];
        }
        // PHP-FPM / nginx access pattern
        elseif (preg_match('/(\w+\s+\d+\s+[\d:]+).*(\d+\.\d+\.\d+\.\d+).*"(\w+)".*logged in/', $line, $m)) {
            $logins[] = [
                'user' => $m[3],
                'ip' => $m[2],
                'time' => $m[1],
                'method' => 'webui'
            ];
        }
    }
    
    return array_reverse($logins); // Newest first
}

/**
 * GET /vouchers - Get active captive portal sessions (voucher users)
 * Returns all active sessions from all captive portal zones
 * 
 * pfSense 2.7.x uses SQLite for captive portal sessions: /var/db/captiveportal*.db
 * Each zone has its own database file.
 */
function action_vouchers(): void {
    $zone_filter = $_GET['zone'] ?? null;
    $body = get_json_body();
    if (isset($body['zone'])) {
        $zone_filter = strtolower($body['zone']);
    }
    
    // Check for diagnostic mode
    $diagnostic = isset($_GET['diagnostic']) || isset($body['diagnostic']);
    
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/captiveportal.inc');
    global $config;
    
    $sessions = [];
    $zones_info = [];
    $debug_info = [];
    
    if (!isset($config['captiveportal']) || !is_array($config['captiveportal'])) {
        log_api('INFO', 'vouchers request - no captive portal zones configured');
        json_response([
            'success' => true,
            'count' => 0,
            'sessions' => [],
            'zones' => []
        ]);
        return;
    }
    
    foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
        $zone_name = strtolower($zone_cfg['zone'] ?? $zone_id);
        
        // Apply zone filter if specified
        if ($zone_filter !== null && $zone_name !== $zone_filter) {
            continue;
        }
        
        // Skip disabled zones
        if (isset($zone_cfg['disabled'])) {
            continue;
        }
        
        // Get zone timeouts
        $hard_timeout = (int)($zone_cfg['timeout'] ?? 0); // Session timeout in minutes
        $idle_timeout = (int)($zone_cfg['idletimeout'] ?? 0); // Idle timeout in minutes
        
        $zones_info[$zone_name] = [
            'name' => $zone_name,
            'hard_timeout_minutes' => $hard_timeout,
            'idle_timeout_minutes' => $idle_timeout,
            'enabled' => true
        ];
        
        // Diagnostic mode: collect info about database files and available functions
        if ($diagnostic) {
            $diag = [
                'zone_id' => $zone_id,
                'zone_name' => $zone_name,
                'db_files_checked' => [],
                'db_file_found' => null,
                'tables' => [],
                'columns' => [],
                'row_count' => 0,
                'functions_available' => []
            ];
            
            // Check which captive portal functions exist
            $funcs_to_check = [
                'captiveportal_read_db',
                'captiveportal_opendb', 
                'captiveportal_get_db',
                'captiveportal_connected_users'
            ];
            foreach ($funcs_to_check as $fn) {
                $diag['functions_available'][$fn] = function_exists($fn);
            }
            
            // Check database files - pfSense uses /var/db/captiveportal{ZONE}.db (NO underscore!)
            $db_paths = [
                "/var/db/captiveportal{$zone_name}.db",      // Correct format (no underscore)
                "/var/db/captiveportal_{$zone_name}.db",     // Legacy/wrong format
                "/var/db/captiveportal-{$zone_name}.db", 
                "/var/db/captiveportal.db",
                "/var/db/captiveportal{$zone_id}.db"
            ];
            
            foreach ($db_paths as $path) {
                $exists = file_exists($path);
                $diag['db_files_checked'][$path] = $exists;
                if ($exists && !$diag['db_file_found']) {
                    $diag['db_file_found'] = $path;
                    
                    // Get table info
                    try {
                        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
                        $tables_result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
                        while ($t = $tables_result->fetchArray(SQLITE3_ASSOC)) {
                            $diag['tables'][] = $t['name'];
                            
                            // Get columns for this table
                            $schema_result = $db->query("PRAGMA table_info({$t['name']})");
                            $cols = [];
                            while ($col = $schema_result->fetchArray(SQLITE3_ASSOC)) {
                                $cols[] = $col['name'];
                            }
                            $diag['columns'][$t['name']] = $cols;
                            
                            // Get row count
                            $count_result = $db->querySingle("SELECT COUNT(*) FROM {$t['name']}");
                            $diag['row_count'] = (int)$count_result;
                        }
                        $db->close();
                    } catch (Exception $e) {
                        $diag['db_error'] = $e->getMessage();
                    }
                }
            }
            
            // Also check for any captiveportal*.db files
            $glob_files = glob('/var/db/captiveportal*.db');
            $diag['all_cp_db_files'] = $glob_files ?: [];
            
            $debug_info[$zone_name] = $diag;
        }
        
        // Read captive portal database for this zone
        // pfSense uses /var/db/captiveportal{ZONE}.db format (NO underscore between captiveportal and zone name!)
        $db_file = "/var/db/captiveportal{$zone_name}.db";
        if (!file_exists($db_file)) {
            // Try with underscore as fallback (some older versions may have used this)
            $db_file = "/var/db/captiveportal_{$zone_name}.db";
        }
        if (!file_exists($db_file)) {
            $db_file = "/var/db/captiveportal.db"; // Fallback for single-zone setups
        }
        
        if (!file_exists($db_file)) {
            log_api('DEBUG', "Zone {$zone_name}: No database file found");
            continue;
        }
        
        log_api('DEBUG', "Zone {$zone_name}: Using database file {$db_file}");
        
        try {
            $db = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
            
            // Query active sessions
            // pfSense 2.7.x captiveportal.db actual schema (verified from pfSense source):
            // allow_time, pipeno, ip, mac, username, sessionid, bpassword, 
            // session_timeout, idle_timeout, session_terminate_time, interim_interval,
            // traffic_quota, bw_up, bw_down, authmethod, context
            // Note: bytes_in/bytes_out/last_activity don't exist - they were assumptions
            $query = "SELECT * FROM captiveportal ORDER BY allow_time DESC";
            $result = $db->query($query);
            
            if ($result) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $allow_time = (int)($row['allow_time'] ?? 0);
                    $session_timeout = (int)($row['session_timeout'] ?? ($hard_timeout * 60)); // Convert to seconds
                    $idle_timeout_sec = (int)($row['idle_timeout'] ?? ($idle_timeout * 60));
                    $last_activity = (int)($row['last_activity'] ?? $allow_time);
                    $now = time();
                    
                    // Calculate remaining time
                    $session_end = $allow_time + $session_timeout;
                    $remaining_seconds = max(0, $session_end - $now);
                    
                    // Check if expired
                    $is_expired = $remaining_seconds <= 0;
                    
                    // Format remaining time
                    $remaining_formatted = format_remaining_time($remaining_seconds);
                    
                    $sessions[] = [
                        'session_id' => $row['sessionid'] ?? '',
                        'ip' => $row['ip'] ?? '',
                        'mac' => strtolower($row['mac'] ?? ''),
                        'username' => $row['username'] ?? '',
                        'zone' => $zone_name,
                        'session_start' => gmdate('Y-m-d\TH:i:s\Z', $allow_time),
                        'last_activity' => gmdate('Y-m-d\TH:i:s\Z', $last_activity),
                        'session_timeout' => $session_timeout,
                        'idle_timeout' => $idle_timeout_sec,
                        'remaining_seconds' => $remaining_seconds,
                        'remaining_formatted' => $remaining_formatted,
                        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $session_end),
                        'is_expired' => $is_expired,
                        'bytes_in' => (int)($row['bytes_in'] ?? 0),
                        'bytes_out' => (int)($row['bytes_out'] ?? 0),
                        'traffic_quota' => (int)($row['traffic_quota'] ?? 0)
                    ];
                }
            }
            
            $db->close();
        } catch (Exception $e) {
            log_api('ERROR', "Failed to read captive portal DB for zone {$zone_name}: " . $e->getMessage());
            if ($diagnostic) {
                $debug_info[$zone_name]['query_error'] = $e->getMessage();
            }
        }
    }
    
    // Sort by remaining time (ascending - expiring soon first)
    usort($sessions, function($a, $b) {
        return $a['remaining_seconds'] - $b['remaining_seconds'];
    });
    
    log_api('INFO', "vouchers request, count=" . count($sessions));
    
    $response = [
        'success' => true,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => count($sessions),
        'sessions' => $sessions,
        'zones' => array_values($zones_info)
    ];
    
    // Include diagnostic info if requested
    if ($diagnostic) {
        $response['diagnostic'] = $debug_info;
    }
    
    json_response($response);
}

/**
 * Format remaining seconds to human-readable string
 */
function format_remaining_time(int $seconds): string {
    if ($seconds <= 0) {
        return 'Expired';
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    }
    return "{$minutes}m";
}

/**
 * POST /voucher_disconnect - Disconnect an active captive portal session
 * Body: { "session_id": "...", "mac": "...", "zone": "default" }
 */
function action_voucher_disconnect(): void {
    $body = get_json_body();
    
    $session_id = $body['session_id'] ?? '';
    $mac = isset($body['mac']) ? normalize_mac($body['mac']) : '';
    $zone = strtolower(trim($body['zone'] ?? 'default'));
    
    if (empty($session_id) && empty($mac)) {
        error_response('Missing required field: session_id or mac');
    }
    
    require_once('/etc/inc/config.inc');
    require_once('/etc/inc/captiveportal.inc');
    global $config;
    
    // Validate zone exists
    if (!isset($config['captiveportal'][$zone])) {
        // Try to find zone by zone name
        $zone_found = false;
        foreach ($config['captiveportal'] as $zid => $zcfg) {
            if (strtolower($zcfg['zone'] ?? $zid) === $zone) {
                $zone = $zid;
                $zone_found = true;
                break;
            }
        }
        if (!$zone_found) {
            error_response('Captive portal zone not found: ' . $zone, 404);
        }
    }
    
    // Try to disconnect the session
    $disconnected = false;
    $disconnect_info = [];
    
    // Read the database to find the session
    // pfSense uses /var/db/captiveportal{ZONE}.db (NO underscore in most versions)
    $db_file = "/var/db/captiveportal{$zone}.db";
    if (!file_exists($db_file)) {
        // Try with underscore as fallback (some older/custom builds)
        $db_file = "/var/db/captiveportal_{$zone}.db";
    }
    if (!file_exists($db_file)) {
        // Generic fallback
        $db_file = "/var/db/captiveportal.db";
    }
    
    if (!file_exists($db_file)) {
        error_response('Captive portal database not found for zone: ' . $zone, 500);
    }
    
    try {
        $db = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
        
        // Find the session
        if (!empty($session_id)) {
            $stmt = $db->prepare("SELECT * FROM captiveportal WHERE sessionid = :sid");
            $stmt->bindValue(':sid', $session_id, SQLITE3_TEXT);
        } else {
            $stmt = $db->prepare("SELECT * FROM captiveportal WHERE LOWER(mac) = :mac");
            $stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $session = $result->fetchArray(SQLITE3_ASSOC);
        $db->close();
        
        if (!$session) {
            error_response('Session not found', 404);
        }
        
        $disconnect_info = [
            'session_id' => $session['sessionid'],
            'ip' => $session['ip'],
            'mac' => $session['mac'],
            'username' => $session['username']
        ];
        
        // CRITICAL: Set $cpzone global variable before calling captiveportal functions
        // pfSense captiveportal functions depend on this global being set
        global $cpzone;
        $cpzone = $zone;

        // Use pfSense function to disconnect
        // captiveportal_disconnect_client($sessionid, $term_cause = 1, $logoutReason = "LOGOUT")
        // term_cause: 1=User-Request, 4=Idle-Timeout, 5=Session-Timeout, 6=Admin, 10=NAS-Request
        if (function_exists('captiveportal_disconnect_client')) {
            try {
                log_api('INFO', "Calling captiveportal_disconnect_client with cpzone={$cpzone}, sessionid={$session['sessionid']}");
                captiveportal_disconnect_client($session['sessionid'], 6, "DISCONNECT");
                $disconnected = true;
                log_api('INFO', "Disconnected session via pfSense function: {$session['sessionid']}");
            } catch (Exception $e) {
                log_api('WARNING', "pfSense disconnect function failed: " . $e->getMessage() . ", using fallback");
                // Fall through to fallback method
                $disconnected = false;
            }
        } else {
            log_api('WARNING', "captiveportal_disconnect_client function not available");
            $disconnected = false;
        }

        // Fallback: try to delete from database directly and remove firewall rules
        if (!$disconnected) {
            try {
                // Delete from SQLite database
                $db = new SQLite3($db_file);
                $stmt = $db->prepare("DELETE FROM captiveportal WHERE sessionid = :sid");
                $stmt->bindValue(':sid', $session['sessionid'], SQLITE3_TEXT);
                $stmt->execute();
                $db->close();
                log_api('INFO', "Deleted session from database: {$session['sessionid']}");

                // Remove firewall rules for this IP
                $client_ip = $session['ip'];
                if (!empty($client_ip)) {
                    // Kill any active states for this IP
                    $pfctl_kill = "/sbin/pfctl -k {$client_ip}";
                    exec($pfctl_kill, $output, $retval);
                    log_api('INFO', "Killed states for IP {$client_ip}: retval={$retval}");

                    // Remove from captive portal anchor
                    // pfSense uses anchors like "captiveportal/{zone}" for rules
                    $anchor = "captiveportal/{$zone}";

                    // Get current rules and filter out this IP
                    exec("/sbin/pfctl -a \"{$anchor}\" -s rules 2>/dev/null", $rules_output);
                    log_api('DEBUG', "Current anchor rules: " . implode("\n", $rules_output));
                }

                $disconnected = true;
                log_api('INFO', "Disconnected session via fallback method: {$session['sessionid']}");
            } catch (Exception $e) {
                log_api('ERROR', "Fallback disconnect method failed: " . $e->getMessage());
                throw $e; // Re-throw to be caught by outer try-catch
            }
        }

        // Double-check: verify session is actually removed from database
        try {
            $db = new SQLite3($db_file, SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM captiveportal WHERE sessionid = :sid");
            $stmt->bindValue(':sid', $session['sessionid'], SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $db->close();

            if ($row['cnt'] > 0) {
                log_api('WARNING', "Session still exists in database after disconnect attempt, forcing delete");
                // Force delete
                $db = new SQLite3($db_file);
                $stmt = $db->prepare("DELETE FROM captiveportal WHERE sessionid = :sid");
                $stmt->bindValue(':sid', $session['sessionid'], SQLITE3_TEXT);
                $stmt->execute();
                $db->close();
                log_api('INFO', "Force deleted session from database");
            }
        } catch (Exception $e) {
            log_api('WARNING', "Could not verify session removal: " . $e->getMessage());
        }

        // CRITICAL: Ensure firewall rules and states are removed
        // Even if captiveportal_disconnect_client() succeeded, we need to make sure
        // the firewall rules are actually removed
        $client_ip = $session['ip'];
        $client_mac = $session['mac'];

        if (!empty($client_ip)) {
            // 1. Delete client's anchor entry from auth anchor using pfSense function
            if (function_exists('captiveportal_ether_delete_entry')) {
                $host = ['ip' => $client_ip];
                if (!empty($client_mac)) {
                    $host['mac'] = $client_mac;
                }
                try {
                    captiveportal_ether_delete_entry($host, 'auth');
                    log_api('INFO', "Deleted firewall anchor entry for IP {$client_ip}, MAC {$client_mac}");
                } catch (Exception $e) {
                    log_api('WARNING', "captiveportal_ether_delete_entry failed: " . $e->getMessage());
                }
            }

            // 2. Kill all pf states for this IP (both directions)
            if (function_exists('pfSense_kill_states')) {
                @pfSense_kill_states($client_ip);
                log_api('INFO', "Killed pf states for IP {$client_ip} using pfSense_kill_states");
            }
            if (function_exists('pfSense_kill_srcstates')) {
                @pfSense_kill_srcstates($client_ip);
                log_api('INFO', "Killed source states for IP {$client_ip} using pfSense_kill_srcstates");
            }

            // 3. Fallback: Use pfctl command directly if functions don't exist
            if (!function_exists('pfSense_kill_states')) {
                exec("/sbin/pfctl -k {$client_ip} 2>&1", $output1, $retval1);
                exec("/sbin/pfctl -K {$client_ip} 2>&1", $output2, $retval2);
                log_api('INFO', "Killed states via pfctl for IP {$client_ip}: pfctl -k retval={$retval1}, pfctl -K retval={$retval2}");
            }
        }
        
    } catch (Exception $e) {
        log_api('ERROR', "Failed to disconnect session: " . $e->getMessage());
        error_response('Failed to disconnect session: ' . $e->getMessage(), 500);
    }
    
    if ($disconnected) {
        // Also remove from passthrumac config so the device can't auto-reconnect
        $mac_removed_from_config = false;
        $mac_to_remove = strtolower($disconnect_info['mac']);

        if (isset($config['captiveportal'][$zone]['passthrumac'])) {
            $passthrumacs = &$config['captiveportal'][$zone]['passthrumac'];

            // Handle single entry case
            if (isset($passthrumacs['mac'])) {
                if (strtolower($passthrumacs['mac']) === $mac_to_remove) {
                    unset($config['captiveportal'][$zone]['passthrumac']);
                    $mac_removed_from_config = true;
                }
            } else {
                // Multiple entries - find and remove
                foreach ($passthrumacs as $idx => $entry) {
                    if (isset($entry['mac']) && strtolower($entry['mac']) === $mac_to_remove) {
                        unset($passthrumacs[$idx]);
                        $mac_removed_from_config = true;
                        break;
                    }
                }
                // Re-index array after removal
                if ($mac_removed_from_config) {
                    $config['captiveportal'][$zone]['passthrumac'] = array_values($passthrumacs);
                    // If empty, remove the key entirely
                    if (empty($config['captiveportal'][$zone]['passthrumac'])) {
                        unset($config['captiveportal'][$zone]['passthrumac']);
                    }
                }
            }

            if ($mac_removed_from_config) {
                // First, delete the firewall rules for this MAC BEFORE writing config
                // This ensures the MAC is immediately blocked
                if (function_exists('captiveportal_passthrumac_delete_entry')) {
                    // Build the macent structure that pfSense expects
                    $macent = [
                        'mac' => $mac_to_remove,
                        'action' => 'pass'  // passthrumacadd uses 'pass' action
                    ];
                    try {
                        captiveportal_passthrumac_delete_entry($macent);
                        log_api('INFO', "Deleted firewall rules for MAC {$mac_to_remove} via captiveportal_passthrumac_delete_entry");
                    } catch (Exception $e) {
                        log_api('WARNING', "captiveportal_passthrumac_delete_entry failed: " . $e->getMessage());
                    }
                }

                // Write config changes
                write_config("macbind_api: removed MAC {$mac_to_remove} from passthrumac (disconnect)");
                log_api('INFO', "Removed MAC {$mac_to_remove} from passthrumac config");

                // Note: We don't call captiveportal_configure_zone() as it's too slow
                // and captiveportal_passthrumac_delete_entry() already handles the firewall rules
            }
        }

        // Also remove from macbind_active.json
        $active_db_file = '/var/db/macbind_active.json';
        $mac_removed_from_db = false;
        if (file_exists($active_db_file)) {
            $active_db = json_decode(file_get_contents($active_db_file), true);
            if ($active_db && isset($active_db['bindings'])) {
                $key = "{$zone}|{$mac_to_remove}";
                if (isset($active_db['bindings'][$key])) {
                    unset($active_db['bindings'][$key]);
                    $active_db['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    file_put_contents($active_db_file, json_encode($active_db, JSON_PRETTY_PRINT));
                    $mac_removed_from_db = true;
                    log_api('INFO', "Removed MAC {$mac_to_remove} from macbind_active.json");
                }
            }
        }

        log_api('INFO', "voucher_disconnect: zone={$zone}, session_id={$disconnect_info['session_id']}, mac={$disconnect_info['mac']}, config_removed={$mac_removed_from_config}, db_removed={$mac_removed_from_db}");
        json_response([
            'success' => true,
            'message' => 'Session disconnected',
            'disconnected' => $disconnect_info,
            'mac_removed_from_config' => $mac_removed_from_config,
            'mac_removed_from_db' => $mac_removed_from_db
        ]);
    } else {
        error_response('Failed to disconnect session', 500);
    }
}

/**
 * GET /sysinfo - Get detailed system information
 * Enhanced for pfSense 2.7.x with uptime, NTP, CPU, RAM, disk, and admin logins
 */
function action_sysinfo(): void {
    if (!file_exists('/etc/inc/config.inc')) {
        error_response('Not running on pfSense', 500);
    }
    
    require_once('/etc/inc/config.inc');
    global $config;
    
    // Get system info
    $hostname = $config['system']['hostname'] ?? gethostname();
    $domain = $config['system']['domain'] ?? 'local';
    $version = file_exists('/etc/version') ? trim(@file_get_contents('/etc/version')) : 'unknown';
    
    // Get enhanced metrics
    $uptime = get_system_uptime();
    $ntp_status = get_ntp_status();
    $cpu = get_cpu_usage();
    $memory = get_memory_usage();
    $disk = get_disk_usage();
    $admin_logins = get_admin_logins(5);
    
    // Get interfaces
    $interfaces = [];
    if (isset($config['interfaces']) && is_array($config['interfaces'])) {
        foreach ($config['interfaces'] as $ifname => $ifcfg) {
            $interfaces[] = [
                'name' => $ifname,
                'descr' => $ifcfg['descr'] ?? $ifname,
                'enabled' => !isset($ifcfg['disabled']),
                'ipaddr' => $ifcfg['ipaddr'] ?? ''
            ];
        }
    }
    
    // Get captive portal zones summary
    $cp_zones = [];
    if (isset($config['captiveportal']) && is_array($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
            $cp_zones[] = [
                'id' => $zone_id,
                'zone' => $zone_cfg['zone'] ?? $zone_id,
                'enabled' => !isset($zone_cfg['disabled'])
            ];
        }
    }
    
    $sysinfo = [
        'success' => true,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'system' => [
            'hostname' => $hostname,
            'domain' => $domain,
            'fqdn' => $hostname . '.' . $domain,
            'version' => $version
        ],
        'uptime' => $uptime,
        'ntp_status' => $ntp_status,
        'cpu' => $cpu,
        'memory' => $memory,
        'disk' => $disk,
        'last_admin_logins' => $admin_logins,
        'interfaces' => $interfaces,
        'captive_portal_zones' => $cp_zones
    ];
    
    log_api('INFO', 'sysinfo request');
    json_response($sysinfo);
}

// ============================================================================
// SELF-HEALING TESTS
// ============================================================================

/**
 * POST /selftest - Run self-healing tests on pfSense side
 * Tests system integrity and auto-fixes common issues
 */
function action_selftest(): void {
    $results = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'tests' => [],
        'auto_fixed' => []
    ];

    // Test 1: Queue file integrity
    $results['tests'][] = test_queue_file();

    // Test 2: Active DB integrity
    $results['tests'][] = test_active_db();

    // Test 3: Lock file cleanup
    $results['tests'][] = test_lock_files();

    // Test 4: Log rotation
    $results['tests'][] = test_log_rotation();

    // Test 5: pfSense config access
    $results['tests'][] = test_pfsense_config();

    // Test 6: Orphaned passthrumac entries
    $results['tests'][] = test_orphaned_macs();

    // Collect auto-fixed items
    foreach ($results['tests'] as $test) {
        if (!empty($test['fixed'])) {
            foreach ($test['fixed'] as $fix) {
                $results['auto_fixed'][] = ['test' => $test['name'], 'fix' => $fix];
            }
        }
    }

    $passed = array_filter($results['tests'], fn($t) => $t['passed']);
    $results['summary'] = [
        'total' => count($results['tests']),
        'passed' => count($passed),
        'failed' => count($results['tests']) - count($passed),
        'auto_fixed' => count($results['auto_fixed'])
    ];

    log_api('INFO', 'selftest completed: ' . json_encode($results['summary']));
    json_response(['success' => true, 'results' => $results]);
}

/**
 * Test queue file integrity
 */
function test_queue_file(): array {
    $result = ['name' => 'Queue File', 'passed' => true, 'issues' => [], 'fixed' => []];

    $queue_file = QUEUE_FILE;

    // Check existence
    if (!file_exists($queue_file)) {
        @touch($queue_file);
        if (file_exists($queue_file)) {
            $result['fixed'][] = 'Created missing queue file';
        } else {
            $result['issues'][] = 'Cannot create queue file';
            $result['passed'] = false;
            return $result;
        }
    }

    // Check permissions
    $perms = fileperms($queue_file) & 0777;
    if ($perms !== 0664 && $perms !== 0666) {
        @chmod($queue_file, 0664);
        $new_perms = fileperms($queue_file) & 0777;
        if ($new_perms === 0664) {
            $result['issues'][] = "Queue file permissions were " . decoct($perms);
            $result['fixed'][] = 'Fixed queue file permissions to 0664';
        }
    }

    // Check for corrupt lines
    $lines = @file($queue_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        $corrupt_count = 0;
        foreach ($lines as $line) {
            if (empty(trim($line)) || $line[0] === '#') continue;
            $parts = str_getcsv($line);
            if (count($parts) !== 6) {
                $corrupt_count++;
            }
        }
        if ($corrupt_count > 0) {
            $result['issues'][] = "Found {$corrupt_count} corrupt queue lines";
        }
    }

    $result['passed'] = empty($result['issues']) || count($result['issues']) === count($result['fixed']);
    return $result;
}

/**
 * Test active DB integrity
 */
function test_active_db(): array {
    $result = ['name' => 'Active DB', 'passed' => true, 'issues' => [], 'fixed' => []];

    $db_file = ACTIVE_DB_FILE;

    if (!file_exists($db_file)) {
        $default = ['version' => 1, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'bindings' => []];
        @file_put_contents($db_file, json_encode($default, JSON_PRETTY_PRINT));
        if (file_exists($db_file)) {
            $result['fixed'][] = 'Created missing active DB file';
        } else {
            $result['issues'][] = 'Cannot create active DB file';
            $result['passed'] = false;
            return $result;
        }
    } else {
        // Validate JSON structure
        $content = @file_get_contents($db_file);
        $data = @json_decode($content, true);

        if ($data === null) {
            $result['issues'][] = 'Active DB contains invalid JSON';
            // Backup corrupt file and recreate
            @rename($db_file, $db_file . '.corrupt.' . time());
            $default = ['version' => 1, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'bindings' => []];
            @file_put_contents($db_file, json_encode($default, JSON_PRETTY_PRINT));
            $result['fixed'][] = 'Backed up corrupt DB and created new one';
        } elseif (!isset($data['bindings']) || !is_array($data['bindings'])) {
            $result['issues'][] = 'Active DB missing bindings array';
            $data['bindings'] = [];
            @file_put_contents($db_file, json_encode($data, JSON_PRETTY_PRINT));
            $result['fixed'][] = 'Added missing bindings array';
        }
    }

    // Check permissions
    if (file_exists($db_file)) {
        $perms = fileperms($db_file) & 0777;
        if ($perms !== 0600 && $perms !== 0644) {
            @chmod($db_file, 0600);
            $result['issues'][] = "Active DB permissions were " . decoct($perms);
            $result['fixed'][] = 'Fixed active DB permissions';
        }
    }

    $result['passed'] = empty($result['issues']) || count($result['issues']) === count($result['fixed']);
    return $result;
}

/**
 * Test lock files for stale locks
 */
function test_lock_files(): array {
    $result = ['name' => 'Lock Files', 'passed' => true, 'issues' => [], 'fixed' => []];

    $lock_file = LOCK_FILE;

    if (file_exists($lock_file)) {
        $mtime = filemtime($lock_file);
        $age = time() - $mtime;

        if ($age > 300) { // 5 minutes
            $result['issues'][] = "Lock file is stale ({$age} seconds old)";

            // Check if PID in lock file is still running
            $pid = trim(@file_get_contents($lock_file));
            $pid_running = false;
            if ($pid && function_exists('posix_kill')) {
                $pid_running = @posix_kill((int)$pid, 0);
            }

            if (!$pid_running) {
                @unlink($lock_file);
                $result['fixed'][] = 'Removed stale lock file (process not running)';
            } else {
                $result['issues'][] = 'Lock file held by running process, not removing';
            }
        }
    }

    $result['passed'] = empty($result['issues']) || count($result['issues']) === count($result['fixed']);
    return $result;
}

/**
 * Test log rotation
 */
function test_log_rotation(): array {
    $result = ['name' => 'Log Rotation', 'passed' => true, 'issues' => [], 'fixed' => []];

    $log_files = [LOG_FILE, '/var/log/macbind_sync.log'];
    $max_size = 5 * 1024 * 1024; // 5MB

    foreach ($log_files as $log_file) {
        if (file_exists($log_file)) {
            $size = filesize($log_file);

            if ($size > $max_size * 1.5) { // 50% over limit
                $result['issues'][] = basename($log_file) . " is oversized (" . round($size/1024/1024, 2) . "MB)";

                // Force rotation
                $backup = $log_file . '.' . gmdate('Ymd_His');
                if (@rename($log_file, $backup)) {
                    @touch($log_file);
                    $result['fixed'][] = 'Rotated ' . basename($log_file);
                }
            }
        }
    }

    $result['passed'] = empty($result['issues']) || count($result['issues']) === count($result['fixed']);
    return $result;
}

/**
 * Test pfSense config access
 */
function test_pfsense_config(): array {
    $result = ['name' => 'pfSense Config', 'passed' => true, 'issues' => [], 'fixed' => []];

    // Test config access
    if (!file_exists('/etc/inc/config.inc')) {
        $result['issues'][] = 'pfSense config.inc not found';
        $result['passed'] = false;
        return $result;
    }

    require_once('/etc/inc/config.inc');
    global $config;

    if (!isset($config['captiveportal'])) {
        $result['issues'][] = 'No captive portal configured';
    }

    $result['passed'] = empty($result['issues']);
    return $result;
}

/**
 * Test for orphaned MACs in pfSense config
 */
function test_orphaned_macs(): array {
    $result = ['name' => 'Orphaned MACs', 'passed' => true, 'issues' => [], 'fixed' => []];

    // Load active DB
    if (!file_exists(ACTIVE_DB_FILE)) {
        return $result;
    }

    $db = @json_decode(@file_get_contents(ACTIVE_DB_FILE), true);
    if (!$db || !isset($db['bindings'])) {
        return $result;
    }

    // Build active MACs set
    $active_macs = [];
    foreach ($db['bindings'] as $binding) {
        $mac = strtolower($binding['mac'] ?? '');
        $zone = strtolower($binding['zone'] ?? '');
        if ($mac && $zone) {
            $active_macs[$zone . '|' . $mac] = true;
        }
    }

    // Load pfSense config
    require_once('/etc/inc/config.inc');
    global $config;

    $orphaned = 0;

    if (isset($config['captiveportal'])) {
        foreach ($config['captiveportal'] as $zone_id => $zone_cfg) {
            if (!isset($zone_cfg['passthrumac'])) continue;
            $zone = strtolower($zone_cfg['zone'] ?? $zone_id);

            foreach ($zone_cfg['passthrumac'] as $entry) {
                $descr = $entry['descr'] ?? '';

                // Only check AUTO_BIND entries (our managed entries)
                if (strpos($descr, TAG_PREFIX) !== 0) continue;

                $mac = strtolower(normalize_mac($entry['mac'] ?? ''));
                $key = $zone . '|' . $mac;

                if (!isset($active_macs[$key])) {
                    $orphaned++;
                    $result['issues'][] = "Orphaned MAC in pfSense: {$mac} (zone: {$zone})";
                }
            }
        }
    }

    if ($orphaned > 0) {
        $result['issues'][] = "Found {$orphaned} orphaned AUTO_BIND entries in pfSense config";
        // Don't auto-fix - require manual review
    }

    $result['passed'] = $orphaned === 0;
    return $result;
}

// ============================================================================
// MAIN REQUEST HANDLER
// ============================================================================

// Set security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Only accept GET and POST
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'OPTIONS'])) {
    error_response('Method not allowed', 405);
}

// Handle CORS preflight
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Load configuration
$config = load_config();

// Validate API key
validate_api_key($config['api_key']);

// Validate IP if configured
validate_ip($config['allowed_ips']);

// Check rate limit
if ($config['rate_limit_enabled']) {
    check_rate_limit();
}

// Get action
$action = $_GET['action'] ?? '';

// Route to appropriate handler
switch ($action) {
    case 'status':
        action_status();
        break;
    
    case 'bindings':
        action_bindings();
        break;
    
    case 'zones':
        action_zones();
        break;
    
    case 'add':
        if ($method !== 'POST') {
            error_response('POST method required for add action', 405);
        }
        action_add();
        break;
    
    case 'remove':
        if ($method !== 'POST') {
            error_response('POST method required for remove action', 405);
        }
        action_remove();
        break;
    
    case 'update':
        if ($method !== 'POST') {
            error_response('POST method required for update action', 405);
        }
        action_update();
        break;
    
    case 'sync':
        if ($method !== 'POST') {
            error_response('POST method required for sync action', 405);
        }
        action_sync();
        break;
    
    case 'cleanup_expired':
        if ($method !== 'POST') {
            error_response('POST method required for cleanup_expired action', 405);
        }
        action_cleanup_expired();
        break;
    
    case 'search':
        action_search();
        break;
    
    case 'backup':
        action_backup();
        break;
    
    case 'sysinfo':
        action_sysinfo();
        break;
    
    case 'vouchers':
        action_vouchers();
        break;
    
    case 'voucher_disconnect':
        if ($method !== 'POST') {
            error_response('POST method required for voucher_disconnect action', 405);
        }
        action_voucher_disconnect();
        break;

    case 'selftest':
        if ($method !== 'POST') {
            error_response('POST method required for selftest action', 405);
        }
        action_selftest();
        break;

    default:
        error_response('Unknown action. Valid actions: status, bindings, zones, add, remove, update, sync, search, backup, sysinfo, vouchers, voucher_disconnect, selftest', 400);
}
