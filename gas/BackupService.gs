/**
 * BackupService.gs - pfSense Backup Management with Google Drive Storage
 * 
 * Handles backup operations for pfSense firewalls,
 * storing configuration backups in Google Drive.
 * Includes scheduled backup management with cron-like scheduling.
 */

/**
 * BackupService namespace
 */
const BackupService = {
  
  // Default backup folder name in Google Drive
  BACKUP_FOLDER_NAME: 'pfSense Backups',
  
  // Cron-like schedule presets
  SCHEDULE_PRESETS: {
    'daily': '0 2 * * *',       // Daily at 2:00 AM
    'weekly': '0 2 * * 0',      // Weekly on Sunday at 2:00 AM
    'monthly': '0 2 1 * *',     // Monthly on 1st at 2:00 AM
    'every6hours': '0 */6 * * *', // Every 6 hours
    'every12hours': '0 */12 * * *' // Every 12 hours
  },
  
  /**
   * Get or create the backup folder in Google Drive
   *
   * Priority:
   * 1. Use backup_folder_id from Config (specific folder ID - recommended for shared access)
   * 2. Fall back to searching by folder name (creates in current user's Drive)
   */
  getBackupFolder: function() {
    // First, check if a specific folder ID is configured (recommended approach)
    const folderId = getConfigValue('backup_folder_id', '');
    if (folderId) {
      try {
        const folder = DriveApp.getFolderById(folderId);
        Logger.log('Using configured backup folder: ' + folder.getName());
        return folder;
      } catch (e) {
        Logger.log('Warning: Configured backup_folder_id is invalid: ' + folderId + ' - ' + e.message);
        // Fall through to search by name
      }
    }

    // Fall back to searching by name (creates in script owner's Drive)
    const folderName = getConfigValue('backup_folder_name', this.BACKUP_FOLDER_NAME);

    // Search for existing folder
    const folders = DriveApp.getFoldersByName(folderName);
    if (folders.hasNext()) {
      return folders.next();
    }

    // Create new folder
    const folder = DriveApp.createFolder(folderName);
    Logger.log('Created backup folder: ' + folderName + ' (ID: ' + folder.getId() + ')');
    Logger.log('TIP: Add "backup_folder_id" = "' + folder.getId() + '" to your Config sheet for consistent backup location');
    return folder;
  },

  /**
   * Verify backup folder access permissions
   * Checks if the backup folder is accessible and writable
   *
   * @returns {Object} Verification result with success flag and details
   */
  verifyBackupFolderAccess: function() {
    const result = {
      success: false,
      accessible: false,
      writable: false,
      folderId: null,
      folderName: null,
      folderUrl: null,
      issues: []
    };

    try {
      // Get the backup folder
      const folder = this.getBackupFolder();
      result.folderId = folder.getId();
      result.folderName = folder.getName();
      result.folderUrl = folder.getUrl();
      result.accessible = true;

      // Test write access by creating and deleting a test file
      const testFileName = '.backup_test_' + new Date().getTime() + '.tmp';
      try {
        const testFile = folder.createFile(testFileName, 'test', 'text/plain');
        testFile.setTrashed(true);
        result.writable = true;
      } catch (writeError) {
        result.issues.push('Cannot write to backup folder: ' + writeError.message);
      }

      // Check folder sharing settings
      try {
        const access = folder.getSharingAccess();
        const permission = folder.getSharingPermission();
        result.sharingAccess = access.toString();
        result.sharingPermission = permission.toString();
      } catch (shareError) {
        // Non-critical, just log it
        Logger.log('Could not check sharing settings: ' + shareError.message);
      }

      result.success = result.accessible && result.writable;

      if (!result.success) {
        SheetsDb.addLog('System', 'verify_backup_folder', 'warning',
          'Backup folder access issues: ' + result.issues.join(', '));
      }

      return result;
    } catch (error) {
      result.issues.push('Cannot access backup folder: ' + error.message);
      SheetsDb.addLog('System', 'verify_backup_folder', 'error', error.message);
      return result;
    }
  },
  
  /**
   * Get or create firewall-specific subfolder
   */
  getFirewallFolder: function(firewallId, firewallName) {
    const backupFolder = this.getBackupFolder();
    const subfolderName = `${firewallName} (${firewallId})`;
    
    // Search for existing subfolder
    const folders = backupFolder.getFoldersByName(subfolderName);
    if (folders.hasNext()) {
      return folders.next();
    }
    
    // Create new subfolder
    const folder = backupFolder.createFolder(subfolderName);
    Logger.log('Created firewall backup folder: ' + subfolderName);
    return folder;
  },
  
  /**
   * Backup a single firewall with advanced options
   *
   * @param {string} firewallId - Firewall ID
   * @param {Object} options - Backup options
   * @param {boolean} options.encrypt - Whether to encrypt the backup
   * @param {string} options.password - Encryption password (required if encrypt=true)
   * @param {boolean} options.includeExtra - Include extra data (DHCP leases, CP db, etc.) - default true
   * @param {boolean} options.skipPackages - Exclude package information
   * @param {boolean} options.skipRrd - Exclude RRD/graph data (reduces size)
   * @param {boolean} options.includeSshKeys - Include SSH host keys
   * @param {string} options.backupArea - Backup area: 'all' or specific section
   * @param {boolean} options.skipFolderVerify - Skip folder access verification (for performance)
   * @returns {Object} Backup result
   */
  backupFirewall: function(firewallId, options = {}) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }

    // Verify backup folder access before starting (unless skipped)
    if (!options.skipFolderVerify) {
      const folderVerify = this.verifyBackupFolderAccess();
      if (!folderVerify.success) {
        return {
          success: false,
          error: 'Backup folder access verification failed',
          issues: folderVerify.issues,
          firewall: firewall.name
        };
      }
    }

    Logger.log('Starting backup for firewall: ' + firewall.name);
    const startTime = new Date().getTime();
    
    // Normalize options to ensure boolean values are properly typed
    // This handles cases where options might come from JSON parsing or form submission
    // IMPORTANT: skip_rrd defaults to false (include RRD data) to match pfSense API behavior
    // Only skip RRD data if explicitly set to true
    const normalizedOptions = {
      // skipRrd: false by default (include RRD data) unless explicitly set to true
      skipRrd: options.skipRrd === true || options.skipRrd === 'true' || options.skipRrd === 1,
      skipPackages: options.skipPackages === true || options.skipPackages === 'true' || options.skipPackages === 1,
      includeExtra: options.includeExtra !== false && options.includeExtra !== 'false' && options.includeExtra !== 0,
      includeSshKeys: options.includeSshKeys === true || options.includeSshKeys === 'true' || options.includeSshKeys === 1,
      backupArea: options.backupArea || 'all',
      encrypt: options.encrypt === true || options.encrypt === 'true' || options.encrypt === 1
    };
    
    // Build payload with all backup options
    // IMPORTANT: Always explicitly include skip_rrd (even when false) to ensure PHP API receives it
    // Default behavior: skip_rrd=false means RRD data WILL be included (matches pfSense backup.inc line 213)
    const payload = {
      include_extra: normalizedOptions.includeExtra,  // Default to true
      skip_packages: normalizedOptions.skipPackages,  // Default to false (include packages)
      skip_rrd: normalizedOptions.skipRrd,  // Default to false (include RRD data) - matches pfSense behavior
      include_ssh_keys: normalizedOptions.includeSshKeys,  // Default to false (don't include SSH keys)
      backup_area: normalizedOptions.backupArea  // Default to 'all'
    };
    
    // Log backup options for debugging
    Logger.log('Backup options - skipRrd: ' + normalizedOptions.skipRrd + 
               ' (input: ' + (options.skipRrd !== undefined ? options.skipRrd : 'undefined') + 
               ', will ' + (normalizedOptions.skipRrd ? 'EXCLUDE' : 'INCLUDE') + ' RRD data)');
    Logger.log('Backup payload: skip_rrd=' + payload.skip_rrd + 
               ', skip_packages=' + payload.skip_packages +
               ', include_extra=' + payload.include_extra + 
               ', include_ssh_keys=' + payload.include_ssh_keys);
    
    if (options.encrypt && options.password) {
      payload.encrypt = true;
      payload.password = options.password;
    }
    
    // Request backup from pfSense (uses POST with api_key in body)
    const result = PfSenseApi.request(firewall, 'backup', 'POST', payload);
    
    if (!result.success) {
      SheetsDb.addLog(firewall.name, 'backup', 'error', result.error);
      return {
        success: false,
        error: result.error,
        firewall: firewall.name
      };
    }
    
    const backupData = result.data;
    
    // Get or create firewall folder
    const folder = this.getFirewallFolder(firewallId, firewall.name);
    
    // Generate filename with timestamp
    const timestamp = Utilities.formatDate(new Date(), 'UTC', 'yyyy-MM-dd_HH-mm-ss');
    const extension = options.encrypt ? 'xml.enc' : 'xml';
    const filename = `${firewall.name}_${timestamp}.${extension}`;
    
    // Decode the base64 config
    const configData = Utilities.base64Decode(backupData.config);
    const blob = Utilities.newBlob(configData, 'application/xml', filename);
    
    // Save to Google Drive
    const file = folder.createFile(blob);
    
    // Add metadata as description (includes RRD and extra data info from API)
    const metadata = {
      firewallId: firewallId,
      firewallName: firewall.name,
      hostname: backupData.hostname,
      version: backupData.version,
      timestamp: backupData.timestamp,
      encrypted: backupData.encrypted,
      sizeBytes: backupData.size_bytes,
      rrddata: backupData.rrddata || null,
      extradata: backupData.extradata || null
    };
    file.setDescription(JSON.stringify(metadata, null, 2));
    
    // Verify backup content against options (using normalizedOptions declared above)
    const verification = this.verifyBackup(file.getId(), {
      encrypt: normalizedOptions.encrypt,
      includeExtra: normalizedOptions.includeExtra,
      skipRrd: normalizedOptions.skipRrd,
      includeSshKeys: normalizedOptions.includeSshKeys,
      skipPackages: normalizedOptions.skipPackages
    });
    
    const duration = new Date().getTime() - startTime;
    
    // Record backup in Sheets with verification status
    this.recordBackup({
      firewallId: firewallId,
      firewallName: firewall.name,
      timestamp: backupData.timestamp,
      filename: filename,
      fileId: file.getId(),
      fileUrl: file.getUrl(),
      sizeBytes: backupData.size_bytes,
      encrypted: backupData.encrypted,
      pfSenseVersion: backupData.version,
      verified: verification.verified
    });
    
    // Log backup result with verification status
    const verifyStatus = verification.verified ? '✓ Verified' : '✗ Verification failed';
    SheetsDb.addLog(firewall.name, 'backup', 'success', 
      `Backup saved: ${filename} (${formatBytes(backupData.size_bytes)}) - ${verifyStatus}: ${verification.details}`);
    
    if (!verification.verified) {
      Logger.log('Backup verification failed: ' + verification.details);
    }
    
    return {
      success: true,
      firewall: firewall.name,
      filename: filename,
      fileId: file.getId(),
      fileUrl: file.getUrl(),
      sizeBytes: backupData.size_bytes,
      encrypted: backupData.encrypted,
      duration: duration
    };
  },
  
  /**
   * Backup all firewalls
   * 
   * @param {Object} options - Backup options
   * @returns {Object} Results for all firewalls
   */
  backupAllFirewalls: function(options = {}) {
    const firewalls = SheetsDb.getFirewalls();
    
    if (firewalls.length === 0) {
      return { success: true, message: 'No firewalls configured', results: [] };
    }
    
    Logger.log('Starting backup for ' + firewalls.length + ' firewalls');
    const startTime = new Date().getTime();
    
    const results = [];
    let successCount = 0;
    let errorCount = 0;
    
    firewalls.forEach(firewall => {
      // Only backup online firewalls
      if (firewall.status !== 'online' && firewall.status !== 'unknown') {
        results.push({
          success: false,
          firewall: firewall.name,
          error: 'Firewall is ' + firewall.status
        });
        errorCount++;
        return;
      }
      
      try {
        const result = this.backupFirewall(firewall.id, options);
        results.push(result);
        
        if (result.success) {
          successCount++;
        } else {
          errorCount++;
        }
      } catch (error) {
        results.push({
          success: false,
          firewall: firewall.name,
          error: error.message
        });
        errorCount++;
      }
      
      // Check timeout (GAS 6-minute limit)
      const elapsed = new Date().getTime() - startTime;
      if (elapsed > 5 * 60 * 1000) {
        Logger.log('Approaching timeout, stopping backup');
        return;
      }
    });
    
    const totalDuration = new Date().getTime() - startTime;
    
    return {
      success: errorCount === 0,
      totalFirewalls: firewalls.length,
      successCount: successCount,
      errorCount: errorCount,
      duration: totalDuration,
      results: results
    };
  },
  
  /**
   * Verify backup file content against expected options
   * 
   * @param {string} fileId - Google Drive file ID
   * @param {Object} options - Backup options used
   * @returns {Object} Verification result {verified: boolean, details: string}
   */
  verifyBackup: function(fileId, options = {}) {
    try {
      // Get backup file content
      const fileResult = this.getBackupContent(fileId);
      if (!fileResult.success) {
        return { verified: false, details: 'Failed to read backup file: ' + fileResult.error };
      }
      
      const content = fileResult.content;
      const isEncrypted = options.encrypt === true;
      
      // For encrypted backups, only verify file structure
      if (isEncrypted) {
        // Check if file has content and looks like encrypted backup
        if (content && content.length > 0) {
          // Encrypted backups may have specific markers or structure
          // Basic check: file exists and has content
          return { verified: true, details: 'Encrypted backup file verified (structure check only)' };
        }
        return { verified: false, details: 'Encrypted backup file is empty' };
      }
      
      // For non-encrypted backups, verify XML content
      // Basic XML structure check
      if (!content || content.trim().length === 0) {
        return { verified: false, details: 'Backup file is empty' };
      }
      
      // Check for valid XML structure
      if (!content.includes('<?xml') || !content.includes('<pfsense>')) {
        return { verified: false, details: 'Invalid XML structure: missing XML declaration or pfsense root tag' };
      }
      
      // Check for version tag (required)
      if (!content.includes('<version>')) {
        return { verified: false, details: 'Missing version tag' };
      }
      
      const checks = [];
      let allChecksPassed = true;
      
      // Check for RRD data if skipRrd is false (default)
      if (options.skipRrd !== true) {
        if (content.includes('<rrddata>')) {
          checks.push('RRD data present');
        } else {
          checks.push('RRD data missing (expected)');
          allChecksPassed = false;
        }
      } else {
        // If skipRrd is true, verify it's not present
        if (!content.includes('<rrddata>') || content.match(/<rrddata>\s*<\/rrddata>/)) {
          checks.push('RRD data excluded (as requested)');
        } else {
          checks.push('RRD data present (should be excluded)');
          allChecksPassed = false;
        }
      }
      
      // Check for extra data if includeExtra is true (default)
      // Note: Extra data is now embedded INSIDE config sections (pfSense 2.7.2 compatible)
      // e.g., <captiveportaldata> is inside <captiveportal>, not at root level
      if (options.includeExtra !== false) {
        const hasCpData = content.includes('<captiveportaldata>');
        const hasDhcpData = content.includes('<dhcpddata>');
        const hasVoucherData = content.includes('<voucherdata>');

        if (hasCpData || hasDhcpData || hasVoucherData) {
          const found = [];
          if (hasCpData) found.push('CP');
          if (hasDhcpData) found.push('DHCP');
          if (hasVoucherData) found.push('Voucher');
          checks.push('Extra data present: ' + found.join(', '));
        } else {
          // Extra data might not exist if services aren't configured
          // This is normal - captiveportal, dhcpd, or voucher services may not be enabled
          checks.push('Extra data sections not found (normal if services not configured)');
        }
      }
      
      // Check for SSH keys if includeSshKeys is true
      if (options.includeSshKeys === true) {
        if (content.includes('<sshdata>')) {
          checks.push('SSH keys present');
        } else {
          checks.push('SSH keys missing (expected)');
          allChecksPassed = false;
        }
      }
      
      // Check for packages if skipPackages is false
      if (options.skipPackages !== true) {
        if (content.includes('<installedpackages>')) {
          checks.push('Packages present');
        } else {
          // Packages might not exist if none installed
          checks.push('Packages section not found (may be normal)');
        }
      } else {
        // If skipPackages is true, verify it's excluded
        if (!content.includes('<installedpackages>') || content.match(/<installedpackages>\s*<\/installedpackages>/)) {
          checks.push('Packages excluded (as requested)');
        } else {
          checks.push('Packages present (should be excluded)');
          allChecksPassed = false;
        }
      }
      
      const details = checks.length > 0 ? checks.join('; ') : 'Basic structure verified';
      
      return {
        verified: allChecksPassed,
        details: details
      };
      
    } catch (error) {
      Logger.log('Backup verification error: ' + error.message);
      return { verified: false, details: 'Verification error: ' + error.message };
    }
  },
  
  /**
   * Record backup in the Backups sheet
   * 
   * @param {Object} backup - Backup record object
   * @param {string} backup.verified - Verification status: "*" if verified, "" if not
   */
  recordBackup: function(backup) {
    const sheet = this.getBackupsSheet();
    
    // Verified status: "*" if verified, "" if not verified or not checked
    const verified = backup.verified === true || backup.verified === '*' ? '*' : '';
    
    sheet.appendRow([
      backup.timestamp,
      backup.firewallId,
      backup.firewallName,
      backup.filename,
      backup.fileId,
      backup.fileUrl,
      backup.sizeBytes,
      backup.encrypted ? 'Yes' : 'No',
      backup.pfSenseVersion,
      verified
    ]);
  },
  
  /**
   * Get or create Backups sheet
   */
  getBackupsSheet: function() {
    const ss = SheetsDb.getSpreadsheet();
    let sheet = ss.getSheetByName('Backups');
    
    if (!sheet) {
      sheet = ss.insertSheet('Backups');
      sheet.appendRow([
        'Timestamp', 'Firewall_ID', 'Firewall_Name', 'Filename',
        'File_ID', 'File_URL', 'Size_Bytes', 'Encrypted', 'pfSense_Version', 'Verified'
      ]);
      sheet.getRange(1, 1, 1, 10).setFontWeight('bold').setBackground('#9c27b0').setFontColor('white');
      sheet.setFrozenRows(1);
    } else {
      // Check if Verified column exists, add if missing (for existing sheets)
      const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
      if (headers.length < 10 || headers[9] !== 'Verified') {
        // Add Verified column header if missing
        sheet.getRange(1, 10).setValue('Verified');
        sheet.getRange(1, 10).setFontWeight('bold').setBackground('#9c27b0').setFontColor('white');
      }
    }
    
    return sheet;
  },
  
  /**
   * Get backup history for a firewall
   */
  getBackupHistory: function(firewallId = null, limit = 50) {
    const sheet = this.getBackupsSheet();
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return [];
    
    const backups = [];
    for (let i = data.length - 1; i >= 1 && backups.length < limit; i--) {
      const row = data[i];
      if (firewallId && row[1] !== firewallId) continue;
      
      backups.push({
        timestamp: row[0],
        firewallId: row[1],
        firewallName: row[2],
        filename: row[3],
        fileId: row[4],
        fileUrl: row[5],
        sizeBytes: row[6],
        encrypted: row[7] === 'Yes',
        pfSenseVersion: row[8],
        verified: row[9] === '*' || row[9] === true || false // Handle missing column for old backups
      });
    }
    
    return backups;
  },
  
  /**
   * Delete old backups based on retention policy
   * 
   * @param {number} keepCount - Number of backups to keep per firewall
   */
  cleanupOldBackups: function(keepCount = 10) {
    const firewalls = SheetsDb.getFirewalls();
    let deletedCount = 0;
    
    firewalls.forEach(firewall => {
      const backups = this.getBackupHistory(firewall.id, 1000);
      
      if (backups.length <= keepCount) return;
      
      // Delete older backups (beyond keepCount)
      const toDelete = backups.slice(keepCount);
      
      toDelete.forEach(backup => {
        try {
          // Delete from Google Drive
          const file = DriveApp.getFileById(backup.fileId);
          file.setTrashed(true);
          deletedCount++;
        } catch (e) {
          Logger.log('Failed to delete backup file: ' + backup.fileId + ' - ' + e.message);
        }
      });
    });
    
    if (deletedCount > 0) {
      SheetsDb.addLog('System', 'cleanup_backups', 'success', 
        `Deleted ${deletedCount} old backup(s)`);
    }
    
    return { success: true, deleted: deletedCount };
  },
  
  /**
   * Get backup storage statistics
   */
  getBackupStats: function() {
    const backups = this.getBackupHistory(null, 10000);
    
    const stats = {
      totalBackups: backups.length,
      totalSizeBytes: 0,
      byFirewall: {},
      oldestBackup: null,
      newestBackup: null
    };
    
    backups.forEach(backup => {
      stats.totalSizeBytes += backup.sizeBytes || 0;
      
      const fwId = backup.firewallId;
      if (!stats.byFirewall[fwId]) {
        stats.byFirewall[fwId] = {
          name: backup.firewallName,
          count: 0,
          totalSize: 0,
          lastBackup: null
        };
      }
      
      stats.byFirewall[fwId].count++;
      stats.byFirewall[fwId].totalSize += backup.sizeBytes || 0;
      
      if (!stats.byFirewall[fwId].lastBackup || 
          new Date(backup.timestamp) > new Date(stats.byFirewall[fwId].lastBackup)) {
        stats.byFirewall[fwId].lastBackup = backup.timestamp;
      }
    });
    
    if (backups.length > 0) {
      stats.newestBackup = backups[0].timestamp;
      stats.oldestBackup = backups[backups.length - 1].timestamp;
    }
    
    stats.totalSizeFormatted = formatBytes(stats.totalSizeBytes);
    
    return stats;
  },
  
  /**
   * Download a backup file content
   */
  getBackupContent: function(fileId) {
    try {
      const file = DriveApp.getFileById(fileId);
      const content = file.getBlob().getDataAsString();
      return {
        success: true,
        filename: file.getName(),
        content: content,
        size: file.getSize()
      };
    } catch (error) {
      return {
        success: false,
        error: error.message
      };
    }
  },
  
  /**
   * Restore backup to pfSense (placeholder - requires additional pfSense API)
   * Note: Full restore requires SSH access or additional API endpoint on pfSense
   */
  restoreBackup: function(firewallId, fileId) {
    // This would require an additional restore endpoint on pfSense
    // For now, return instructions for manual restore
    return {
      success: false,
      error: 'Automatic restore not yet implemented. Please download the backup and restore manually via pfSense web interface (Diagnostics > Backup & Restore).'
    };
  },
  
  // =========================================================================
  // SCHEDULED BACKUPS CRUD
  // =========================================================================
  
  /**
   * Get or create ScheduledBackups sheet
   */
  getScheduledBackupsSheet: function() {
    const ss = SheetsDb.getSpreadsheet();
    let sheet = ss.getSheetByName('ScheduledBackups');
    
    if (!sheet) {
      sheet = ss.insertSheet('ScheduledBackups');
      sheet.appendRow([
        'ID', 'Firewall_ID', 'Schedule', 'Options_JSON', 
        'Enabled', 'Last_Run', 'Next_Run', 'Created_At', 'Notes'
      ]);
      sheet.getRange(1, 1, 1, 9).setFontWeight('bold').setBackground('#ff9800').setFontColor('white');
      sheet.setFrozenRows(1);
      Logger.log('Created ScheduledBackups sheet');
    }
    
    return sheet;
  },
  
  /**
   * Create a scheduled backup configuration
   * 
   * @param {Object} config - Scheduled backup config
   * @param {string} config.firewallId - Firewall ID (or 'all' for all firewalls)
   * @param {string} config.schedule - Cron expression or preset name
   * @param {Object} config.options - Backup options
   * @param {boolean} config.enabled - Whether the schedule is enabled
   * @param {string} config.notes - Optional notes
   * @returns {Object} Result with schedule ID
   */
  createScheduledBackup: function(config) {
    if (!config.firewallId) {
      return { success: false, error: 'Missing firewallId' };
    }
    if (!config.schedule) {
      return { success: false, error: 'Missing schedule' };
    }
    
    // Resolve preset to cron expression (skip for "NOW")
    let schedule = config.schedule;
    if (schedule !== 'NOW') {
      schedule = this.SCHEDULE_PRESETS[config.schedule] || config.schedule;
    }
    
    // Validate cron expression
    if (!this.isValidCron(schedule)) {
      return { success: false, error: 'Invalid cron expression: ' + schedule };
    }
    
    const sheet = this.getScheduledBackupsSheet();
    const id = 'sched_' + Date.now();
    const now = new Date().toISOString();
    const nextRun = this.getNextRunTime(schedule);
    
    // Ensure options object exists and has proper defaults
    // IMPORTANT: skipRrd defaults to false (include RRD data) to match pfSense behavior
    const backupOptions = config.options || {};
    if (backupOptions.skipRrd === undefined) {
      backupOptions.skipRrd = false;  // Default: include RRD data
    }
    
    sheet.appendRow([
      id,
      config.firewallId,
      schedule,
      JSON.stringify(backupOptions),
      config.enabled !== false ? 'true' : 'false',
      '', // Last run
      nextRun,
      now,
      config.notes || ''
    ]);
    
    SheetsDb.addLog('System', 'create_scheduled_backup', 'success', 
      'Created schedule: ' + id + ' for ' + config.firewallId);
    
    return { 
      success: true, 
      id: id, 
      schedule: schedule,
      nextRun: nextRun
    };
  },
  
  /**
   * Get all scheduled backups
   * 
   * @param {string} firewallId - Optional filter by firewall
   * @returns {Array} Scheduled backup configs
   */
  getScheduledBackups: function(firewallId = null) {
    const sheet = this.getScheduledBackupsSheet();
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return [];
    
    const schedules = [];
    for (let i = 1; i < data.length; i++) {
      const row = data[i];
      if (!row[0]) continue;
      
      if (firewallId && row[1] !== firewallId && row[1] !== 'all') continue;
      
      let options = {};
      try {
        const optionsJson = row[3] || '{}';
        options = JSON.parse(optionsJson);
        
        // Ensure boolean values are properly typed (Google Sheets might convert them to strings)
        // IMPORTANT: skipRrd defaults to false (include RRD data) to match pfSense behavior
        if (options.skipRrd !== undefined) {
          options.skipRrd = options.skipRrd === true || options.skipRrd === 'true' || options.skipRrd === 1;
        } else {
          options.skipRrd = false;  // Default: include RRD data
        }
        if (options.skipPackages !== undefined) {
          options.skipPackages = options.skipPackages === true || options.skipPackages === 'true' || options.skipPackages === 1;
        } else {
          options.skipPackages = false;  // Default: include packages
        }
        if (options.includeExtra !== undefined) {
          options.includeExtra = options.includeExtra !== false && options.includeExtra !== 'false' && options.includeExtra !== 0;
        } else {
          options.includeExtra = true;  // Default: include extra data
        }
        if (options.includeSshKeys !== undefined) {
          options.includeSshKeys = options.includeSshKeys === true || options.includeSshKeys === 'true' || options.includeSshKeys === 1;
        } else {
          options.includeSshKeys = false;  // Default: don't include SSH keys
        }
        if (options.encrypt !== undefined) {
          options.encrypt = options.encrypt === true || options.encrypt === 'true' || options.encrypt === 1;
        } else {
          options.encrypt = false;  // Default: don't encrypt
        }
        // Ensure backupArea is set (required for RRD inclusion)
        if (!options.backupArea) {
          options.backupArea = 'all';  // Default: all (required for RRD data inclusion)
        }
        
        Logger.log('Parsed schedule options for ' + row[0] + ': skipRrd=' + options.skipRrd + ' (RRD will be ' + (options.skipRrd ? 'EXCLUDED' : 'INCLUDED') + '), backupArea=' + options.backupArea);
      } catch (e) {
        Logger.log('Error parsing schedule options: ' + e.message);
        // Set defaults on error
        options = {
          skipRrd: false,  // Default: include RRD data
          skipPackages: false,
          includeExtra: true,
          includeSshKeys: false,
          encrypt: false,
          backupArea: 'all'  // Default: all (required for RRD data inclusion)
        };
      }
      
      schedules.push({
        id: row[0],
        firewallId: row[1],
        schedule: row[2],
        options: options,
        enabled: row[4] === 'true' || row[4] === true,
        lastRun: row[5],
        nextRun: row[6],
        createdAt: row[7],
        notes: row[8],
        rowIndex: i + 1
      });
    }
    
    return schedules;
  },
  
  /**
   * Get a specific scheduled backup by ID
   * 
   * @param {string} scheduleId - Schedule ID
   * @returns {Object|null} Schedule config or null
   */
  getScheduledBackup: function(scheduleId) {
    const schedules = this.getScheduledBackups();
    return schedules.find(s => s.id === scheduleId) || null;
  },
  
  /**
   * Update a scheduled backup configuration
   * 
   * @param {string} scheduleId - Schedule ID
   * @param {Object} updates - Fields to update
   * @returns {Object} Result
   */
  updateScheduledBackup: function(scheduleId, updates) {
    const existing = this.getScheduledBackup(scheduleId);
    if (!existing) {
      return { success: false, error: 'Schedule not found: ' + scheduleId };
    }
    
    const sheet = this.getScheduledBackupsSheet();
    const rowIndex = existing.rowIndex;
    
    if (updates.schedule !== undefined) {
      const schedule = this.SCHEDULE_PRESETS[updates.schedule] || updates.schedule;
      if (!this.isValidCron(schedule)) {
        return { success: false, error: 'Invalid cron expression' };
      }
      sheet.getRange(rowIndex, 3).setValue(schedule);
      // Update next run time
      sheet.getRange(rowIndex, 7).setValue(this.getNextRunTime(schedule));
    }
    
    if (updates.options !== undefined) {
      // Ensure boolean values are properly serialized as JSON booleans, not strings
      const optionsToStore = {
        skipRrd: updates.options.skipRrd === true || updates.options.skipRrd === 'true' || updates.options.skipRrd === 1,
        skipPackages: updates.options.skipPackages === true || updates.options.skipPackages === 'true' || updates.options.skipPackages === 1,
        includeExtra: updates.options.includeExtra !== false && updates.options.includeExtra !== 'false' && updates.options.includeExtra !== 0,
        includeSshKeys: updates.options.includeSshKeys === true || updates.options.includeSshKeys === 'true' || updates.options.includeSshKeys === 1,
        encrypt: updates.options.encrypt === true || updates.options.encrypt === 'true' || updates.options.encrypt === 1,
        backupArea: updates.options.backupArea || 'all',
        password: updates.options.password // Keep password as-is if present
      };
      Logger.log('Storing schedule options: skipRrd=' + optionsToStore.skipRrd + ' (type: ' + typeof optionsToStore.skipRrd + ')');
      sheet.getRange(rowIndex, 4).setValue(JSON.stringify(optionsToStore));
    }
    
    if (updates.enabled !== undefined) {
      sheet.getRange(rowIndex, 5).setValue(updates.enabled ? 'true' : 'false');
    }
    
    if (updates.notes !== undefined) {
      sheet.getRange(rowIndex, 9).setValue(updates.notes);
    }
    
    return { success: true, message: 'Schedule updated' };
  },
  
  /**
   * Delete a scheduled backup
   * 
   * @param {string} scheduleId - Schedule ID
   * @returns {Object} Result
   */
  deleteScheduledBackup: function(scheduleId) {
    const existing = this.getScheduledBackup(scheduleId);
    if (!existing) {
      return { success: false, error: 'Schedule not found: ' + scheduleId };
    }
    
    const sheet = this.getScheduledBackupsSheet();
    sheet.deleteRow(existing.rowIndex);
    
    SheetsDb.addLog('System', 'delete_scheduled_backup', 'success', 
      'Deleted schedule: ' + scheduleId);
    
    return { success: true, message: 'Schedule deleted' };
  },
  
  /**
   * Run a specific scheduled backup immediately (Run Now)
   * 
   * @param {string} scheduleId - Schedule ID to run
   * @returns {Object} Backup result
   */
  runScheduledBackupNow: function(scheduleId) {
    const schedule = this.getScheduledBackup(scheduleId);
    if (!schedule) {
      return { success: false, error: 'Schedule not found: ' + scheduleId };
    }
    
    if (!schedule.enabled) {
      return { success: false, error: 'Schedule is disabled' };
    }
    
    Logger.log('Running scheduled backup now: ' + schedule.id);
    const now = new Date();
    
    try {
      let backupResult;
      
      // Use schedule.options (may be empty {} which defaults to including RRD data)
      if (schedule.firewallId === 'all') {
        backupResult = this.backupAllFirewalls(schedule.options);
      } else {
        backupResult = this.backupFirewall(schedule.firewallId, schedule.options);
      }
      
      // Update last run and next run (if not a "NOW" schedule)
      const sheet = this.getScheduledBackupsSheet();
      sheet.getRange(schedule.rowIndex, 6).setValue(now.toISOString());
      
      // Only update next run if it's not a "NOW" schedule
      if (schedule.schedule !== 'NOW') {
        sheet.getRange(schedule.rowIndex, 7).setValue(this.getNextRunTime(schedule.schedule));
      }
      
      return {
        success: backupResult.success,
        scheduleId: schedule.id,
        firewallId: schedule.firewallId,
        result: backupResult
      };
      
    } catch (error) {
      return {
        success: false,
        scheduleId: schedule.id,
        error: error.message
      };
    }
  },
  
  /**
   * Run due scheduled backups
   * Called by time-based trigger
   * 
   * @returns {Object} Results
   */
  runScheduledBackups: function() {
    const schedules = this.getScheduledBackups();
    const now = new Date();
    const results = [];
    
    schedules.forEach(schedule => {
      if (!schedule.enabled) return;
      
      // Skip "NOW" schedules - they are run manually only
      if (schedule.schedule === 'NOW') return;
      
      // Check if it's time to run
      const nextRun = schedule.nextRun ? new Date(schedule.nextRun) : null;
      if (!nextRun || now < nextRun) return;
      
      Logger.log('Running scheduled backup: ' + schedule.id);
      
      try {
        let backupResult;
        
        // Use schedule.options (may be empty {} which defaults to including RRD data)
        // If schedule.options.skipRrd is not explicitly set to true, RRD data will be included
        if (schedule.firewallId === 'all') {
          backupResult = this.backupAllFirewalls(schedule.options);
        } else {
          backupResult = this.backupFirewall(schedule.firewallId, schedule.options);
        }
        
        // Update last run and next run
        const sheet = this.getScheduledBackupsSheet();
        sheet.getRange(schedule.rowIndex, 6).setValue(now.toISOString());
        sheet.getRange(schedule.rowIndex, 7).setValue(this.getNextRunTime(schedule.schedule));
        
        results.push({
          scheduleId: schedule.id,
          firewallId: schedule.firewallId,
          success: backupResult.success,
          result: backupResult
        });
        
      } catch (error) {
        results.push({
          scheduleId: schedule.id,
          firewallId: schedule.firewallId,
          success: false,
          error: error.message
        });
      }
    });
    
    return {
      success: true,
      schedulesProcessed: results.length,
      results: results
    };
  },
  
  /**
   * Validate a cron expression (basic validation)
   * Format: minute hour day-of-month month day-of-week
   * Also accepts 'NOW' for immediate execution
   * 
   * @param {string} cron - Cron expression or 'NOW'
   * @returns {boolean} Whether valid
   */
  isValidCron: function(cron) {
    if (!cron || typeof cron !== 'string') return false;
    
    // "NOW" is a valid schedule option
    if (cron === 'NOW') return true;
    
    const parts = cron.trim().split(/\s+/);
    if (parts.length !== 5) return false;
    
    // Basic pattern validation for each field
    const patterns = [
      /^(\*|[0-5]?\d)(\/\d+)?$|^(\*|[0-5]?\d)-[0-5]?\d$/,  // minute (0-59)
      /^(\*|[01]?\d|2[0-3])(\/\d+)?$|^(\*|[01]?\d|2[0-3])-([01]?\d|2[0-3])$/,  // hour (0-23)
      /^(\*|[1-9]|[12]\d|3[01])(\/\d+)?$/,  // day of month (1-31)
      /^(\*|[1-9]|1[0-2])(\/\d+)?$/,  // month (1-12)
      /^(\*|[0-6])(\/\d+)?$/  // day of week (0-6)
    ];
    
    for (let i = 0; i < 5; i++) {
      if (!patterns[i].test(parts[i])) {
        return false;
      }
    }
    
    return true;
  },
  
  /**
   * Calculate next run time from cron expression
   * Simplified calculation - returns next hour/day match
   * 
   * @param {string} cron - Cron expression or 'NOW'
   * @returns {string} ISO8601 next run time or null for 'NOW'
   */
  getNextRunTime: function(cron) {
    // "NOW" schedules don't have a next run time
    if (cron === 'NOW') {
      return null;
    }
    const parts = cron.trim().split(/\s+/);
    const [minute, hour, dayOfMonth, month, dayOfWeek] = parts;
    
    const now = new Date();
    const next = new Date(now);
    
    // Set the minute
    if (minute !== '*') {
      const min = parseInt(minute.split('/')[0]) || 0;
      next.setMinutes(min);
      next.setSeconds(0);
      next.setMilliseconds(0);
    }
    
    // Set the hour
    if (hour !== '*') {
      const h = parseInt(hour.split('/')[0]) || 0;
      next.setHours(h);
      
      // If time already passed today, move to next day
      if (next <= now) {
        next.setDate(next.getDate() + 1);
      }
    } else if (hour.includes('/')) {
      // Handle */N pattern
      const interval = parseInt(hour.split('/')[1]) || 1;
      const currentHour = now.getHours();
      const nextHour = Math.ceil((currentHour + 1) / interval) * interval;
      if (nextHour >= 24) {
        next.setDate(next.getDate() + 1);
        next.setHours(0);
      } else {
        next.setHours(nextHour);
      }
    }
    
    // Handle day of week (0 = Sunday)
    if (dayOfWeek !== '*') {
      const targetDay = parseInt(dayOfWeek);
      const currentDay = next.getDay();
      let daysToAdd = targetDay - currentDay;
      if (daysToAdd <= 0) daysToAdd += 7;
      if (daysToAdd === 7 && next > now) daysToAdd = 0;
      next.setDate(next.getDate() + daysToAdd);
    }
    
    // Handle day of month
    if (dayOfMonth !== '*') {
      const targetDate = parseInt(dayOfMonth);
      next.setDate(targetDate);
      if (next <= now) {
        next.setMonth(next.getMonth() + 1);
      }
    }
    
    return next.toISOString();
  }
};

