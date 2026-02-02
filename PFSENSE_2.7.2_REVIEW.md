# pfSense 2.7.2 Captive Portal Integration Review

**Date:** 2026-01-20  
**pfSense Version:** 2.7.2 (RELENG_2_7_2)  
**Review Scope:** Captive Portal voucher authentication and MAC passthrough integration

---

## Executive Summary

This review compares your MAC binding integration against the official pfSense 2.7.2 source code. **Critical issues** were found in the hook placement and variable dependencies that will prevent the system from working correctly. Several **compatibility risks** and **bugs** were also identified.

---

## Critical Issues

### 1. **Hook Variable Dependencies Don't Exist in pfSense Core**

**Location:** `captive_portal_hook.php` lines 84-208

**Problem:**
Your hook relies on variables that are **not set by pfSense core**:
- `$voucher_auth_success` - **Does not exist** in pfSense core
- `$voucher_duration` - **Not available** in the auth context
- `$voucher_roll['minutes']` - **Not accessible** at hook execution time
- `$voucher_code` - May not be set depending on insertion point

**pfSense 2.7.2 Reality:**
In `src/usr/local/captiveportal/index.php` (lines 197-231), the voucher flow is:
```php
$timecredit = voucher_auth($voucher);  // Returns minutes or error code
if ($timecredit > 0) {
    $attr = array(
        'voucher' => 1,
        'session_timeout' => $timecredit*60,  // Converted to seconds
        'session_terminate_time' => 0
    );
    portal_allow($clientip, $clientmac, $voucher, null, $redirurl, $attr, ...);
}
```

**Fix Required:**
1. Insert hook **after** `voucher_auth()` succeeds but **before** `portal_allow()` redirects
2. Use `$timecredit * 60` for duration (already in seconds)
3. Use `$voucher` variable (the validated voucher code)
4. Set `$voucher_auth_success = true` yourself, or check `$timecredit > 0`

**Recommended Hook Placement:**
```php
// In index.php, after line 209 (voucher_auth success check)
if ($timecredit > 0) {
    // INSERT YOUR MACBIND HOOK HERE
    // Variables available: $voucher, $timecredit, $clientip, $clientmac, $cpzone
    $voucher_auth_success = true;
    $voucher_duration = $timecredit;  // Already in minutes
    $voucher_code = $voucher;
    // ... your hook code ...
    
    $attr = array(...);
    portal_allow(...);
}
```

---

### 2. **MAC Address Format Mismatch Risk**

**Location:** `captive_portal_hook.php` lines 95-121

**Problem:**
Your hook normalizes MAC to lowercase colon-separated format, but pfSense's `passthrumac` entries may use different formats depending on how they're added.

**pfSense 2.7.2 Behavior:**
- `pfSense_ip_to_mac()` returns `['macaddr' => 'XX:XX:XX:XX:XX:XX']` (uppercase, colon-separated)
- When adding to `passthrumac`, pfSense stores exactly as provided (line 2076: `$mac['mac'] = $clientmac`)
- Your `macbind_manage.php` uses `strtolower()` for comparison (line 420), which is good

**Risk:**
If MACs are stored in different cases, lookups may fail. Your normalization is correct, but ensure consistency.

**Recommendation:**
Your current normalization (lines 117-121) is correct. Ensure `macbind_sync.php` also normalizes before adding to pfSense config.

---

### 3. **Voucher Expiration Calculation May Be Wrong**

**Location:** `captive_portal_hook.php` lines 126-149

**Problem:**
Your hook tries multiple methods to get expiration, but the primary source (`$timecredit`) isn't available if hook is placed incorrectly.

**pfSense 2.7.2 Reality:**
- `voucher_auth()` returns **remaining minutes** (not total duration)
- For new vouchers: returns `$minutes_per_roll[$roll]` (line 296 in voucher.inc)
- For active vouchers: returns `$remaining` calculated from `(timestamp + minutes*60 - time())/60` (line 274)
- `portal_allow()` receives `'session_timeout' => $timecredit*60` (seconds)

**Fix:**
If hook is placed correctly (after `voucher_auth()`), use:
```php
$duration_minutes = $timecredit;  // Already the correct value
$expires_ts = time() + ($duration_minutes * 60);
```

Your fallback to `MACBIND_DEFAULT_DURATION_MINUTES` (43200 = 30 days) is **too long** and may create bindings that outlive the actual voucher session.

---

## Compatibility Issues

### 4. **Passthrough MAC Description Format**

**Location:** `usr/local/sbin/macbind_manage.php` line 419

**Problem:**
Your code checks for `TAG_PREFIX` ("AUTO_BIND:") in the description, but pfSense 2.7.2 uses:
- `"Auto-added for voucher {$username}"` (line 2083 in captiveportal.inc)

**Impact:**
Your `macbind_manage.php remove` command won't identify auto-added voucher MACs correctly.

**Fix:**
Update the check to match pfSense's format:
```php
// Current (WRONG):
if (isset($entry['descr']) && strpos($entry['descr'], TAG_PREFIX) === 0)

// Should be:
if (isset($entry['descr']) && 
    (strpos($entry['descr'], 'Auto-added for voucher') === 0 ||
     strpos($entry['descr'], TAG_PREFIX) === 0))  // Keep for backward compat
```

