# Quick Start Guide

This guide provides step-by-step instructions to quickly set up the pfSense MAC Binding system.

## Prerequisites

- pfSense CE 2.7.2 or later
- Captive Portal with voucher system configured
- macOS or Linux computer (for deployment)

## Step 1: Enable SSH on pfSense (For Each Firewall)

1. Log in to pfSense web interface
2. Go to **System > Advanced > Admin Access**
3. In **Secure Shell** section:
   - Check "Enable Secure Shell"
   - SSH Port: `22` (or custom port)
4. Click **Save**

## Step 2: Create Firewall List

Edit `firewalls.txt` and add your pfSense IP addresses:

```
# One firewall per line
192.168.1.1 22 Main-Office
10.0.0.1 22 Branch-1
172.16.0.1 2222 Branch-2
```

## Step 3: Automatic Deployment

### For Single Firewall:

```bash
cd /Users/aytek/Documents/33APPS/pfSense-captive-poral-MAC-Bind
./deploy_to_pfsense.sh 192.168.1.1
```

### For All Firewalls:

```bash
cd /Users/aytek/Documents/33APPS/pfSense-captive-poral-MAC-Bind
./deploy_all.sh
```

The script will:
- Copy all files
- Run install script
- Generate and save API key
- Run self-test
- Test API endpoint

## Step 4: Add Cron Job (Each Firewall - GUI)

1. **System > Package Manager > Available Packages**
2. Install "Cron" package
3. **Services > Cron > Add**
4. Settings:
   - Minute: `*`
   - Hour: `*`
   - Day of Month: `*`
   - Month: `*`
   - Day of Week: `*`
   - User: `root`
   - Command: `/usr/local/sbin/macbind_sync.sh`
5. Save

## Step 5: Add Captive Portal Hook (Each Firewall - GUI)

1. **Services > Captive Portal > [Zone Name]**
2. Add to your portal page after voucher validation succeeds:

```php
// After voucher auth succeeds
$voucher_auth_success = true;
// === Paste content from captive_portal_hook.php here ===
```

See `captive_portal_hook.php` for detailed code.

## Step 6: Disable Pass-through MAC Auto Entry (Each Firewall)

1. **Services > Captive Portal > [Zone Name]**
2. Find "Pass-through MAC Auto Entry"
3. Set to **Disabled**
4. Save

---

## Google Apps Script Setup (Optional - Central Management)

For managing multiple pfSense firewalls:

### 1. Create Google Sheets

1. Go to [sheets.google.com](https://sheets.google.com)
2. Create new spreadsheet: "pfSense MAC Binding Manager"
3. Copy ID from URL: `https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/edit`

### 2. Create Apps Script Project

1. **Extensions > Apps Script**
2. Copy files from `gas/` folder:
   - Code.gs
   - Config.gs
   - PfSenseApi.gs
   - SheetsDb.gs
   - SyncService.gs
   - BindingService.gs
   - BackupService.gs
   - Triggers.gs
   - index.html
   - appsscript.json (Project Settings > Show manifest)

### 3. Configure

1. **Project Settings > Script Properties**
2. Add: `SPREADSHEET_ID` = Your Spreadsheet ID

### 4. Deploy

1. Run `initializeApp` function
2. Approve permissions
3. **Deploy > New deployment > Web app**
4. Execute as: **Me**, Who has access: **Anyone**
5. Copy Web URL

### 5. Set Up Triggers

Run `setupTriggers` function

### 6. Add Firewalls

Open Dashboard and for each firewall:
- ID: `pf-main`
- Name: `Main Office`
- URL: `https://192.168.1.1`
- API Key: Key from deploy script output

---

## Verification

### pfSense Side

```bash
# Self-test
/usr/local/sbin/macbind_sync.php --selftest

# Manual sync (dry-run)
/usr/local/sbin/macbind_sync.php --dry-run

# List bindings
/usr/local/sbin/macbind_manage.php list

# Statistics
/usr/local/sbin/macbind_manage.php stats

# Watch logs
tail -f /var/log/macbind_sync.log
```

### Test Scenario

1. Connect a device to Captive Portal
2. Authenticate with voucher
3. Check queue file:
   ```bash
   cat /var/db/macbind_queue.csv
   ```
4. Run sync:
   ```bash
   /usr/local/sbin/macbind_sync.sh
   ```
5. Verify in pfSense GUI:
   - Services > Captive Portal > [Zone] > MACs
   - Entry with `AUTO_BIND:` prefix should appear

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| SSH connection error | Is SSH enabled on pfSense? Is port correct? |
| Queue file not written | Check `ls -la /var/db/macbind_queue.csv` - permissions correct? |
| Sync not running | Is cron job active? Check `crontab -l` |
| API error | Check `/var/log/macbind_api.log` |
| MAC not appearing in list | Is zone name correct? Test with dry-run |

## File Locations

| File | Description |
|------|-------------|
| `/var/db/macbind_queue.csv` | New binding queue |
| `/var/db/macbind_active.json` | Active bindings database |
| `/var/log/macbind_sync.log` | Sync log file |
| `/usr/local/etc/macbind_api.conf` | API configuration |
| `/conf/macbind_backups/` | Config backups |
