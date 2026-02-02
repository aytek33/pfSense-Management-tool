# RRD Backup Implementation Summary

## Overview
This document summarizes the implementation of RRD (Round Robin Database) data backup functionality in the pfSense MAC Binding Manager webApp. The implementation ensures compatibility with pfSense 2.7.2 source code and provides RRD data inclusion by default, with an optional checkbox to exclude it.

## Implementation Date
January 2026

## Key Requirements
1. **RRD data included by default** - All backups include RRD data unless explicitly excluded
2. **pfSense 2.7.2 compatibility** - Implementation matches native pfSense backup behavior
3. **Google Apps Script compatibility** - No use of browser-only APIs (like `fetch()`) in server-side code
4. **UI checkbox control** - Users can opt-out of RRD data inclusion via checkbox
5. **Backend respects UI selection** - Backend properly handles checkbox state

## Files Modified

### 1. Backend: `usr/local/www/macbind_api.php`
**Location**: pfSense server

#### Changes:
- **RRD Data Generation Function** (`rrd_data_xml()`):
  - Enhanced error handling for `glob()`, `rrdtool dump`, and `file_get_contents()` operations
  - Added `gzdeflate()` compression with fallback (matches pfSense `backup.inc` line 60)
  - Comprehensive logging for debugging and troubleshooting
  - Silent cleanup of temporary XML files using `@unlink()`

- **RRD Data Insertion Logic**:
  - Changed from `str_replace()` to `strrpos()` and `substr_replace()` for precise placement
  - Inserts `<rrddata>` block before the closing `</pfsense>` tag (matches `backup.inc` line 215-216)
  - Validation after insertion to confirm RRD data is present
  - Only includes RRD data when `skip_rrd === false` AND `backup_area === 'all'`

- **Default Behavior**:
  - `skip_rrd` defaults to `false` (include RRD data) when not provided
  - Matches pfSense `backup.inc` line 213: `if (($post['backuparea'] != "rrddata") && !$post['donotbackuprrd'])`

- **Backup Response Enhancement**:
  - Added `rrddata` key to response containing:
    - `included` (boolean): Whether RRD data was included
    - `file_count`: Number of RRD files processed
    - `warning` or `reason`: Optional messages

- **Logging Improvements**:
  - Detailed logging for RRD generation, insertion, and validation
  - Clear indication of whether RRD data was included or excluded

#### Key Code Sections:
```php
// Default: skip_rrd = false means RRD data WILL be included
$skip_rrd = $get_option('skip_rrd');  // Defaults to false

// RRD data insertion (matches pfSense backup.inc line 213-217)
if ($skip_rrd === false && ($backup_area === 'all' || $backup_area === '')) {
    if (function_exists('rrd_data_xml')) {
        $rrd_data_xml = rrd_data_xml();
        // Insert before </pfsense> tag
        $closing_tag = '</pfsense>';
        $pos = strrpos($config_content, $closing_tag);
        if ($pos !== false) {
            $config_content = substr_replace($config_content, $rrd_data_xml . $closing_tag, $pos, strlen($closing_tag));
        }
    }
}
```

### 2. Frontend: `gas/BackupService.gs`
**Location**: Google Apps Script

#### Changes:
- **Option Normalization**:
  - `skipRrd` explicitly defaults to `false` (include RRD data)
  - Proper boolean type conversion from JSON/form inputs
  - Always includes `skip_rrd` in payload (even when `false`)

- **Scheduled Backup Support**:
  - Ensures `skipRrd` defaults to `false` when creating new schedules
  - Properly parses and defaults options when loading existing schedules
  - Defaults applied on error during option parsing

- **Logging**:
  - Clear logging showing whether RRD data will be included or excluded
  - Logs input values, normalized values, and final payload

#### Key Code Sections:
```javascript
// Normalize options - skipRrd defaults to false (include RRD data)
const normalizedOptions = {
  skipRrd: options.skipRrd === true || options.skipRrd === 'true' || options.skipRrd === 1,
  // ... other options
};

// Always explicitly include skip_rrd in payload
const payload = {
  skip_rrd: normalizedOptions.skipRrd,  // Default to false (include RRD data)
  // ... other options
};
```

### 3. Frontend: `gas/index.html`
**Location**: Google Apps Script (client-side)

#### Changes:
- **Backup Options Modal**:
  - Checkbox label: "Skip RRD/graph data (reduces size) - RRD data included by default"
  - Checkbox unchecked by default (include RRD data)
  - Explicitly sets `skipRrd: false` when checkbox is unchecked

- **Immediate Backup Function**:
  - Default options include `skipRrd: false`
  - Properly handles form submission with explicit boolean values

- **Scheduled Backup Form**:
  - Same checkbox behavior as immediate backup
  - Explicitly sets `skipRrd` value when creating/updating schedules

- **Removed Debug Code**:
  - Removed all debug `fetch()` calls (these were causing errors in Apps Script)
  - Cleaned up agent log regions

#### Key Code Sections:
```javascript
// Default backup options
const defaultOptions = {
  skipRrd: false,  // Include RRD data by default
  skipPackages: false,
  includeExtra: true,
  includeSshKeys: false,
  backupArea: 'all'
};

// Form submission
const skipRrd = form.skipRrd ? form.skipRrd.checked : false;  // false = include RRD (default)
const options = {
  skipRrd: skipRrd,  // Explicitly set (false = include RRD by default)
  // ... other options
};
```

