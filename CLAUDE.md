# CLAUDE.md - pfSense Management Tool

Complete technical documentation based on **live system debugging** (2026-02-02).

---

## EXECUTIVE SUMMARY

### What This Tool IS
A **captive portal MAC binding management system** for multi-firewall pfSense deployments:
- Centralized MAC binding management across multiple firewalls
- Configuration backup to Google Drive with scheduling
- Voucher session tracking and history
- System monitoring (CPU, RAM, disk, uptime)
- Admin login audit trail

### What This Tool is NOT
- **NOT a firewall rules manager** - Cannot edit pfSense firewall rules
- **NOT an IDS/alerting system** - Suricata data exists but no integration
- **NOT a policy engine** - Only basic pass/block per MAC
- **NOT a settings UI** - Configuration is in Google Sheets only
- **NOT real-time** - Requires manual refresh or 5-minute triggers

---

## COMMON MISTAKES TO AVOID

### DO NOT assume these features exist:

| Wrong Assumption | Reality |
|------------------|---------|
| "Total Devices" widget | **Does NOT exist** - Use "Total Bindings" instead |
| "Clean" status widget | **Does NOT exist** - No health/security classification |
| "Warning" status widget | **Does NOT exist** - No warning category |
| "ALERTS" tab | **Does NOT exist** - Suricata data on pfSense, no API |
| "Policies" tab | **Does NOT exist** - Only pass/block per binding |
| "Settings" tab | **Does NOT exist** - Edit Google Sheets Config tab |
| Real-time dashboard | **Does NOT exist** - Manual refresh or 5-min triggers |

### DO NOT confuse these terms:

| Term Used | Actual Meaning |
|-----------|----------------|
| "Devices" | MAC bindings (not network devices) |
| "Sessions" | Voucher sessions in Vouchers tab |
| "Alerts" | Activity logs (not security alerts) |
| "Policies" | Pass/block action on binding (not firewall rules) |

### DO NOT try to implement:

| Feature | Why Not |
|---------|---------|
| Direct pfSense rule editing | Use pfSense WebGUI instead |
| Suricata alert viewing | Need to add `?action=alerts` API first |
| User role management | Not in scope - use Google account permissions |
| Real-time updates | GAS has 6-minute execution limit |

### BEFORE making changes, verify:

1. **Read the Feature Matrix** - Check if feature exists
2. **Check API endpoints** - Only use implemented endpoints
3. **Test on pfSense** - Use curl commands in Debugging section
4. **Check pfSense version** - Use compatibility matrix

---

## FEATURE MATRIX

### Implemented Features

| Feature | Status | Location | Notes |
|---------|--------|----------|-------|
| Firewalls Tab | ✅ Full | `index.html` | Add/edit/remove/test firewalls |
| Bindings Tab | ✅ Full | `index.html` | MAC binding CRUD with search/filter/sort |
| Backups Tab | ✅ Full | `index.html` | Config backup with scheduling |
| Logs Tab | ✅ Full | `index.html` | Activity logs (operations, not security) |
| Vouchers Tab | ✅ Full | `index.html` | Session tracking and disconnect |
| System Info | ✅ Full | `sysinfo` API | CPU, RAM, disk, NTP, uptime, admin logins |
| Multi-firewall Sync | ✅ Full | `SyncService.gs` | Periodic sync every 5 minutes |
| Self-healing Tests | ⚠️ Partial | `Code.gs` | GAS-side only; PHP-side not deployed |
| MAC Action | ⚠️ Partial | Bindings | Only pass/block toggle, not policies |

### NOT Implemented (Don't Expect These)

| Feature | Status | Notes |
|---------|--------|-------|
| Suricata/IDS Alerts | ❌ Missing | Data exists on pfSense, no API endpoint |
| Policies Tab | ❌ Missing | Only basic pass/block action exists |
| Settings UI | ❌ Missing | Config only in Google Sheets |
| Firewall Rules | ❌ Missing | Cannot edit pfSense rules |
| Bandwidth Control | ❌ Missing | No QoS or rate limiting |
| Content Filtering | ❌ Missing | No URL blocking |
| User Management | ❌ Missing | No role-based access |
| Device Health Scoring | ❌ Missing | No "clean/warning" classification |

---

## DASHBOARD WIDGETS (ACTUAL)

