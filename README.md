# pfSense MAC Binding Automation

Automatic MAC Binding for pfSense CE 2.7.2 Captive Portal with voucher authentication.

## Overview

This automation reduces Captive Portal CPU/RAM usage for 1000+ active users by automatically adding authenticated clients to the Pass-through MAC list, bypassing full portal authentication for subsequent connections. Bindings automatically expire when the voucher validity ends.

### Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                     Captive Portal (Web Process)                        │
│  ┌─────────────────┐    ┌────────────────┐    ┌──────────────────────┐ │
│  │ Voucher Auth    │───▶│ MAC Bind Hook  │───▶│ Append to Queue CSV  │ │
│  │ (existing)      │    │ (minimal code) │    │ (constant time)      │ │
│  └─────────────────┘    └────────────────┘    └──────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
                                                          │
                                                          ▼
                                            /var/db/macbind_queue.csv
                                                          │
┌─────────────────────────────────────────────────────────────────────────┐
│                    Root Cron Job (Every 1 minute)                       │
│  ┌─────────────────┐    ┌────────────────┐    ┌──────────────────────┐ │
│  │ Read Queue      │───▶│ Update Active  │───▶│ Sync to pfSense      │ │
│  │ (batch 2000)    │    │ DB + Expiry    │    │ Config + Reload      │ │
│  └─────────────────┘    └────────────────┘    └──────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────┘
                                │                          │
                                ▼                          ▼
                 /var/db/macbind_active.json    pfSense Pass-through MAC List
```

### Key Features

- **Low CPU Impact**: Portal page only appends one line (constant time, no loops)
- **Safe Config Writes**: All pfSense config changes done by root cron job
- **Automatic Expiry**: Bindings removed when voucher expires
- **Tagged Entries**: Only manages entries with `AUTO_BIND:` prefix
- **Emergency Disable**: Create `/var/db/macbind_disabled` to stop sync
- **Daily Backups**: Config backed up before first change each day
- **Dry-Run Mode**: Test changes without applying

## Files

| File | Purpose |
|------|---------|
| `/usr/local/sbin/macbind_sync.php` | Main sync script (root cron) |
| `/usr/local/sbin/macbind_sync.sh` | Shell wrapper with safe PATH |
| `/usr/local/sbin/macbind_install.sh` | Installer (idempotent) |
| `/usr/local/sbin/macbind_import.php` | Import existing sessions (first run) |
| `/usr/local/sbin/macbind_manage.php` | Management CLI (list/remove/stats) |
| `captive_portal_hook.php` | Hook snippet for portal page |

### Data Files

| File | Owner | Purpose |
|------|-------|---------|
| `/var/db/macbind_queue.csv` | root:www (0664) | Queue for new bindings |
| `/var/db/macbind_active.json` | root:wheel (0600) | Active bindings DB |
| `/var/log/macbind_sync.log` | root:wheel (0644) | Sync log (5MB rotation) |
| `/conf/macbind_backups/` | root:wheel (0755) | Daily config backups |

---

## Installation

### Prerequisites

- pfSense CE 2.7.2
- Captive Portal with voucher authentication enabled
- Root (admin) access to pfSense

### Step 1: Copy Files to pfSense

Transfer files to your pfSense system:

```bash
# From your local machine
scp usr/local/sbin/macbind_sync.php root@pfsense:/usr/local/sbin/
scp usr/local/sbin/macbind_sync.sh root@pfsense:/usr/local/sbin/
scp usr/local/sbin/macbind_install.sh root@pfsense:/usr/local/sbin/
scp usr/local/sbin/macbind_import.php root@pfsense:/usr/local/sbin/
scp usr/local/sbin/macbind_manage.php root@pfsense:/usr/local/sbin/
```

### Step 2: Run Installer

SSH to pfSense and run the installer as root:

```bash
ssh root@pfsense
chmod +x /usr/local/sbin/macbind_install.sh
/usr/local/sbin/macbind_install.sh
```

The installer will:
- Create required directories and files
- Set correct permissions
- Run self-test diagnostics

### Step 3: Import Existing Sessions (Important for First Install)

If you have users already connected to the captive portal, import them:

```bash
# Preview what will be imported (dry run)
/usr/local/sbin/macbind_import.php --dry-run

# Import all zones
/usr/local/sbin/macbind_import.php

# Import specific zone only
/usr/local/sbin/macbind_import.php --zone=myzone

