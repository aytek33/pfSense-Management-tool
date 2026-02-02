# Google Apps Script Installation Guide

This guide explains how to set up the pfSense MAC Binding Manager on Google Apps Script for remote management of multiple pfSense firewalls.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         Google Cloud                                     │
│  ┌──────────────┐   ┌──────────────────┐   ┌────────────────────────┐  │
│  │  Web App UI  │──▶│ Google Apps Script│──▶│    Google Sheets DB    │  │
│  │  (Dashboard) │   │   (Backend)       │   │ (Firewalls, Bindings)  │  │
│  └──────────────┘   └────────┬─────────┘   └────────────────────────┘  │
│                              │                                          │
└──────────────────────────────│──────────────────────────────────────────┘
                               │ HTTPS + API Key
                               ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                     pfSense Firewalls (20+)                             │
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────┐                │
│  │  pfSense 1   │   │  pfSense 2   │   │  pfSense N   │   ...          │
│  │ macbind_api  │   │ macbind_api  │   │ macbind_api  │                │
│  └──────────────┘   └──────────────┘   └──────────────┘                │
└─────────────────────────────────────────────────────────────────────────┘
```

## Prerequisites

- Google account with access to Google Drive and Google Sheets
- pfSense CE 2.7.2 or later on all firewalls
- HTTPS access to pfSense web interface from Google's servers
- Basic familiarity with Google Apps Script

## Part 1: pfSense Setup (Repeat for Each Firewall)

### Step 1: Install the MAC Binding System

First, install the base MAC binding system on each pfSense:

```bash
# SSH to pfSense
ssh root@your-pfsense-ip

# Copy all files to pfSense
scp usr/local/sbin/macbind_*.php root@pfsense:/usr/local/sbin/
scp usr/local/sbin/macbind_*.sh root@pfsense:/usr/local/sbin/
scp usr/local/www/macbind_api.php root@pfsense:/usr/local/www/
scp usr/local/etc/macbind_api.conf.sample root@pfsense:/usr/local/etc/