### 4. API Client: `gas/PfSenseApi.gs`
**Location**: Google Apps Script

#### Changes:
- **Removed Debug Code**:
  - Removed all `fetch()` calls (browser API not available in Apps Script)
  - Replaced with `Logger.log()` for debugging
  - Kept `UrlFetchApp.fetch()` (correct Apps Script API)

## pfSense 2.7.2 Compatibility

### Source Code Reference
- **File**: `pfsense-src-2.7.2/src/usr/local/pfSense/include/www/backup.inc`
- **RRD Function**: Lines 44-66 (`rrd_data_xml()`)
- **RRD Inclusion Logic**: Lines 213-217
- **UI Default**: Line 173 (`donotbackuprrd` checkbox defaults to `true` in UI, but our implementation defaults to `false` to include RRD)

### Compatibility Points:
1. **RRD Data Generation**:
   - Uses same `rrdtool dump` command
   - Same temp file handling (create XML in same directory as RRD file)
   - Same compression method (`gzdeflate()`)
   - Same base64 encoding

2. **RRD Data Insertion**:
   - Inserts before closing `</pfsense>` tag
   - Same XML structure: `<rrddata><rrddatafile><filename>...</filename><xmldata>...</xmldata></rrddatafile></rrddata>`

3. **Conditional Logic**:
   - Only includes RRD when `backup_area === 'all'`
   - Respects `skip_rrd` parameter

## Google Apps Script Compatibility

### Verification Results:
✅ **No `fetch()` calls in `.gs` files** - All removed
✅ **Only `UrlFetchApp.fetch()` used** - Correct Apps Script API
✅ **No browser-only APIs** - All code compatible with Apps Script runtime

### Files Verified:
- `gas/BackupService.gs` - ✅ No fetch() calls
- `gas/PfSenseApi.gs` - ✅ Only UrlFetchApp.fetch()
- `gas/Code.gs` - ✅ No fetch() calls
- `gas/SheetsDb.gs` - ✅ No fetch() calls
- `gas/index.html` - ✅ fetch() calls removed (client-side, but removed for cleanliness)

## Default Behavior

### RRD Data Inclusion:
- **Default**: ✅ **INCLUDED** (`skip_rrd = false`)
- **When Excluded**: Only when checkbox is checked (`skip_rrd = true`)
- **Backend Default**: `false` (include RRD data)
- **Frontend Default**: `false` (include RRD data)
- **UI Default**: Checkbox unchecked (include RRD data)

### Flow:
1. User creates backup (checkbox unchecked by default)
2. Frontend sends `skipRrd: false` in payload
3. Backend receives `skip_rrd = false`
4. Backend includes RRD data in backup XML
5. Backup file contains `<rrddata>` section

## Testing Checklist

- [x] RRD data included by default in immediate backups
- [x] RRD data included by default in scheduled backups
- [x] Checkbox unchecked = RRD data included
- [x] Checkbox checked = RRD data excluded
- [x] Backend properly handles `skip_rrd` parameter
- [x] No `fetch()` errors in Apps Script
- [x] Backup XML structure matches pfSense native backup
- [x] RRD data properly compressed and encoded
- [x] Error handling for missing RRD files
- [x] Logging provides clear feedback

## Error Handling

### RRD Generation Errors:
- Empty `glob()` results → Logs and returns empty `<rrddata></rrddata>`
- `rrdtool dump` failures → Logs warning and skips file
- `file_get_contents()` failures → Logs warning and skips file
- `gzdeflate()` failures → Falls back to uncompressed data

### RRD Insertion Errors:
- Missing `</pfsense>` tag → Logs error, backup continues without RRD
- Insertion validation fails → Logs error, backup may be incomplete

## Logging

### Backend Logs (`macbind_api.php`):
- `DEBUG`: RRD handling parameters, generation steps
- `INFO`: RRD data included/excluded, file counts, sizes
- `WARNING`: RRD file processing errors, compression failures
- `ERROR`: RRD insertion failures

### Frontend Logs (`BackupService.gs`):
- Option normalization values
- Payload values sent to backend
- Whether RRD data will be included or excluded

## Future Enhancements

1. **RRD Data Size Warning**: Show estimated size before backup
2. **Selective RRD Backup**: Allow selection of specific RRD files
3. **RRD Data Validation**: Verify RRD data integrity after restore
4. **Progress Indicators**: Show RRD processing progress for large backups

## References

- pfSense Source Code: `pfsense-src-2.7.2/src/usr/local/pfSense/include/www/backup.inc`
- pfSense UI: `pfsense-src-2.7.2/src/usr/local/www/diag_backup.php` (line 169-174)
- Google Apps Script API: `UrlFetchApp.fetch()` documentation

## Notes

- The implementation intentionally defaults to **including** RRD data (opposite of pfSense UI default) to ensure complete backups by default
- Users can opt-out via checkbox if they want smaller backup files
- All RRD processing happens server-side on pfSense (no data transfer until final backup)
- RRD data is compressed using `gzdeflate()` to reduce backup file size
