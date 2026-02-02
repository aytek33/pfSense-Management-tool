<?php
/**
 * MAC Binding Captive Portal Hook
 * 
 * This file contains the hook snippet that must be inserted into your
 * pfSense Captive Portal login page to enable automatic MAC binding.
 * 
 * IMPORTANT: This code must ONLY run after successful voucher authentication.
 * DO NOT place this code where it could execute on failed auth attempts.
 * 
 * ============================================================================
 * PLACEMENT INSTRUCTIONS
 * ============================================================================
 * 
 * Option 1: Custom Captive Portal Page
 * -------------------------------------
 * If you're using a custom captive portal page (uploaded via pfSense GUI at
 * Services > Captive Portal > [Zone] > Portal Page Contents), insert this
 * snippet in your portal.php or index.php file AFTER the voucher validation
 * succeeds but BEFORE the redirect.
 * 
 * Look for code that checks voucher validity and insert after the success branch:
 * 
 *   if ($voucher_valid) {
 *       // ... existing success handling ...
 *       
 *       // INSERT MACBIND HOOK HERE (see snippet below)
 *       
 *       // ... redirect to success page ...
 *   }
 * 
 * Option 2: Patching pfSense Core (Advanced)
 * ------------------------------------------
 * For pfSense 2.7.2, the voucher authentication logic is in:
 *   /usr/local/captiveportal/index.php
 * 
 * Insert the hook after line 209, inside the voucher success block:
 * 
 *   if ($timecredit > 0) {
 *       // MACBIND HOOK INSERTION POINT
 *       // Variables available: $voucher, $timecredit, $clientip, $clientmac, $cpzone
 *       // ... hook code ...
 *       
 *       $attr = array(...);
 *       portal_allow(...);
 *   }
 * 
 * Option 3: Using a Post-Auth Hook (if available)
 * ------------------------------------------------
 * Some pfSense configurations support post-authentication scripts. If available,
 * adapt this snippet for that mechanism.
 * 
 * ============================================================================
 * REQUIRED VARIABLES
 * ============================================================================
 * 
 * For pfSense 2.7.2, the hook expects these variables from the auth context:
 * 
 *   $cpzone      - (string) Current captive portal zone name
 *   $voucher     - (string) The validated voucher code
 *   $timecredit  - (int) Voucher validity in minutes (from voucher_auth() return)
 *   $clientip    - (string) Client IP address
 *   $clientmac   - (string) Client MAC address
 * 
 * These variables are available in /usr/local/captiveportal/index.php after
 * successful voucher authentication (when $timecredit > 0).
 * 
 * ============================================================================
 * CONFIGURATION
 * ============================================================================
 */

// Default voucher duration in minutes (24 hours = 1440 minutes)
// This is used only as a fallback if the voucher system doesn't provide explicit duration
// Note: pfSense 2.7.2 provides $timecredit (in minutes) from voucher_auth() - use that instead
define('MACBIND_DEFAULT_DURATION_MINUTES', 1440);

// Queue file path (must match macbind_sync.php configuration)
define('MACBIND_QUEUE_FILE', '/var/db/macbind_queue.csv');

/**
 * ============================================================================
 * HOOK SNIPPET - COPY THIS SECTION INTO YOUR PORTAL PAGE
 * ============================================================================
 * 
 * Insert this code block after successful voucher authentication.
 * Adapt variable names ($cpzone, $voucher_code, etc.) to match your context.
 */

// ===== BEGIN MACBIND HOOK =====
// MAC Binding Queue Append - runs only on successful voucher auth
// This is a minimal, constant-time operation - no loops, no config reads
//
// For pfSense 2.7.2: Insert this hook in /usr/local/captiveportal/index.php
// after line 209, inside the block: if ($timecredit > 0) { ... }
//
// Required variables (available in pfSense 2.7.2 context):
//   $voucher    - Validated voucher code
//   $timecredit - Voucher duration in minutes (from voucher_auth() return)
//   $clientip   - Client IP address
//   $clientmac  - Client MAC address
//   $cpzone     - Captive portal zone name