# Set permissions
chmod +x /usr/local/sbin/macbind_*.sh
chmod +x /usr/local/sbin/macbind_*.php
```

### Step 2: Configure API Endpoint

1. Generate a unique API key:

```bash
openssl rand -hex 32
```

2. Create the configuration file:

```bash
cp /usr/local/etc/macbind_api.conf.sample /usr/local/etc/macbind_api.conf
nano /usr/local/etc/macbind_api.conf
```

3. Set your API key:

```
api_key=YOUR_64_CHARACTER_HEX_KEY_HERE
allowed_ips=
rate_limit_enabled=true
```

### Step 3: Test API Endpoint

Test the API is working:

```bash
# From pfSense itself
curl -k -H "X-API-Key: YOUR_API_KEY" "https://127.0.0.1/macbind_api.php?action=status"
```

Expected response:
```json
{
  "success": true,
  "timestamp": "2026-01-19T10:30:00Z",
  "hostname": "pfSense.local",
  "version": "1.0.0",
  "sync_enabled": true,
  "bindings": {"total": 0, "by_zone": {}},
  "queue": {"pending": 0}
}
```

### Step 4: Ensure HTTPS Access

Google Apps Script requires HTTPS. Ensure:

1. Your pfSense uses HTTPS (System > Advanced > Admin Access)
2. The firewall allows inbound HTTPS from Google's IP ranges (or anywhere if using IP allowlist in API config)

**Note**: Self-signed certificates are supported by the GAS client.

---

## Part 2: Google Apps Script Setup

### Step 1: Create Google Spreadsheet

1. Go to [Google Sheets](https://sheets.google.com)
2. Create a new spreadsheet
3. Name it "pfSense MAC Binding Manager"
4. Note the spreadsheet ID from the URL:
   - URL: `https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/edit`
   - Copy the `SPREADSHEET_ID` part

### Step 2: Create Apps Script Project

1. In your spreadsheet, go to **Extensions > Apps Script**
2. This opens the Apps Script editor
3. Delete any existing code in `Code.gs`

### Step 3: Create Project Files

Create the following files in the Apps Script editor (use **+** button next to Files):

| File Name | Source File |
|-----------|-------------|
| Code.gs | `gas/Code.gs` |
| Config.gs | `gas/Config.gs` |
| PfSenseApi.gs | `gas/PfSenseApi.gs` |
| SheetsDb.gs | `gas/SheetsDb.gs` |
| SyncService.gs | `gas/SyncService.gs` |
| BindingService.gs | `gas/BindingService.gs` |
| Triggers.gs | `gas/Triggers.gs` |
| index.html | `gas/index.html` |
| appsscript.json | `gas/appsscript.json` |

**To view appsscript.json**: Click the gear icon (Project Settings) and check "Show 'appsscript.json' manifest file in editor"

### Step 4: Configure Spreadsheet ID

Option A: Set in Script Properties
1. Go to **Project Settings** (gear icon)
2. Scroll to **Script Properties**
3. Click **Add script property**
4. Property: `SPREADSHEET_ID`, Value: Your spreadsheet ID

Option B: Set in Code
1. Open `Config.gs`
2. Find `SPREADSHEET_ID: ''`
3. Add your spreadsheet ID between the quotes

### Step 5: Initialize the Application

1. In Apps Script editor, select `initializeApp` from the function dropdown
2. Click **Run**
3. Grant required permissions when prompted
4. Check the execution log for "Application initialized successfully"

This creates the required sheets:
- **Firewalls** - Your pfSense instances
- **Bindings** - MAC bindings across all firewalls
- **Logs** - Activity log
- **Config** - Settings

### Step 6: Deploy as Web App

1. Click **Deploy > New deployment**
2. Click the gear icon next to "Select type" and choose **Web app**
3. Configure:
   - Description: "pfSense MAC Binding Manager v1.0"
   - Execute as: **Me**
   - Who has access: **Anyone** (or restrict as needed)
4. Click **Deploy**
5. Copy the **Web app URL** - this is your dashboard

### Step 7: Set Up Triggers

1. In Apps Script editor, select `setupTriggers` from the function dropdown
2. Click **Run**
3. This creates:
   - Sync trigger (every 5 minutes)
   - Cleanup trigger (daily at 3 AM)
   - Stale check trigger (every 15 minutes)

---

## Part 3: Add Your Firewalls

### Via Web Dashboard

1. Open your Web App URL
2. Click **+ Add Firewall**
3. Fill in:
   - **ID**: Unique identifier (e.g., `office-main`)
   - **Name**: Display name (e.g., "Office Main Firewall")
   - **URL**: pfSense URL (e.g., `https://192.168.1.1`)
   - **API Key**: The 64-character key you generated
4. Click **Add Firewall**
5. Click **Test** to verify connection

### Via Google Sheets

1. Open your spreadsheet
2. Go to the **Firewalls** sheet
3. Add a row with:
   - ID, Name, URL, API_Key, Zones (comma-separated), Last_Sync, Status, Active_Bindings, Notes

---

## Part 4: Usage

### Dashboard Features

- **Firewalls Tab**: View all firewalls, status, sync, test connection
- **Bindings Tab**: View/search/add/remove MAC bindings
- **Logs Tab**: View activity history

### Sync Operations

- **Manual Sync**: Click "Sync" on a firewall or "Sync All" in header
- **Automatic Sync**: Runs every 5 minutes via trigger

### Add MAC Binding

1. Go to Bindings tab
2. Click **+ Add Binding**
3. Select firewall, enter zone, MAC, duration
4. Click **Add Binding**

The binding is pushed to the pfSense firewall and recorded in Sheets.

---

## Troubleshooting

### "Connection failed" when testing

1. **Check URL**: Ensure it's `https://` and accessible from internet
2. **Check API Key**: Must match exactly what's in `/usr/local/etc/macbind_api.conf`
3. **Check Firewall Rules**: pfSense must allow inbound HTTPS
4. **Check Logs**: View Logs tab or Google Apps Script execution logs

### Sync not working

1. **Check Triggers**: Run `listTriggers()` function to see active triggers
2. **Check Quotas**: Google Apps Script has daily limits (20,000 URL fetches)
3. **Check Execution Logs**: View > Execution log in Apps Script editor

### Bindings not appearing on pfSense