# Run sync to process imported entries
/usr/local/sbin/macbind_sync.php
```

This ensures existing authenticated users get their MAC bindings without needing to re-authenticate.

### Step 4: Configure Cron Job

**Option A: pfSense Cron Package (Recommended)**

1. Install "Cron" package from System > Package Manager
2. Go to Services > Cron
3. Add new cron job:
   - Minute: `*`
   - Hour: `*`
   - Day of Month: `*`
   - Month: `*`
   - Day of Week: `*`
   - User: `root`
   - Command: `/usr/local/sbin/macbind_sync.sh`

**Option B: Manual Crontab**

Add to `/etc/crontab.local`:

```bash
echo '* * * * * root /usr/local/sbin/macbind_sync.sh' >> /etc/crontab.local
```

### Step 5: Install Portal Hook

Insert the MAC binding hook into your captive portal page.

**For Custom Portal Pages:**

1. Open your custom portal page (portal.php or index.php)
2. Find the voucher authentication success section
3. Insert the hook code from `captive_portal_hook.php`

Example integration:

```php
// After voucher validation succeeds
if ($voucher_valid) {
    // Set flag for hook
    $voucher_auth_success = true;
    $cpzone = 'your_zone_name';  // Your zone
    $voucher_code = $_POST['auth_voucher'];
    $voucher_duration = 43200;  // Minutes, from voucher roll config
    
    // ===== PASTE MACBIND HOOK HERE =====
    // (copy from captive_portal_hook.php)
    // ===== END MACBIND HOOK =====
    
    // Continue with redirect...
}
```

### Step 6: Disable Built-in Auto Entry (Important)

To avoid conflicts, disable pfSense's built-in "Pass-through MAC Auto Entry":

1. Go to Services > Captive Portal > [Your Zone]
2. Find "Pass-through MAC Auto Entry"
3. Set to **Disabled** or ensure it doesn't conflict

---

## Verification

### Test 1: Self-Test

```bash
/usr/local/sbin/macbind_sync.php --selftest
```

Expected output:
```
=== macbind_sync.php Self-Test ===

1. Running as root: YES
2. Lock file test: OK
3. Queue file: exists, readable
...
RESULT: All checks passed
```

### Test 2: Manual Voucher Login

1. Connect a device to the captive portal
2. Authenticate with a voucher
3. Check queue file:

```bash
cat /var/db/macbind_queue.csv
```

Should show a line like:
```
2026-01-19T10:30:00Z,myzone,aa:bb:cc:dd:ee:ff,2026-02-18T10:30:00Z,abc123...,192.168.1.100
```

### Test 3: Dry Run

```bash
/usr/local/sbin/macbind_sync.php --dry-run
```

Output shows what would be done without applying:
```
=== DRY RUN MODE (no changes applied) ===
processed_queue=1
active_count=1
added=1
removed=0
expired_removed=0
errors=0
```

### Test 4: Full Sync

```bash
/usr/local/sbin/macbind_sync.php
```

Then verify in pfSense GUI:
- Go to Services > Captive Portal > [Zone] > MACs
- Look for entry with description `AUTO_BIND:...`

### Test 5: Monitor Logs

```bash
tail -f /var/log/macbind_sync.log
```

---

## Install & Verify Checklist

| # | Step | Status |
|---|------|--------|
| 1 | Copy scripts to `/usr/local/sbin/` | ☐ |
| 2 | Run `macbind_install.sh` as root | ☐ |
| 3 | Verify self-test passes | ☐ |
| 4 | Import existing sessions with `macbind_import.php` | ☐ |
| 5 | Configure cron job (every 1 minute) | ☐ |
| 6 | Insert hook into portal page | ☐ |
| 7 | Disable built-in "Pass-through MAC Auto Entry" | ☐ |
| 8 | Test voucher login, check queue file | ☐ |
| 9 | Run dry-run, verify output | ☐ |
| 10 | Run full sync, check pfSense MAC list | ☐ |
| 11 | Verify bindings with `macbind_manage.php list` | ☐ |
| 12 | Monitor `/var/log/macbind_sync.log` | ☐ |

---

## Management CLI

The `macbind_manage.php` tool provides a full CLI interface for viewing and managing MAC bindings.

### List All Bindings

```bash
# Table format (default)
/usr/local/sbin/macbind_manage.php list

# Filter by zone
/usr/local/sbin/macbind_manage.php list --zone=myzone