**Alternative:**
Use `logintype === 'voucher'` to identify voucher-based passthrumac entries (more reliable).

---

### 5. **Zone Name Case Sensitivity**

**Location:** Multiple files

**Problem:**
pfSense stores zone names in `$config['captiveportal'][$cpzone]` where `$cpzone` is **lowercase** (see `index.php` line 40: `$cpzone = strtolower($_REQUEST['zone'])`).

Your code uses `strtolower()` in most places, which is correct, but ensure consistency everywhere.

**Verification:**
- ✅ `captive_portal_hook.php` line 124: `strtolower($cpzone ?? ...)`
- ✅ `macbind_manage.php` line 408: `strtolower($zone_name) !== strtolower($zone)`

---

## Bugs Found (From Previous Review)

### 6. **reconcileVoucherSessions() Never Reconciles**

**Location:** `gas/VoucherService.gs` lines 1080-1082

**Status:** Still present - needs fix

The wrapper function passes `null` to `reconcileSessions()`, which causes it to return immediately without expiring orphaned sessions.

---

### 7. **MAC Normalization Mismatch**

**Location:** `gas/VoucherService.gs` lines 500 vs 796

**Status:** Still present - needs fix

Sessions are stored with raw MAC from API, but `markSessionEndedByMac()` normalizes before lookup, causing failures.

---

## Positive Findings

### ✅ Correct Use of pfSense Functions

Your hook correctly uses:
- `pfSense_ip_to_mac()` - Official pfSense function (line 95)
- ARP fallback - Good defensive programming (line 104)
- Proper MAC validation regex (line 111)

### ✅ Proper Config Structure

Your `macbind_manage.php` correctly:
- Uses `config_get_path()` pattern (though direct array access is also valid)
- Checks for array existence before iteration
- Uses `write_config()` for changes

### ✅ Security Practices

- Voucher hash storage (SHA256) instead of plaintext ✅
- CSV escaping for queue file ✅
- File locking (FILE_APPEND | LOCK_EX) ✅

---

## Recommendations

### Immediate Actions Required

1. **Fix Hook Placement:**
   - Modify `index.php` directly (or provide patch instructions)
   - Insert hook after line 209 in `index.php`
   - Use available variables: `$voucher`, `$timecredit`, `$clientip`, `$clientmac`, `$cpzone`

2. **Fix Description Matching:**
   - Update `macbind_manage.php` to check for `"Auto-added for voucher"` prefix
   - Or better: check `logintype === 'voucher'`

3. **Fix Voucher Duration:**
   - Remove fallback to 30-day default
   - Use `$timecredit` directly (already in minutes)

### Testing Checklist

- [ ] Hook executes after successful voucher auth
- [ ] MAC binding created with correct expiration (matches voucher time)
- [ ] MAC appears in `passthrumac` with `logintype = 'voucher'`
- [ ] `macbind_manage.php remove` can find and remove auto-added MACs
- [ ] Expired vouchers trigger MAC removal via sync

### Long-term Improvements

1. **Use pfSense's Native Hooks (if available):**
   - Check if pfSense 2.7.2 has post-auth hooks/events
   - Prefer official extension points over file patching

2. **Session ID Tracking:**
   - Consider storing pfSense session ID in your queue
   - Enables direct session termination if needed

3. **Error Handling:**
   - Add retry logic for queue file writes
   - Log to pfSense syslog for better integration

---

## pfSense 2.7.2 Key Behaviors (Reference)

### Voucher Authentication Flow
```
index.php (line 197)
  → voucher_auth($voucher) returns minutes
  → If > 0: portal_allow() with attributes['voucher'] = 1
  → portal_allow() sets authmethod = "voucher", context = "voucher"
  → If passthrumacadd enabled: adds MAC with logintype = "voucher"
```

### Passthrough MAC Structure
```php
$mac = [
    'action' => 'pass',
    'mac' => 'xx:xx:xx:xx:xx:xx',
    'descr' => 'Auto-added for voucher {username}',
    'logintype' => 'voucher',  // Key identifier!
    'ip' => $clientip,
    'username' => $voucher_code
];
```

### Session Timeout Handling
- Vouchers: `session_timeout` in seconds (from `$timecredit * 60`)
- Stored in SQLite DB: `captiveportal` table, column `session_timeout`
- Pruning: `captiveportal_prune_old()` checks `$cpentry[0] + $cpentry[7] >= time()`

---

## Conclusion

Your integration approach is sound, but **critical fixes are required** for the hook to work with pfSense 2.7.2. The main issues are:

1. **Hook placement and variable availability** (CRITICAL)
2. **Description format mismatch** (HIGH)
3. **Voucher duration calculation** (MEDIUM)

Once these are fixed, the integration should work correctly with pfSense 2.7.2's voucher and passthrumac systems.

---

**Reviewer Notes:**
- pfSense 2.7.2 uses `pf` (packet filter) backend, not `ipfw` (migration completed in 2.7.0)
- Voucher system uses SQLite databases per roll: `voucher_{zone}_{roll}.db`
- Active sessions stored in: `/var/db/captiveportal_{zone}.db` (SQLite)