1. **Run manual sync**: SSH to pfSense and run `/usr/local/sbin/macbind_sync.php`
2. **Check queue file**: `cat /var/db/macbind_queue.csv`
3. **Check active DB**: `cat /var/db/macbind_active.json`

### Self-signed certificate errors

The GAS client is configured to accept self-signed certificates (`validateHttpsCertificates: false`). If you still have issues, check that pfSense is actually serving over HTTPS.

---

## Security Considerations

1. **API Keys**: Use unique 64-character keys for each firewall
2. **HTTPS Only**: Never use HTTP for API communication
3. **IP Allowlist**: Optionally restrict API access to Google's IP ranges
4. **Web App Access**: Consider restricting to "Anyone with Google account"
5. **Spreadsheet Sharing**: Only share with trusted users

### Google IP Ranges (Optional)

If you want to restrict API access to Google's servers only, you can find their IP ranges at:
https://www.gstatic.com/ipranges/goog.json

---

## Maintenance

### Update Sync Interval

```javascript
// In Apps Script, run:
createCustomSyncTrigger(10);  // Set to 10 minutes
```

### Pause/Resume Sync

```javascript
pauseSync();   // Stop automatic sync
resumeSync();  // Resume automatic sync
```

### Clean Up Old Logs

The system automatically trims logs to keep the last 10,000 entries. Adjust in Config sheet.

---

## pfSense Configuration Backup

The system supports automatic backup of complete pfSense configurations to Google Drive.

### Features

- **Full Config Backup**: Exports complete `/conf/config.xml` from each pfSense
- **Google Drive Storage**: Backups stored in organized folders by firewall
- **Scheduled Backups**: Daily automatic backups at 2 AM (configurable)
- **Retention Policy**: Automatically removes old backups (default: keep 10 per firewall)
- **Encryption Support**: Optional AES-256 encryption for sensitive configs

### Manual Backup

1. Go to the **Backups** tab in the dashboard
2. Click **Backup All** to backup all firewalls
3. Or click **Backup** on a specific firewall row

### Scheduled Backup Configuration

```javascript
// Enable scheduled backup at 2 AM
enableScheduledBackup(2);

// Disable scheduled backup
disableScheduledBackup();

// Set retention (keep 10 backups per firewall)
setBackupRetention(10);
```

### Backup Storage

Backups are stored in Google Drive:
```
My Drive/
└── pfSense Backups/
    ├── Office Main (pf-office)/
    │   ├── Office Main_2026-01-19_02-00-00.xml
    │   └── Office Main_2026-01-18_02-00-00.xml
    └── Branch Office (pf-branch)/
        └── Branch Office_2026-01-19_02-00-00.xml
```

### Restore a Backup

1. Go to **Backups** tab
2. Click **Download** on the desired backup
3. In pfSense: Go to **Diagnostics > Backup & Restore**
4. Upload the downloaded XML file
5. Click **Restore Configuration**

---

## API Reference

### pfSense API Endpoints

| Action | Method | Description |
|--------|--------|-------------|
| `status` | GET | Get system status and binding counts |
| `bindings` | GET | List all active bindings |
| `zones` | GET | List captive portal zones |
| `add` | POST | Add MAC binding |
| `remove` | POST | Remove MAC binding |
| `sync` | POST | Trigger immediate sync |
| `search` | GET | Search bindings by MAC/IP |
| `backup` | GET | Export full pfSense config backup |
| `sysinfo` | GET | Get detailed system information |

### GAS Functions

| Function | Description |
|----------|-------------|
| `manualSync()` | Sync all firewalls |
| `manualBackupAll()` | Backup all firewalls |
| `initializeApp()` | Initialize sheets and triggers |
| `setupTriggers()` | Create scheduled triggers |
| `pauseSync()` | Pause automatic sync |
| `resumeSync()` | Resume automatic sync |
| `enableScheduledBackup(hour)` | Enable daily backup at specified hour |
| `disableScheduledBackup()` | Disable scheduled backups |
| `setBackupRetention(count)` | Set number of backups to keep |

---

## Support

For issues specific to:
- **pfSense**: Check `/var/log/macbind_api.log` on the firewall
- **Google Apps Script**: Check Execution logs in the editor
- **Web App**: Check browser console (F12 > Console)