/**
 * Format bytes to human-readable string
 */
function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B';
  
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
}

// ============================================
// Scheduled Backup wrapper functions for google.script.run
// ============================================

/**
 * Create a scheduled backup
 */
function createScheduledBackup(config) {
  return BackupService.createScheduledBackup(config);
}

/**
 * Get all scheduled backups
 */
function getScheduledBackups(firewallId) {
  return BackupService.getScheduledBackups(firewallId);
}

/**
 * Update a scheduled backup
 */
function updateScheduledBackup(scheduleId, updates) {
  return BackupService.updateScheduledBackup(scheduleId, updates);
}

/**
 * Delete a scheduled backup
 */
function deleteScheduledBackup(scheduleId) {
  return BackupService.deleteScheduledBackup(scheduleId);
}

/**
 * Run a scheduled backup immediately (Run Now)
 */
function runScheduledBackupNow(scheduleId) {
  return BackupService.runScheduledBackupNow(scheduleId);
}

/**
 * Run due scheduled backups (called by trigger)
 */
function runScheduledBackups() {
  return BackupService.runScheduledBackups();
}

/**
 * Scheduled backup function - called by time trigger
 * This now uses the new runScheduledBackups() system that checks the ScheduledBackups sheet
 */
function scheduledBackup() {
  try {
    Logger.log('Starting scheduled backup check...');
    
    // Check if scheduled backups are enabled
    const enabled = getConfigValue('scheduled_backup_enabled', 'true');
    if (enabled !== 'true' && enabled !== true) {
      Logger.log('Scheduled backups are disabled');
      return;
    }
    
    // Run scheduled backups (checks ScheduledBackups sheet for due backups)
    const result = BackupService.runScheduledBackups();
    Logger.log('Scheduled backup check complete: ' + JSON.stringify(result));
    
    // Cleanup old backups
    const keepCount = parseInt(getConfigValue('backup_keep_count', 10));
    BackupService.cleanupOldBackups(keepCount);
    
  } catch (error) {
    Logger.log('Scheduled backup error: ' + error.message);
    SheetsDb.addLog('System', 'scheduled_backup', 'error', error.message);
  }
}

/**
 * Manual backup function for testing
 */
function manualBackupAll() {
  const result = BackupService.backupAllFirewalls();
  Logger.log('Manual backup result: ' + JSON.stringify(result));
  return result;
}