# JSON format
/usr/local/sbin/macbind_manage.php list --format=json

# CSV format
/usr/local/sbin/macbind_manage.php list --format=csv
```

Example output:
```
ZONE            | MAC ADDRESS         | EXPIRES                | REMAINING    | SRC IP
------------------------------------------------------------------------------------------
myzone          | aa:bb:cc:dd:ee:ff   | 2026-02-18T10:30:00Z   | 29d 23h      | 192.168.1.100
myzone          | 11:22:33:44:55:66   | 2026-01-20T15:00:00Z   | 23h 30m      | 192.168.1.101
------------------------------------------------------------------------------------------
Total: 2 binding(s)
```

### Search for a MAC or IP

```bash
# Search by MAC address
/usr/local/sbin/macbind_manage.php search aa:bb:cc:dd:ee:ff

# Search by partial MAC
/usr/local/sbin/macbind_manage.php search aa:bb:cc

# Search by IP address
/usr/local/sbin/macbind_manage.php search 192.168.1.100
```

### Remove a MAC Binding

```bash
# Remove with confirmation prompt
/usr/local/sbin/macbind_manage.php remove aa:bb:cc:dd:ee:ff

# Remove from specific zone only
/usr/local/sbin/macbind_manage.php remove aa:bb:cc:dd:ee:ff --zone=myzone

# Remove without confirmation
/usr/local/sbin/macbind_manage.php remove aa:bb:cc:dd:ee:ff --force
```

### View Statistics

```bash
/usr/local/sbin/macbind_manage.php stats
```

Output:
```
=== MAC Binding Statistics ===

Total active bindings: 150
Expired (pending cleanup): 3
Expiring within 1 hour: 5
Expiring within 24 hours: 12

Bindings by zone:
  myzone: 120
  guestzone: 30

Queue file: 5 pending entries

Last database update: 2026-01-19T10:35:00Z
```

### Export Bindings to CSV

```bash
# Export to default location (/tmp/)
/usr/local/sbin/macbind_manage.php export

# Export to specific file
/usr/local/sbin/macbind_manage.php export --file=/root/bindings_backup.csv
```

### Purge Expired Entries

```bash
# Preview what would be purged
/usr/local/sbin/macbind_manage.php purge --dry-run

# Actually purge expired entries
/usr/local/sbin/macbind_manage.php purge
```

---

## Operations

### View Active Bindings (Alternative)

```bash
cat /var/db/macbind_active.json | python3 -m json.tool
```

Or count:
```bash
grep -c '"mac"' /var/db/macbind_active.json
```

### View Queue

```bash
wc -l /var/db/macbind_queue.csv  # Count pending
cat /var/db/macbind_queue.csv    # View all
```

### Emergency Disable

To immediately stop all sync operations:

```bash
touch /var/db/macbind_disabled
```

To re-enable:
```bash
rm /var/db/macbind_disabled
```

### Force Sync

```bash
/usr/local/sbin/macbind_sync.sh
```

### View Logs

```bash
# Recent entries
tail -100 /var/log/macbind_sync.log

# Follow live
tail -f /var/log/macbind_sync.log