// Check if voucher auth succeeded (pfSense 2.7.2: $timecredit > 0 means success)
if (isset($timecredit) && $timecredit > 0) {
    do {
        // Get client IP (use $clientip if available, otherwise fallback)
        $client_ip = $clientip ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (empty($client_ip)) {
            break;
        }
        
        // Get client MAC address
        // Method 1: Use $clientmac if available (pfSense 2.7.2 provides this)
        $client_mac = '';
        if (!empty($clientmac)) {
            $client_mac = $clientmac;
        } elseif (function_exists('pfSense_ip_to_mac')) {
            // Method 2: Use pfSense built-in function
            $mac_info = pfSense_ip_to_mac($client_ip);
            if (isset($mac_info['macaddr'])) {
                $client_mac = strtolower($mac_info['macaddr']);
            }
        }
        
        // Method 3: Fallback to ARP table lookup
        if (empty($client_mac)) {
            $arp_output = shell_exec("/usr/sbin/arp -an 2>/dev/null | grep '({$client_ip})'");
            if ($arp_output && preg_match('/([0-9a-f]{1,2}(?:[:-][0-9a-f]{1,2}){5})/i', $arp_output, $matches)) {
                $client_mac = strtolower($matches[1]);
            }
        }
        
        // Validate MAC format
        if (empty($client_mac) || !preg_match('/^([0-9a-f]{1,2}[:-]){5}[0-9a-f]{1,2}$/i', $client_mac)) {
            // Could not determine valid MAC, skip silently
            break;
        }
        
        // Normalize MAC to colon-separated lowercase
        $mac_hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $client_mac));
        if (strlen($mac_hex) !== 12) {
            break;
        }
        $client_mac = implode(':', str_split($mac_hex, 2));
        
        // Get zone name (pfSense 2.7.2: $cpzone is available)
        $zone_name = strtolower($cpzone ?? $_POST['zone'] ?? $_GET['zone'] ?? 'default');
        
        // Compute expiration timestamp
        // pfSense 2.7.2: $timecredit is already in minutes from voucher_auth()
        $duration_minutes = (int)$timecredit;
        
        // Use $timecredit if valid, otherwise fallback to default
        if ($duration_minutes <= 0) {
            $duration_minutes = MACBIND_DEFAULT_DURATION_MINUTES;
        }
        
        $expires_ts = time() + ($duration_minutes * 60);
        
        // Format timestamps
        $now_iso = gmdate('Y-m-d\TH:i:s\Z');
        $expires_iso = gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
        
        // Get voucher code (pfSense 2.7.2: $voucher is available)
        $voucher_code = $voucher ?? $_POST['auth_voucher'] ?? '';
        if (empty($voucher_code)) {
            break;
        }
        $voucher_hash = hash('sha256', $voucher_code);
        
        // Build CSV line: ts_iso,zone,mac,expires_at_iso,voucher_hash,src_ip
        $csv_fields = [
            $now_iso,
            $zone_name,
            $client_mac,
            $expires_iso,
            $voucher_hash,
            $client_ip
        ];
        
        // Escape CSV fields properly
        $csv_line = '';
        foreach ($csv_fields as $field) {
            if (strpos($field, ',') !== false || strpos($field, '"') !== false) {
                $field = '"' . str_replace('"', '""', $field) . '"';
            }
            $csv_line .= ($csv_line === '' ? '' : ',') . $field;
        }
        $csv_line .= "\n";
        
        // Append to queue file (atomic, append-only operation)
        $queue_file = MACBIND_QUEUE_FILE;
        
        // Check if writable (without expensive operations)
        if (is_writable($queue_file) || (!file_exists($queue_file) && is_writable(dirname($queue_file)))) {
            // Use LOCK_EX for atomic append
            $result = @file_put_contents($queue_file, $csv_line, FILE_APPEND | LOCK_EX);
            
            if ($result === false) {
                // Log failure once (throttled via static flag)
                static $macbind_logged_error = false;
                if (!$macbind_logged_error) {
                    error_log("macbind: Failed to write to queue file: {$queue_file}");
                    $macbind_logged_error = true;
                }
            }
        } else {
            // Queue file not writable - log once and continue
            static $macbind_logged_perm = false;
            if (!$macbind_logged_perm) {
                error_log("macbind: Queue file not writable: {$queue_file}");
                $macbind_logged_perm = true;
            }
        }
        
    } while (false); // Single-iteration loop for clean break on any condition
}
// ===== END MACBIND HOOK =====

