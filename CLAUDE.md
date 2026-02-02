# CLAUDE.md - pfSense Management Tool

Complete technical documentation based on **live system debugging** (2026-02-02).

---

## EXECUTIVE SUMMARY

### What This Tool Does
- Multi-firewall pfSense management from Google Sheets + GAS
- MAC binding management for captive portal
- Configuration backup to Google Drive
- Voucher session tracking
- System monitoring (CPU, RAM, disk, uptime)

### What This Tool Does NOT Do
- **Suricata/IDS alerts** - NOT implemented (data exists on pfSense, no API endpoint)
- **Policies** - Only pass/block per MAC binding
- **Settings UI** - Config is in Google Sheets only
- **Real-time dashboard** - Requires manual refresh or triggers

---

## LIVE SYSTEM STATUS (192.168.150.201)

Verified on 2026-02-02:

| Component | Status | Notes |
|-----------|--------|-------|
| pfSense Version | 2.8.1-RELEASE | FreeBSD 15.0-CURRENT |
| Web UI Port | 33445 | Non-standard (https://192.168.150.201:33445) |
| macbind_api.php | Working | On port 33445 |
| API Key | `24d0b7d2b2792840f9d62db317473447a0c9ad3e5ba236d343f4ba7e5dfad65c` | In /usr/local/etc/macbind_api.conf |
| Captive Portal Zones | 1 (office) | On opt1, opt2 interfaces |
| Active Voucher Sessions | 1 | MAC a0:36:9f:a5:51:c2, expires 2026-03-01 |
| Bindings | 0 | Sync was broken (now fixed) |
| Suricata | Running (7.0.11) | With SecureEDGE rules |
| NTP | NOT synced | Should be fixed |

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

---

## BUGS FOUND (NOT YET FIXED)

### BUG #3: Voucher "Expiring" Filter Mismatch

**Severity:** MEDIUM
**File:** `gas/index.html:2676-2678` vs `gas/VoucherService.gs:340-348`

Server counts 10min to 3days as "Expiring", client filter only shows 1-3 days OR 10-30min (missing 30min-1day).

**Fix Required:**
```javascript
// In index.html line 2676-2678, change to:
if (voucherCategoryFilter === 'expiring') {
  return remainingSeconds >= 600 && remainingSeconds < 259200; // 10 min to 3 days
}
```

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

**Base URL:** `https://192.168.150.201:33445/macbind_api.php`

**Authentication:** POST with JSON body containing `api_key`

```bash
curl -s -k -X POST \
  -H 'Content-Type: application/json' \
  -d '{"api_key":"24d0b7d2b2792840f9d62db317473447a0c9ad3e5ba236d343f4ba7e5dfad65c"}' \
  'https://192.168.150.201:33445/macbind_api.php?action=status'
```

| Action | Method | Description |
|--------|--------|-------------|
| `status` | GET/POST | System status, binding counts |
| `bindings` | GET/POST | All active MAC bindings |
| `zones` | GET/POST | Captive portal zones |
| `vouchers` | GET/POST | Active voucher sessions |
| `sysinfo` | GET/POST | CPU, RAM, disk, uptime, NTP |
| `add` | POST | Add MAC binding |
| `remove` | POST | Remove binding |
| `update` | POST | Update binding |
| `sync` | POST | Trigger sync |
| `cleanup_expired` | POST | Remove expired bindings |
| `backup` | POST | Create config backup |
| `voucher_disconnect` | POST | Disconnect session |

**NOT implemented:** `selftest`, `alerts`

---

## DASHBOARD STRUCTURE

### Main Stats (4 Cards)
```
Total Firewalls | Online | Total Bindings | Expiring (24h)
```

### Tabs
1. **Firewalls** - List with status, version, uptime, CPU/RAM/Disk
2. **Bindings** - MAC binding table with search/filter
3. **Backups** - Backup history + scheduled backups
4. **Logs** - Activity logs (NOT security alerts)
5. **Vouchers** - Captive portal sessions

### Vouchers Tab Stats (4 Cards)
```
Total Active | Healthy (>3 days) | Expiring | Critical (<10m)
```

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

### Check Suricata alerts
```bash
grep '"event_type":"alert"' /var/log/suricata/suricata_*/eve.json | tail -20
```

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

### Config Sheet
| Key | Default |
|-----|---------|
| sync_interval_minutes | 5 |
| stale_threshold_minutes | 15 |
| default_duration_minutes | 43200 |
| backup_folder_id | (empty) |
| backup_keep_count | 10 |

---

## QUICK FIXES CHECKLIST

- [x] Add macbind_sync cron job
- [x] Clear stale queue
- [ ] Fix voucher filter mismatch (index.html:2676)
- [ ] Fix distributed lock race condition (Config.gs:204)
- [ ] Add JSON parse error handling (macbind_api.php)
- [ ] Fix timezone in expiry comparisons (SheetsDb.gs)
- [ ] Configure NTP on pfSense
- [ ] Add Suricata alerts endpoint (FEATURE)
- [ ] Deploy selftest endpoint (FEATURE)

---

*Last Updated: 2026-02-02 - Live debugging session*
*pfSense: 192.168.150.201:33445*