### Main Stats Bar (4 Cards)
```
┌─────────────────┬─────────────────┬─────────────────┬─────────────────┐
│ Total Firewalls │ Online          │ Total Bindings  │ Expiring (24h)  │
│ statTotalFW     │ statOnlineFW    │ statTotalBind   │ statExpiring    │
└─────────────────┴─────────────────┴─────────────────┴─────────────────┘
```

**NOT**: "Total Devices", "Clean", "Warning" - These widgets do NOT exist.

### Vouchers Tab Stats (4 Cards)
```
┌─────────────────┬─────────────────┬─────────────────┬─────────────────┐
│ Total Active    │ Healthy (>3d)   │ Expiring        │ Critical (<10m) │
└─────────────────┴─────────────────┴─────────────────┴─────────────────┘
```

### Backup Tab Stats (4 Cards)
```
┌─────────────────┬─────────────────┬─────────────────┬─────────────────┐
│ Total Backups   │ Backup Storage  │ Last Backup     │ Backed Up FWs   │
└─────────────────┴─────────────────┴─────────────────┴─────────────────┘
```

---

## LIVE SYSTEM STATUS (192.168.150.201)

Verified on 2026-02-02:

| Component | Status | Notes |
|-----------|--------|-------|
| pfSense Version | 2.8.1-RELEASE | FreeBSD 15.0-CURRENT |
| Web UI Port | 33445 | Non-standard (https://192.168.150.201:33445) |
| macbind_api.php | Working | On port 33445 |
| API Key | `24d0b7d2...` | In /usr/local/etc/macbind_api.conf |
| Captive Portal Zones | 1 (office) | On opt1, opt2 interfaces |
| Active Voucher Sessions | 1 | MAC a0:36:9f:a5:51:c2, expires 2026-03-01 |
| Bindings | 0 | Sync was broken (now fixed) |
| Suricata | Running (7.0.11) | With SecureEDGE rules - NO API |
| NTP | NOT synced | Should be fixed |

---

## pfSense COMPATIBILITY REFERENCE

### Verified Compatible Versions
- **pfSense CE 2.7.2** - Primary development target, fully tested
- **pfSense CE 2.8.0** - Compatible with minor considerations
- **pfSense CE 2.8.1** - Compatible (maintenance release, no CP changes)

### pfSense 2.8.0 Changes Affecting This Project

| Change | Impact | Action Required |
|--------|--------|-----------------|
| PHP 8.2.x → 8.3.x upgrade | Low | No deprecated PHP 8.2 features used |
| FreeBSD 14 → FreeBSD 15-CURRENT | Low | No direct FreeBSD API calls |
| Captive Portal: MAC mask blocking | None | New feature, doesn't affect code |
| Captive Portal: Auto-added MAC pruning fix | **Positive** | Fixes issue #15299 |
| Captive Portal: Zone ID conflict fix | **Positive** | Fixes issue #15772 |
| State Policy Default changed | None | Doesn't affect captive portal |

### pfSense Functions Used (SAFE across 2.7.2 - 2.8.1)

```php
// From captiveportal.inc - SAFE TO USE
captiveportal_configure_zone($cpcfg)     // Reload zone configuration
captiveportal_configure()                 // Reload all zones (legacy fallback)
captiveportal_passthrumac_delete_entry($macent)  // Remove MAC + firewall rules
write_config($description)               // Save config.xml

// From pfSense core - SAFE TO USE
pfSense_ip_to_mac($clientip)             // Get MAC from IP via ARP
pfSense_pf_cp_flush($anchor, $type)      // Flush PF rules for anchor

// Config access - SAFE TO USE
config_get_path("captiveportal/{$zone}") // New-style config access (2.7+)
$config['captiveportal'][$zone]          // Legacy style (still works)
```

### Version Fallback Pattern Used

```php
// All pfSense calls are wrapped with existence checks
if (function_exists('captiveportal_configure_zone')) {
    captiveportal_configure_zone($zone);
} elseif (function_exists('captiveportal_configure')) {
    captiveportal_configure();  // Fallback for older pfSense
}
```

### pfSense Variables in Captive Portal Context

```php
$cpzone      // (string) Zone name (lowercase)
$cpzoneid    // (int) Zone ID number
$clientip    // (string) Client IP address
$clientmac   // (string) Client MAC address
$voucher     // (string) Voucher code entered
$timecredit  // (int) Minutes remaining from voucher_auth()
```

### pfSense File Paths (Stable Across Versions)

| Path | Purpose | Access |
|------|---------|--------|
| `/var/db/captiveportal{zone}.db` | SQLite session DB | Read-only safe |
| `/var/db/voucher_{zone}_active_*.db` | Active voucher tracking | Read-only safe |
| `/conf/config.xml` | Main config | Use write_config() only |
| `/etc/inc/captiveportal.inc` | CP functions | require_once() |
| `/etc/inc/voucher.inc` | Voucher functions | require_once() |
| `/etc/inc/config.inc` | Config functions | require_once() |

### CRITICAL: Do NOT Use These Patterns

```php
// DEPRECATED in pfSense 2.7+ (IPFW removed)
// - Any IPFW pipe references
// - captiveportal_disconnect_client() with old parameters

// UNSAFE - May cause DB corruption
// - Direct SQLite writes to captiveportal{zone}.db
// - Opening captive portal DB with SQLITE3_OPEN_READWRITE

// UNSTABLE - Session-based MAC removal
// - Relying on captiveportal session DB for MAC expiry
// - Our system uses expires_at field instead (more reliable)
```

---

## GOOGLE APPS SCRIPT COMPATIBILITY REFERENCE

### GAS Services Used (All Stable)

| Service | Usage | Status |
|---------|-------|--------|
| `UrlFetchApp.fetch()` | HTTP to pfSense API | ✅ Stable |
| `SpreadsheetApp.*` | Database CRUD | ✅ Stable |
| `DriveApp.*` | Backup storage | ✅ Stable |
| `PropertiesService.*` | Script config | ✅ Stable |
| `Utilities.*` | Helpers (sleep, format) | ✅ Stable |
| `ScriptApp.*` | Triggers | ✅ Stable |
| `LockService.*` | Distributed locks | ✅ Stable |
| `ContentService.*` | JSON responses | ✅ Stable |

### UrlFetchApp Configuration for pfSense

```javascript
const options = {
  method: 'POST',
  contentType: 'application/json',
  payload: JSON.stringify({ api_key: key, ...data }),
  validateHttpsCertificates: false,  // Self-signed pfSense certs
  muteHttpExceptions: true,          // Handle errors gracefully
  timeout: 30000                     // 30 second timeout
};
```

### GAS Limits

| Limit | Value | Notes |
|-------|-------|-------|
| Request timeout | 60 seconds | Hard limit, not configurable |
| Response size | 50 MB | Larger responses truncated |
| Daily URL fetch calls | 20,000 | Consumer accounts |
| Daily URL fetch calls | 100,000 | Workspace accounts |
| Script execution time | 6 minutes | Max single execution |

### GAS Deprecations (NOT affecting this project)

| Service | Deprecation Date | Used Here? |
|---------|------------------|------------|
| Contacts Service | Jan 31, 2025 | No |
| Analytics UA | Already deprecated | No |
| Script versions > 200 | Jun 1, 2024 | No |

---

## VERSION COMPATIBILITY MATRIX

| Component | Minimum | Recommended | Maximum Tested |
|-----------|---------|-------------|----------------|
| pfSense CE | 2.6.0 | 2.7.2+ | 2.8.1 |
| PHP (on pfSense) | 7.4 | 8.1+ | 8.3 |
| Google Apps Script | V8 Runtime | V8 Runtime | Current |
| FreeBSD | 13.x | 14.x | 15-CURRENT |

---

## BUGS FOUND & FIXED

### BUG #1: macbind_sync Cron Not Installed (FIXED)

**Severity:** CRITICAL
**Impact:** Queue had 51 stale items from Jan 22 that never processed

**Root Cause:** No cron entry for sync script
```bash
# Was missing from /etc/crontab
*/5 * * * * root /usr/local/sbin/macbind_sync.sh >> /var/log/macbind_sync.log 2>&1
```

**Fix Applied:** Added cron entry on 2026-02-02

### BUG #2: Stale Queue Data (FIXED)

**Impact:** 51 expired entries in /var/db/macbind_queue.csv from Jan 22

**Fix Applied:** Backed up old queue, created fresh queue file

### BUG #3: Voucher "Expiring" Filter Mismatch (FIXED)

**Severity:** MEDIUM
**File:** `gas/index.html:2676-2678`

**Problem:** Client filter showed 1-3 days OR 10-30min, missing vouchers between 30min-1day.

**Fix Applied:** Changed to continuous range `remainingSeconds >= 600 && remainingSeconds < 259200` (10 min to 3 days)

---

## BUGS FOUND (NOT YET FIXED)

### BUG #4: Distributed Lock Race Condition

**Severity:** HIGH
**File:** `gas/Config.gs:204-217`

PropertiesService doesn't guarantee atomic check-and-set. Two deployments can both acquire the "same" lock.

### BUG #5: JSON Parse Failures Not Detected

**Severity:** MEDIUM
**File:** `usr/local/www/macbind_api.php:536,737,1275`

`json_decode()` returns null on failure but code doesn't check explicitly.

### BUG #6: Timezone Issues in Expiry Comparisons

**Severity:** MEDIUM
**File:** `gas/SheetsDb.gs:459+`

Date comparisons may use local timezone instead of UTC.

### BUG #7: NTP Not Synced

**Severity:** LOW
**Location:** pfSense system

NTP shows `synced: false` - should be configured.

---

## MISSING FEATURES (Not Bugs)

### Suricata Alerts Integration

**Status:** Data exists, no API endpoint

Suricata is running with SecureEDGE rules and logging alerts to EVE JSON:
```
/var/log/suricata/suricata_igb0697b7e4441611/eve.json
```

Example alert:
```json
{
  "event_type": "alert",
  "alert": {
    "signature_id": 9000001,
    "signature": "SECUREEDGE Multiple TLS Connections",
    "category": "Potential Corporate Privacy Violation",
    "severity": 1
  }
}
```

**To implement:** Add `?action=alerts` endpoint to macbind_api.php that parses EVE JSON files.

---

## FILE STRUCTURE

```
pfSense-Management-tool/
├── gas/                              # Google Apps Script
│   ├── Code.gs                       # Main entry, doGet/doPost
│   ├── SheetsDb.gs                   # Google Sheets database
│   ├── PfSenseApi.gs                 # HTTP client to pfSense
│   ├── BindingService.gs             # MAC binding CRUD
│   ├── SyncService.gs                # Multi-firewall sync
│   ├── VoucherService.gs             # Captive portal sessions
│   ├── SystemInfoService.gs          # System metrics
│   ├── BackupService.gs              # Config backup
│   ├── Config.gs                     # Constants, distributed lock
│   ├── Triggers.gs                   # Scheduled automation
│   └── index.html                    # Dashboard UI (147KB)
│
├── usr/local/www/
│   └── macbind_api.php               # pfSense REST API (123KB)
│
└── usr/local/sbin/
    ├── macbind_sync.php              # Sync daemon
    ├── macbind_sync.sh               # Sync wrapper script
    ├── macbind_manage.php            # CLI admin tool
    └── macbind_import.php            # CSV import
```

---

## API REFERENCE

### pfSense API (macbind_api.php)

**Base URL:** `https://<pfsense-ip>:<port>/macbind_api.php`

**Authentication:** POST with JSON body containing `api_key`

```bash
curl -s -k -X POST \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"YOUR_API_KEY"}' \
  'https://192.168.150.201:33445/macbind_api.php?action=status'
```

### Implemented Endpoints

| Action | Method | Description |
|--------|--------|-------------|
| `status` | GET/POST | System status, binding counts |
| `bindings` | GET/POST | All active MAC bindings |
| `zones` | GET/POST | Captive portal zones |
| `vouchers` | GET/POST | Active voucher sessions |
| `sysinfo` | GET/POST | CPU, RAM, disk, uptime, NTP, admin logins |
| `search` | GET/POST | Search bindings by MAC, zone, IP |
| `add` | POST | Add MAC binding |
| `remove` | POST | Remove binding |
| `update` | POST | Update binding |
| `sync` | POST | Trigger sync |
| `cleanup_expired` | POST | Remove expired bindings |
| `backup` | POST | Create config backup |
| `voucher_disconnect` | POST | Disconnect session |

### NOT Implemented Endpoints

| Action | Status | Notes |
|--------|--------|-------|
| `selftest` | ❌ Not deployed | Code exists, needs deployment |
| `alerts` | ❌ Not implemented | Suricata integration needed |
| `policies` | ❌ Not implemented | Would require rule management |
| `rules` | ❌ Not implemented | Firewall rule editing |

---

## GOOGLE SHEETS SCHEMA

### Firewalls Sheet
| Column | Description |
|--------|-------------|
| ID | Unique identifier |
| Name | Display name |
| URL | https://ip:port |
| API_Key | Authentication key |
| Status | online/offline/stale |
| Last_Sync | Timestamp |

### Bindings Sheet
| Column | Description |
|--------|-------------|
| Firewall_ID | FK to Firewalls |
| Zone | Captive portal zone |
| MAC | aa:bb:cc:dd:ee:ff |
| Expires_At | ISO8601 |
| Status | active/expired |
| Action | pass/block |

### Config Sheet
| Key | Default | Description |
|-----|---------|-------------|
| sync_interval_minutes | 5 | Sync frequency |
| stale_threshold_minutes | 15 | Mark offline after N mins |
| default_duration_minutes | 43200 | Default binding duration (30 days) |
| max_log_entries | 10000 | Log retention count |
| backup_folder_id | (empty) | Google Drive folder ID |
| backup_keep_count | 10 | Backups to retain |
| voucher_session_retention_days | 90 | Voucher history retention |

---

## SCHEDULED TRIGGERS

| Trigger | Interval | Function |
|---------|----------|----------|
| scheduledSync | 5 min | Sync all firewalls |
| cleanupExpiredBindings | 15 min | Remove expired bindings |
| updateStaleStatuses | 15 min | Mark offline firewalls |
| runScheduledBackups | 1 hour | Execute backup schedules |
| scheduledSelfHealingTests | Daily 3 AM | System integrity tests |

**pfSense Cron:**
```
*/5 * * * * root /usr/local/sbin/macbind_sync.sh >> /var/log/macbind_sync.log 2>&1
```

---

## SELF-HEALING TESTS

### GAS Tests (runSelfHealingTests())

| Test | Auto-Fix |
|------|----------|
| Sheet Integrity | Creates missing sheets |
| Firewall Connectivity | Updates status to offline |
| Duplicate Bindings | Removes duplicates |
| Expired Binding Cleanup | Triggers cleanup |
| Orphaned Sessions | Marks as disconnected |
| Config Integrity | Sets missing defaults |
| Backup Folder Access | Clears invalid folder ID |

### PHP Tests (NOT deployed yet)

Would test:
- Queue file integrity
- Active DB JSON validity
- Lock file cleanup
- Log rotation
- pfSense config access
- Orphaned MACs

---

## DEBUGGING COMMANDS

### Test API from pfSense
```bash
curl -s -k -X POST \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"YOUR_KEY"}' \
  'https://localhost:33445/macbind_api.php?action=status'
```

### Check sync log
```bash
tail -f /var/log/macbind_sync.log
```

### Check API log
```bash
tail -f /var/log/macbind_api.log
```

### Check queue
```bash
cat /var/db/macbind_queue.csv
```

### Check active bindings DB
```bash
cat /var/db/macbind_active.json
```

### Run manual sync
```bash
/usr/local/sbin/macbind_sync.sh
```

### Check Suricata alerts (data exists, no API)
```bash
grep '"event_type":"alert"' /var/log/suricata/suricata_*/eve.json | tail -20
```

---

## DECISION GUIDELINES

### When to Use pfSense Native Functions vs Custom Code

| Task | Use Native | Use Custom |
|------|------------|------------|
| Add MAC to passthrumac | `write_config()` + reload | Never roll your own |
| Remove MAC + disconnect | `captiveportal_passthrumac_delete_entry()` | Fallback with `pfSense_pf_cp_flush()` |
| Read active sessions | SQLite read-only | Never write to session DB |
| MAC lookup from IP | `pfSense_ip_to_mac()` | ARP fallback only |

### When to Use GAS Services vs External APIs

| Task | Use GAS Service | Use External |
|------|-----------------|--------------|
| Store config data | Google Sheets | Never |
| Store backups | Google Drive | Never |
| HTTP to pfSense | UrlFetchApp | Never |
| User auth | Google OAuth | Never |

---

## QUICK FIXES CHECKLIST

- [x] Add macbind_sync cron job
- [x] Clear stale queue
- [x] Fix voucher filter mismatch (index.html:2676)
- [ ] Fix distributed lock race condition (Config.gs:204)
- [ ] Add JSON parse error handling (macbind_api.php)
- [ ] Fix timezone in expiry comparisons (SheetsDb.gs)
- [ ] Configure NTP on pfSense
- [ ] Add Suricata alerts endpoint (FEATURE)
- [ ] Deploy selftest endpoint (FEATURE)

---

*Last Updated: 2026-02-02 - Live debugging session*
*pfSense: 192.168.150.201:33445*
*Repository: https://github.com/aytek33/pfSense-Management-tool*
