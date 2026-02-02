# Voucher Reuse Prevention Fix

## Problem

When "Pass-through MAC Auto Entry" is enabled in pfSense Captive Portal, the same voucher could be used on multiple devices simultaneously. This happened because:

1. pfSense's built-in concurrent login check (`portal_allow()` returning 2) doesn't work with Pass-through MAC Auto Entry - the session is never written to the database
2. The `voucher_usage` tracking was only written when `macbind_api.php` processed the queue (via cron), creating a race condition where Device B could use the voucher before Device A's usage was recorded

## Solution

Immediate voucher_usage tracking in `portal_mac_to_voucher.php`:
- Write `voucher_usage` to `/var/db/macbind_active.json` immediately when a voucher is validated
- Check against this file before allowing access
- Verify the previous MAC is still active in pfSense's `passthrumac` config

## Files to Replace

### 1. pfSense Files (on pfSense firewall)

| Local Path | Destination on pfSense |
|------------|------------------------|
| `pfSense/portal_mac_to_voucher.php` | `/usr/local/captiveportal/portal_mac_to_voucher.php` |
| `usr/local/www/macbind_api.php` | `/usr/local/www/macbind_api.php` |

### 2. Google Apps Script Files (in GAS project)

| Local Path | Description |
|------------|-------------|
| `gas/Triggers.gs` | Cleanup trigger changed to 15 minutes |
| `gas/Code.gs` | Added cleanup call in scheduledSync |
| `gas/PfSenseApi.gs` | Added voucher_hash support in addBinding |
| `gas/BindingService.gs` | Added voucherHash to binding object |
| `gas/index.html` | Added VOUCHER_ALREADY_IN_USE error handling |

## Deployment Steps

### Step 1: Update pfSense Files

```bash
# SSH into pfSense
ssh admin@your-pfsense-ip

# Backup existing files
cp /usr/local/captiveportal/portal_mac_to_voucher.php /usr/local/captiveportal/portal_mac_to_voucher.php.bak
cp /usr/local/www/macbind_api.php /usr/local/www/macbind_api.php.bak

# Copy new files (via SCP or manual paste)
# Option 1: SCP from local machine
scp pfSense/portal_mac_to_voucher.php admin@pfsense:/usr/local/captiveportal/
scp usr/local/www/macbind_api.php admin@pfsense:/usr/local/www/

# Option 2: Manual - copy content and paste via vi/nano

# Set permissions
chmod 755 /usr/local/captiveportal/portal_mac_to_voucher.php
chmod 755 /usr/local/www/macbind_api.php
chown root:wheel /usr/local/captiveportal/portal_mac_to_voucher.php
chown root:wheel /usr/local/www/macbind_api.php
```

### Step 2: Update Google Apps Script

1. Open your Google Apps Script project
2. Replace contents of each file:
   - `Triggers.gs`
   - `Code.gs`
   - `PfSenseApi.gs`
   - `BindingService.gs`
   - `index.html`
3. Save all files
4. Run `setupTriggers()` to recreate triggers with new schedule

### Step 3: Initialize Active DB (if needed)

```bash
# On pfSense, ensure the active DB file exists
touch /var/db/macbind_active.json
chmod 644 /var/db/macbind_active.json
echo '{"bindings":{},"voucher_usage":{}}' > /var/db/macbind_active.json
```

## How It Works

### Flow Diagram

```
Device A uses voucher "ABC123"
    |
    v
portal_mac_to_voucher.php
    |
    +---> Check voucher_usage in macbind_active.json
    |     (No previous usage found)
    |
    +---> WRITE voucher_usage immediately  <-- NEW
    |     {mac: "aa:bb:cc:dd:ee:ff", expires_at: "..."}
    |
    +---> Allow access via portal_allow()
    v
Device B tries same voucher "ABC123"
    |
    v
portal_mac_to_voucher.php
    |
    +---> Check voucher_usage in macbind_active.json
    |     (Found: mac="aa:bb:cc:dd:ee:ff")
    |
    +---> Check if aa:bb:cc:dd:ee:ff in passthrumac
    |     (YES - still active)
    |
    +---> BLOCKED - Show error page
          - Active Device: aa:bb:cc:dd:ee:ff
          - Time Remaining: 2h 15m
          - Your Device: 11:22:33:44:55:66
```

### Scenarios

| Scenario | Result |
|----------|--------|
| First device uses voucher | Allowed (no previous usage) |
| Same device reconnects | Allowed (same MAC) |
| Different device, original still active | **Blocked** |
| Different device, original expired/removed | Allowed |

## Key Code Changes

### portal_mac_to_voucher.php

Added two new sections after voucher validation:

1. **VOUCHER REUSE CHECK** (lines 248-430)
   - Reads `/var/db/macbind_active.json`
   - Checks if voucher_hash has previous usage
   - Verifies previous MAC is still in pfSense passthrumac
   - Shows error page if blocked

2. **WRITE VOUCHER USAGE IMMEDIATELY** (lines 432-477)
   - Writes voucher_usage to active.json immediately
   - Prevents race condition
   - Uses atomic write (temp file + rename)

### macbind_api.php

Already had voucher reuse prevention in `action_add()` - this serves as a secondary check when API is called directly.

### Triggers.gs

Changed cleanup trigger from daily to every 15 minutes for timely expiration handling.

## Testing

### Test Case 1: Block Second Device

1. Use voucher on Device A
2. Try same voucher on Device B within expiration time
3. Expected: Device B sees "Voucher Already In Use" error with:
   - Active Device MAC
   - Time Remaining
   - Your Device MAC

### Test Case 2: Allow Reconnect

1. Use voucher on Device A
2. Disconnect Device A
3. Reconnect Device A with same voucher
4. Expected: Device A reconnects successfully

### Test Case 3: Allow After Expiration

1. Use voucher on Device A
2. Wait for voucher to expire (or manually remove MAC from passthrumac)
3. Try same voucher on Device B
4. Expected: Device B is allowed (original binding no longer active)

## Troubleshooting

### Check Active DB Content

```bash
cat /var/db/macbind_active.json | python3 -m json.tool
```

### Check PHP Error Log

```bash
tail -f /var/log/php_errors.log | grep macbind
```

### Verify passthrumac Entries

```bash
# In pfSense shell
pfSsh.php playback listmacs
# Or check config directly
cat /cf/conf/config.xml | grep -A5 passthrumac
```

### Manual Cleanup

```bash
# Clear voucher_usage (allows all vouchers to be reused)
echo '{"bindings":{},"voucher_usage":{}}' > /var/db/macbind_active.json
```

## Compatibility

- pfSense 2.7.2: Verified
- Google Apps Script: Compatible
- Uses pfSense's `$config['captiveportal'][zone]['passthrumac']` structure
- Does not modify pfSense core files or databases