/**
 * ============================================================================
 * ALTERNATIVE: STANDALONE FUNCTION VERSION
 * ============================================================================
 * 
 * If you prefer a function-based approach, use this version instead.
 * Call macbind_queue_append() after successful voucher auth.
 */

/**
 * Append MAC binding to queue after successful voucher authentication
 * 
 * @param string $zone          Captive portal zone name
 * @param string $voucher_code  The validated voucher code
 * @param int    $duration_min  Voucher duration in minutes (0 = use default)
 * @param string $expires_iso   Optional explicit expiry (ISO8601)
 * @return bool                 True if queued successfully
 */
function macbind_queue_append(string $zone, string $voucher_code, int $duration_min = 0, string $expires_iso = ''): bool {
    // Get client IP
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($client_ip)) {
        return false;
    }
    
    // Get client MAC
    $client_mac = '';
    
    // Try pfSense function
    if (function_exists('pfSense_ip_to_mac')) {
        $mac_info = pfSense_ip_to_mac($client_ip);
        if (isset($mac_info['macaddr'])) {
            $client_mac = $mac_info['macaddr'];
        }
    }
    
    // Fallback to ARP
    if (empty($client_mac)) {
        $arp = shell_exec("/usr/sbin/arp -an 2>/dev/null | grep '({$client_ip})'");
        if ($arp && preg_match('/([0-9a-f]{1,2}(?:[:-][0-9a-f]{1,2}){5})/i', $arp, $m)) {
            $client_mac = $m[1];
        }
    }
    
    if (empty($client_mac)) {
        return false;
    }
    
    // Normalize MAC
    $mac_hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $client_mac));
    if (strlen($mac_hex) !== 12) {
        return false;
    }
    $client_mac = implode(':', str_split($mac_hex, 2));
    
    // Compute expiry
    if (!empty($expires_iso)) {
        $expires_ts = strtotime($expires_iso);
    } else {
        // Use provided duration, or fallback to default if invalid
        if ($duration_min <= 0) {
            $duration_min = MACBIND_DEFAULT_DURATION_MINUTES;
        }
        $expires_ts = time() + ($duration_min * 60);
    }
    
    if ($expires_ts === false) {
        return false;
    }
    
    // Build CSV line
    $line = sprintf(
        "%s,%s,%s,%s,%s,%s\n",
        gmdate('Y-m-d\TH:i:s\Z'),
        strtolower($zone),
        $client_mac,
        gmdate('Y-m-d\TH:i:s\Z', $expires_ts),
        hash('sha256', $voucher_code),
        $client_ip
    );
    
    // Append to queue
    $queue_file = MACBIND_QUEUE_FILE;
    if (is_writable($queue_file) || (!file_exists($queue_file) && is_writable(dirname($queue_file)))) {
        return @file_put_contents($queue_file, $line, FILE_APPEND | LOCK_EX) !== false;
    }
    
    return false;
}

/**
 * ============================================================================
 * USAGE EXAMPLE
 * ============================================================================
 * 
 * For pfSense 2.7.2, in /usr/local/captiveportal/index.php:
 * 
 *   // After voucher_auth() succeeds (around line 209):
 *   if ($timecredit > 0) {
 *       // Option 1: Use inline hook (copy the MACBIND HOOK section above)
 *       // Variables $voucher, $timecredit, $clientip, $clientmac, $cpzone are available
 *       // ... hook code runs ...
 *       
 *       // Option 2: Use function
 *       macbind_queue_append($cpzone, $voucher, $timecredit);
 *       
 *       $attr = array(...);
 *       portal_allow(...);
 *   }
 * 
 */