# Errors only
grep -E '\[ERROR\]|\[WARN\]' /var/log/macbind_sync.log
```

### Check Managed MACs in pfSense

Via CLI:
```bash
grep -c 'AUTO_BIND' /conf/config.xml
```

Via GUI:
- Services > Captive Portal > [Zone] > MACs
- Look for entries with `AUTO_BIND:` in description

### Rollback (if needed)

Backups are stored in `/conf/macbind_backups/`:

```bash
ls -la /conf/macbind_backups/
```

To restore, manually edit `/conf/config.xml` or use pfSense restore.

---

## Troubleshooting

### Queue file not being written

**Symptom**: No entries in `/var/db/macbind_queue.csv` after voucher login

**Check**:
1. File permissions: `ls -la /var/db/macbind_queue.csv`
   - Should be: `-rw-rw-r-- root www`
2. Hook is inserted in correct location (after auth success)
3. Check PHP error log: `tail /var/log/php_errors.log`

**Fix**:
```bash
chown root:www /var/db/macbind_queue.csv
chmod 0664 /var/db/macbind_queue.csv
```

### Sync not running

**Symptom**: Queue growing but active DB not updating

**Check**:
1. Cron is running: `crontab -l` or check pfSense Cron package
2. Lock file: `ls -la /var/run/macbind_sync.lock`
3. Disable flag: `ls -la /var/db/macbind_disabled`

**Fix**:
- Remove stale lock: `rm /var/run/macbind_sync.lock`
- Remove disable flag: `rm /var/db/macbind_disabled`

### MACs not appearing in pfSense

**Symptom**: Active DB has entries but pfSense MAC list is empty

**Check**:
1. Zone name matches: Compare `macbind_active.json` zone with pfSense config
2. Run with logging: `/usr/local/sbin/macbind_sync.php 2>&1 | tee /tmp/sync.log`

**Fix**:
- Ensure zone names are lowercase in hook
- Check captive portal zone configuration in pfSense

### High CPU usage

**Symptom**: Sync script taking too long

**Check**:
- Queue size: `wc -l /var/db/macbind_queue.csv`
- Active bindings: `grep -c '"mac"' /var/db/macbind_active.json`

**Fix**:
- Queue is processed in batches of 2000; large queues will catch up
- If active bindings > 5000, consider cleanup or zone separation

---

## Configuration Reference

### macbind_sync.php Constants

| Constant | Default | Description |
|----------|---------|-------------|
| `QUEUE_FILE` | `/var/db/macbind_queue.csv` | Queue file path |
| `ACTIVE_DB_FILE` | `/var/db/macbind_active.json` | Active bindings DB |
| `LOG_FILE` | `/var/log/macbind_sync.log` | Log file |
| `BACKUP_DIR` | `/conf/macbind_backups` | Backup directory |
| `MAX_QUEUE_LINES` | `2000` | Max lines per sync run |
| `LOG_MAX_SIZE` | `5MB` | Log rotation threshold |
| `ZONE_DEFAULT_DURATION_MINUTES` | `43200` | Default 30 days |
| `TAG_PREFIX` | `AUTO_BIND:` | Managed entry identifier |

### Queue CSV Format

```
ts_iso,zone,mac,expires_at_iso,voucher_hash,src_ip
2026-01-19T10:30:00Z,myzone,aa:bb:cc:dd:ee:ff,2026-02-18T10:30:00Z,sha256hash,192.168.1.100
```

### Active DB JSON Format

```json
{
  "version": 1,
  "updated_at": "2026-01-19T10:35:00Z",
  "bindings": {
    "myzone|aa:bb:cc:dd:ee:ff": {
      "zone": "myzone",
      "mac": "aa:bb:cc:dd:ee:ff",
      "expires_at": "2026-02-18T10:30:00Z",
      "voucher_hash": "abc123...",
      "last_seen": "2026-01-19T10:30:00Z",
      "src_ip": "192.168.1.100"
    }
  }
}
```

---

## Remote Management (Google Apps Script)

For managing MAC bindings across **multiple pfSense firewalls** remotely, this project includes a Google Apps Script-based web dashboard.

### Features

- **Centralized Dashboard**: Web UI to manage 20+ pfSense firewalls
- **Real-time Sync**: Automatic synchronization every 5 minutes
- **Google Sheets Backend**: All data stored in Google Sheets for easy access
- **Remote Operations**: Add/remove MAC bindings from anywhere
- **Status Monitoring**: Track firewall status and binding counts
- **Configuration Backups**: Full pfSense config backups stored in Google Drive
- **Scheduled Backups**: Automatic daily backups with retention policy

### Quick Start

1. Install the API endpoint on each pfSense:
   ```bash
   scp usr/local/www/macbind_api.php root@pfsense:/usr/local/www/
   ```

2. Configure API key:
   ```bash
   openssl rand -hex 32  # Generate key
   cp usr/local/etc/macbind_api.conf.sample /usr/local/etc/macbind_api.conf
   # Edit and set api_key=YOUR_KEY
   ```

3. Deploy Google Apps Script - see [INSTALL_GAS.md](INSTALL_GAS.md)

### Files

| File | Purpose |
|------|---------|
| `usr/local/www/macbind_api.php` | REST API endpoint for pfSense |
| `usr/local/etc/macbind_api.conf.sample` | API configuration template |
| `gas/*.gs` | Google Apps Script backend files |
| `gas/index.html` | Web dashboard UI |
| `INSTALL_GAS.md` | Detailed installation guide |

---

## License

BSD-2-Clause

## Version

1.1.0 - Added Google Apps Script remote management for multi-firewall support
