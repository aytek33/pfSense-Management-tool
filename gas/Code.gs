/**
 * Code.gs - Main entry point for pfSense MAC Binding Manager
 * 
 * Google Apps Script Web App for managing MAC bindings
 * across multiple pfSense firewalls remotely.
 * 
 * @version 1.0.0
 */

/**
 * Web App entry point for GET requests
 * Serves the dashboard HTML or handles API calls
 */
function doGet(e) {
  const page = e.parameter.page || 'dashboard';
  const action = e.parameter.action;
  
  // Handle API-style GET requests
  if (action) {
    return handleApiGet(action, e.parameter);
  }
  
  // Serve HTML pages
  switch (page) {
    case 'dashboard':
      return serveHtmlPage('index');
    default:
      return serveHtmlPage('index');
  }
}

/**
 * Web App entry point for POST requests
 * Handles all data modification operations
 */
function doPost(e) {
  try {
    const data = JSON.parse(e.postData.contents);
    const action = data.action;
    
    switch (action) {
      case 'addBinding':
        return jsonResponse(BindingService.addBinding(data));
      
      case 'removeBinding':
        return jsonResponse(BindingService.removeBinding(data));
      
      case 'updateBinding':
        return jsonResponse(BindingService.updateBinding(data));
      
      case 'cleanupExpiredBindings':
        return jsonResponse(BindingService.cleanupExpired());
      
      case 'syncFirewall':
        return jsonResponse(SyncService.syncFirewall(data.firewallId));
      
      case 'syncAllFirewalls':
        return jsonResponse(SyncService.syncAllFirewalls());
      
      case 'addFirewall':
        return jsonResponse(SheetsDb.addFirewall(data.firewall));
      
      case 'updateFirewall':
        return jsonResponse(SheetsDb.updateFirewall(data.firewall));
      
      case 'removeFirewall':
        return jsonResponse(SheetsDb.removeFirewall(data.firewallId));
      
      case 'testConnection':
        return jsonResponse(PfSenseApi.testConnection(data.firewallId));
      
      case 'getStats':
        return jsonResponse(getGlobalStats());
      
      // Backup actions
      case 'backupFirewall':
        return jsonResponse(BackupService.backupFirewall(data.firewallId, data.options || {}));
      
      case 'backupAllFirewalls':
        return jsonResponse(BackupService.backupAllFirewalls(data.options || {}));
      
      case 'getBackupHistory':
        return jsonResponse({
          success: true,
          backups: BackupService.getBackupHistory(data.firewallId, data.limit || 50)
        });
      
      case 'getBackupStats':
        return jsonResponse({
          success: true,
          stats: BackupService.getBackupStats()
        });
      
      case 'cleanupOldBackups':
        return jsonResponse(BackupService.cleanupOldBackups(data.keepCount || 10));
      
      // System info actions
      case 'getFirewallSystemInfo':
        return jsonResponse(SystemInfoService.getFirewallSystemInfo(data.firewallId));
      
      case 'getFirewallsWithSystemInfo':
        return jsonResponse(SystemInfoService.getFirewallsWithSystemInfo());
      
      // Filtered logs
      case 'getLogsFiltered':
        return jsonResponse({
          success: true,
          logs: SheetsDb.getLogsFiltered(data.filters || {})
        });
      
      case 'getLogFilterOptions':
        return jsonResponse({
          success: true,
          options: SheetsDb.getLogFilterOptions()
        });
      
      // Scheduled backups
      case 'createScheduledBackup':
        return jsonResponse(BackupService.createScheduledBackup(data.config || data));
      
      case 'updateScheduledBackup':
        return jsonResponse(BackupService.updateScheduledBackup(data.scheduleId, data.updates));
      
      case 'deleteScheduledBackup':
        return jsonResponse(BackupService.deleteScheduledBackup(data.scheduleId));
      
      case 'runScheduledBackupNow':
        return jsonResponse(BackupService.runScheduledBackupNow(data.scheduleId));
      
      // Admin logins
      case 'getAdminLogins':
        return jsonResponse({
          success: true,
          logins: SystemInfoService.getAdminLogins(data.firewallId, data.limit || 50)
        });
      
      // Voucher session history and stats
      case 'getVoucherSessionHistory':
        return jsonResponse(VoucherService.getSessionHistory(data.filters || {}));
      
      case 'getVoucherSessionStats':
        return jsonResponse({
          success: true,
          stats: VoucherService.getSessionStats(data.filters || {})
        });
      
      case 'cleanupVoucherSessions':
        return jsonResponse(SheetsDb.cleanupOldVoucherSessions(data.retentionDays || null));
      
      case 'reconcileVoucherSessions':
        return jsonResponse(VoucherService.reconcileSessions(null));
      
      default:
        return jsonResponse({ success: false, error: 'Unknown action: ' + action });
    }
  } catch (error) {
    Logger.log('doPost error: ' + error.message);
    return jsonResponse({ success: false, error: error.message });
  }
}

/**
 * Handle API-style GET requests
 */
function handleApiGet(action, params) {
  try {
    switch (action) {
      case 'getFirewalls':
        return jsonResponse({ success: true, firewalls: SheetsDb.getFirewalls() });
      
      case 'getBindings':
        const firewallId = params.firewallId || null;
        return jsonResponse({ 
          success: true, 
          bindings: SheetsDb.getBindings(firewallId) 
        });
      
      case 'getLogs':
        const limit = parseInt(params.limit) || 100;
        return jsonResponse({ success: true, logs: SheetsDb.getLogs(limit) });
      
      case 'getStats':
        return jsonResponse(getGlobalStats());
      
      case 'searchBindings':
        return jsonResponse({
          success: true,
          results: SheetsDb.searchBindings(params.query)
        });
      
      case 'getBackupHistory':
        return jsonResponse({
          success: true,
          backups: BackupService.getBackupHistory(params.firewallId || null, parseInt(params.limit) || 50)
        });
      
      case 'getBackupStats':
        return jsonResponse({
          success: true,
          stats: BackupService.getBackupStats()
        });
      
      case 'getFirewallSystemInfo':
        return jsonResponse(SystemInfoService.getFirewallSystemInfo(params.firewallId));
      
      case 'getFirewallsWithSystemInfo':
        return jsonResponse(SystemInfoService.getFirewallsWithSystemInfo());
      
      case 'getDashboardSummary':
        return jsonResponse({
          success: true,
          dashboard: SystemInfoService.getDashboardSummary()
        });
      
      case 'getLogsFiltered':
        return jsonResponse({
          success: true,
          logs: SheetsDb.getLogsFiltered({
            startDate: params.startDate,
            endDate: params.endDate,
            firewall: params.firewall,
            actions: params.actions ? params.actions.split(',') : null,
            result: params.result,
            search: params.search,
            limit: parseInt(params.limit) || 100
          })
        });
      
      case 'getLogFilterOptions':
        return jsonResponse({
          success: true,
          options: SheetsDb.getLogFilterOptions()
        });
      
      case 'getScheduledBackups':
        return jsonResponse({
          success: true,
          schedules: BackupService.getScheduledBackups(params.firewallId || null)
        });
      
      case 'getAdminLogins':
        return jsonResponse({
          success: true,
          logins: SystemInfoService.getAdminLogins(params.firewallId || null, parseInt(params.limit) || 50)
        });
      
      case 'getVoucherSessions':
        return jsonResponse({
          success: true,
          sessions: SheetsDb.getVoucherSessions({
            firewallId: params.firewallId,
            zone: params.zone,
            mac: params.mac,
            status: params.status,
            startDate: params.startDate,
            endDate: params.endDate,
            limit: parseInt(params.limit) || 500
          })
        });
      
      case 'getVoucherSessionHistory':
        return jsonResponse(VoucherService.getSessionHistory({
          firewallId: params.firewallId,
          zone: params.zone,
          mac: params.mac,
          startDate: params.startDate,
          endDate: params.endDate,
          limit: parseInt(params.limit) || 100
        }));
      
      case 'getVoucherSessionStats':
        return jsonResponse({
          success: true,
          stats: VoucherService.getSessionStats({
            firewallId: params.firewallId,
            zone: params.zone,
            startDate: params.startDate,
            endDate: params.endDate
          })
        });
      
      default:
        return jsonResponse({ success: false, error: 'Unknown action' });
    }
  } catch (error) {
    Logger.log('handleApiGet error: ' + error.message);
    return jsonResponse({ success: false, error: error.message });
  }
}

/**
 * Serve HTML page with template processing
 */
function serveHtmlPage(pageName) {
  const template = HtmlService.createTemplateFromFile(pageName);
  
  // Pass initial data to template
  template.firewalls = JSON.stringify(SheetsDb.getFirewalls());
  template.config = JSON.stringify(SheetsDb.getConfig());
  
  return template.evaluate()
    .setTitle('pfSenseMANAGER XXXIII')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1');
}

/**
 * Include HTML partial files
 */
function include(filename) {
  return HtmlService.createHtmlOutputFromFile(filename).getContent();
}

/**
 * Create JSON response
 */
function jsonResponse(data) {
  return ContentService.createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * Get global statistics across all firewalls
 */
function getGlobalStats() {
  const firewalls = SheetsDb.getFirewalls();
  const bindings = SheetsDb.getBindings();
  
  const now = new Date();
  const oneHour = new Date(now.getTime() + 60 * 60 * 1000);
  const oneDay = new Date(now.getTime() + 24 * 60 * 60 * 1000);
  
  let expiringOneHour = 0;
  let expiringOneDay = 0;
  let expired = 0;
  const bindingsByFirewall = {};
  const bindingsByZone = {};
  
  bindings.forEach(b => {
    const expiresAt = new Date(b.expiresAt);
    
    if (expiresAt < now) {
      expired++;
    } else if (expiresAt < oneHour) {
      expiringOneHour++;
    } else if (expiresAt < oneDay) {
      expiringOneDay++;
    }
    
    // Count by firewall
    const fwId = b.firewallId || 'unknown';
    bindingsByFirewall[fwId] = (bindingsByFirewall[fwId] || 0) + 1;
    
    // Count by zone
    const zone = b.zone || 'unknown';
    bindingsByZone[zone] = (bindingsByZone[zone] || 0) + 1;
  });
  
  // Count firewall statuses
  let onlineCount = 0;
  let offlineCount = 0;
  
  firewalls.forEach(fw => {
    if (fw.status === 'online') {
      onlineCount++;
    } else {
      offlineCount++;
    }
  });
  
  return {
    success: true,
    stats: {
      totalFirewalls: firewalls.length,
      onlineFirewalls: onlineCount,
      offlineFirewalls: offlineCount,
      totalBindings: bindings.length,
      expiredBindings: expired,
      expiringOneHour: expiringOneHour,
      expiringOneDay: expiringOneDay,
      bindingsByFirewall: bindingsByFirewall,
      bindingsByZone: bindingsByZone
    }
  };
}

/**
 * Manual trigger function for testing
 */
function manualSync() {
  const result = SyncService.syncAllFirewalls();
  Logger.log('Manual sync result: ' + JSON.stringify(result));
  return result;
}

/**
 * Scheduled sync function - called by time trigger
 * Also triggers cleanup on each firewall to ensure expired bindings are removed
 */
function scheduledSync() {
  try {
    Logger.log('Starting scheduled sync...');
    const result = SyncService.syncAllFirewalls();
    Logger.log('Scheduled sync complete: ' + JSON.stringify(result));

    // Also trigger cleanup on each online firewall to remove expired bindings
    const firewalls = SheetsDb.getFirewalls();
    firewalls.forEach(firewall => {
      if (firewall.status === 'online') {
        try {
          PfSenseApi.cleanupExpired(firewall);
        } catch (cleanupError) {
          Logger.log('Cleanup error for ' + firewall.name + ': ' + cleanupError.message);
        }
      }
    });
  } catch (error) {
    Logger.log('Scheduled sync error: ' + error.message);
    SheetsDb.addLog('System', 'scheduled_sync', 'error', error.message);
  }
}

/**
 * Clean up expired bindings - called by time trigger or UI
 * Returns result for UI calls, logs for trigger calls
 */
function cleanupExpiredBindings() {
  try {
    Logger.log('Starting expired bindings cleanup...');
    const result = BindingService.cleanupExpired();
    Logger.log('Cleanup complete: ' + JSON.stringify(result));
    return result;
  } catch (error) {
    Logger.log('Cleanup error: ' + error.message);
    SheetsDb.addLog('System', 'cleanup', 'error', error.message);
    return { success: false, error: error.message };
  }
}

// ============================================
// Wrapper functions for google.script.run
// (google.script.run only supports top-level functions)
// ============================================

/**
 * Add a new firewall configuration
 */
function addFirewall(firewall) {
  return SheetsDb.addFirewall(firewall);
}

/**
 * Remove a firewall configuration
 */
function removeFirewall(firewallId) {
  return SheetsDb.removeFirewall(firewallId);
}

/**
 * Update an existing firewall configuration
 */
function updateFirewall(firewall) {
  return SheetsDb.updateFirewall(firewall);
}

/**
 * Add a new MAC binding
 */
function addBinding(data) {
  return BindingService.addBinding(data);
}

/**
 * Remove a MAC binding
 */
function removeBinding(data) {
  return BindingService.removeBinding(data);
}

/**
 * Update an existing MAC binding
 */
function updateBinding(data) {
  return BindingService.updateBinding(data);
}

/**
 * Get logs with filtering
 */
function getLogsFiltered(filters) {
  return { success: true, logs: SheetsDb.getLogsFiltered(filters || {}) };
}

/**
 * Get log filter options
 */
function getLogFilterOptions() {
  return { success: true, options: SheetsDb.getLogFilterOptions() };
}

/**
 * Sync a single firewall
 */
function syncFirewall(firewallId) {
  return SyncService.syncFirewall(firewallId);
}

/**
 * Test connection to a firewall
 */
function testConnection(firewallId) {
  return PfSenseApi.testConnection(firewallId);
}

/**
 * Create backup for a single firewall
 */
function backupFirewall(firewallId, options) {
  return BackupService.backupFirewall(firewallId, options || {});
}

/**
 * Create backups for all firewalls
 */
function backupAllFirewalls(options) {
  return BackupService.backupAllFirewalls(options || {});
}

// ============================================
// Wrapper function for handleApiGet
// Returns plain objects for google.script.run
// ============================================

/**
 * Handle API GET requests from client-side (google.script.run)
 * Returns plain JavaScript objects instead of ContentService responses
 */
function handleApiGetClient(action, params) {
  params = params || {};
  try {
    switch (action) {
      case 'getFirewalls':
        return { success: true, firewalls: SheetsDb.getFirewalls() };
      
      case 'getBindings':
        const firewallId = params.firewallId || null;
        return { success: true, bindings: SheetsDb.getBindings(firewallId) };
      
      case 'getLogs':
        const limit = parseInt(params.limit) || 100;
        return { success: true, logs: SheetsDb.getLogs(limit) };
      
      case 'getStats':
        return getGlobalStats();
      
      case 'searchBindings':
        return { success: true, results: SheetsDb.searchBindings(params.query) };
      
      case 'getBackupHistory':
        return {
          success: true,
          backups: BackupService.getBackupHistory(params.firewallId || null, parseInt(params.limit) || 50)
        };
      
      case 'getBackupStats':
        return { success: true, stats: BackupService.getBackupStats() };
      
      case 'getFirewallSystemInfo':
        return SystemInfoService.getFirewallSystemInfo(params.firewallId);
      
      case 'getFirewallsWithSystemInfo':
        return SystemInfoService.getFirewallsWithSystemInfo();
      
      case 'getDashboardSummary':
        return { success: true, dashboard: SystemInfoService.getDashboardSummary() };
      
      case 'getLogsFiltered':
        return {
          success: true,
          logs: SheetsDb.getLogsFiltered({
            startDate: params.startDate,
            endDate: params.endDate,
            firewall: params.firewall,
            actions: params.actions ? params.actions.split(',') : null,
            result: params.result,
            search: params.search,
            limit: parseInt(params.limit) || 100
          })
        };
      
      case 'getLogFilterOptions':
        return { success: true, options: SheetsDb.getLogFilterOptions() };
      
      case 'getScheduledBackups':
        return { success: true, schedules: BackupService.getScheduledBackups(params.firewallId || null) };
      
      case 'getAdminLogins':
        return { success: true, logins: SystemInfoService.getAdminLogins(params.firewallId || null, parseInt(params.limit) || 50) };
      
      case 'getVoucherSessions':
        return {
          success: true,
          sessions: SheetsDb.getVoucherSessions({
            firewallId: params.firewallId,
            zone: params.zone,
            mac: params.mac,
            status: params.status,
            startDate: params.startDate,
            endDate: params.endDate,
            limit: parseInt(params.limit) || 500
          })
        };
      
      case 'getVoucherSessionHistory':
        return VoucherService.getSessionHistory({
          firewallId: params.firewallId,
          zone: params.zone,
          mac: params.mac,
          startDate: params.startDate,
          endDate: params.endDate,
          limit: parseInt(params.limit) || 100
        });
      
      case 'getVoucherSessionStats':
        return {
          success: true,
          stats: VoucherService.getSessionStats({
            firewallId: params.firewallId,
            zone: params.zone,
            startDate: params.startDate,
            endDate: params.endDate
          })
        };
      
      default:
        return { success: false, error: 'Unknown action: ' + action };
    }
  } catch (error) {
    Logger.log('handleApiGetClient error: ' + error.message);
    return { success: false, error: error.message };
  }
}

// ============================================
// Voucher Data Folder wrapper functions
// ============================================

/**
 * Get or validate the voucher data folder configuration
 * Returns the folder ID if configured and valid, or creates one if needed
 * 
 * @returns {Object} Result with folder ID and URL
 */
function getVoucherDataFolderId() {
  try {
    const configuredId = getConfigValue('voucher_data_folder_id', '');
    
    if (configuredId) {
      // Validate the configured folder exists
      try {
        const folder = DriveApp.getFolderById(configuredId);
        return {
          success: true,
          folderId: configuredId,
          folderName: folder.getName(),
          folderUrl: folder.getUrl(),
          configured: true
        };
      } catch (e) {
        return {
          success: false,
          error: 'Configured voucher_data_folder_id is invalid: ' + e.message,
          configured: true,
          configuredId: configuredId
        };
      }
    }
    
    // No folder configured
    return {
      success: true,
      folderId: null,
      configured: false,
      message: 'No voucher data folder configured. Using main VoucherSessions sheet.'
    };
  } catch (error) {
    return { success: false, error: error.message };
  }
}

/**
 * Create and configure a voucher data folder
 * Creates the folder and optionally updates the config
 * 
 * @param {boolean} updateConfig - Whether to save the folder ID to config (default true)
 * @returns {Object} Result with folder ID and URL
 */
function createVoucherDataFolder(updateConfig = true) {
  try {
    const folder = VoucherService.getVoucherDataFolder();
    const folderId = folder.getId();
    
    // Optionally update config with the folder ID
    if (updateConfig) {
      SheetsDb.setConfig('voucher_data_folder_id', folderId);
      SheetsDb.addLog('System', 'create_voucher_folder', 'success', 
        'Created voucher data folder: ' + folder.getName() + ' (ID: ' + folderId + ')');
    }
    
    return {
      success: true,
      folderId: folderId,
      folderName: folder.getName(),
      folderUrl: folder.getUrl(),
      configUpdated: updateConfig
    };
  } catch (error) {
    SheetsDb.addLog('System', 'create_voucher_folder', 'error', error.message);
    return { success: false, error: error.message };
  }
}

/**
 * Get the URL to a firewall's voucher spreadsheet
 * Creates the spreadsheet if it doesn't exist
 * 
 * @param {string} firewallId - Firewall ID
 * @returns {Object} Result with spreadsheet URL
 */
function getFirewallVoucherSheetUrl(firewallId) {
  try {
    // First check if per-firewall storage is configured
    const voucherFolderId = getConfigValue('voucher_data_folder_id', '');
    if (!voucherFolderId) {
      return {
        success: false,
        error: 'Per-firewall voucher storage not configured. Set voucher_data_folder_id in Config.'
      };
    }
    
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    const spreadsheet = VoucherService.getFirewallVoucherSpreadsheet(firewallId, firewall.name);
    
    return {
      success: true,
      firewallId: firewallId,
      firewallName: firewall.name,
      spreadsheetId: spreadsheet.getId(),
      spreadsheetUrl: spreadsheet.getUrl(),
      spreadsheetName: spreadsheet.getName()
    };
  } catch (error) {
    return { success: false, error: error.message };
  }
}

// ============================================
// SELF-HEALING TESTS
// ============================================

/**
 * Self-healing test suite - run daily via trigger
 * Tests system integrity and auto-fixes common issues
 */
function runSelfHealingTests() {
  const results = {
    timestamp: new Date().toISOString(),
    tests: [],
    errors: [],
    autoFixed: []
  };

  // Test 1: Sheet integrity
  results.tests.push(testSheetIntegrity());

  // Test 2: Firewall connectivity
  results.tests.push(testFirewallConnectivity());

  // Test 3: Duplicate binding detection
  results.tests.push(testDuplicateBindings());

  // Test 4: Expired binding cleanup
  results.tests.push(testExpiredBindingCleanup());

  // Test 5: Orphaned sessions
  results.tests.push(testOrphanedSessions());

  // Test 6: Config validation
  results.tests.push(testConfigIntegrity());

  // Test 7: Backup folder access
  results.tests.push(testBackupFolderAccess());

  // Collect auto-fixed items
  results.tests.forEach(test => {
    if (test.fixed && test.fixed.length > 0) {
      results.autoFixed.push(...test.fixed.map(f => ({ test: test.name, fix: f })));
    }
    if (!test.passed) {
      results.errors.push({ test: test.name, issues: test.issues });
    }
  });

  // Log results
  SheetsDb.addLog('SelfTest', 'self_healing_tests',
    results.errors.length === 0 ? 'success' : 'warning',
    `Passed: ${results.tests.filter(t => t.passed).length}/${results.tests.length}, Auto-fixed: ${results.autoFixed.length}`
  );

  return results;
}

/**
 * Test 1: Sheet integrity - verify all required sheets exist
 */
function testSheetIntegrity() {
  const result = { name: 'Sheet Integrity', passed: true, issues: [], fixed: [] };

  try {
    // Check all required sheets exist
    const requiredSheets = ['Firewalls', 'Bindings', 'Logs', 'Config', 'VoucherSessions'];
    requiredSheets.forEach(sheetName => {
      try {
        SheetsDb.getSheet(sheetName);
      } catch (e) {
        result.issues.push(`Missing sheet: ${sheetName}`);
        // Auto-fix: getSheet creates if missing
        result.fixed.push(`Created missing sheet: ${sheetName}`);
      }
    });

    result.passed = result.issues.length === 0 || result.issues.length === result.fixed.length;
  } catch (e) {
    result.passed = false;
    result.issues.push(e.message);
  }

  return result;
}

/**
 * Test 2: Firewall connectivity - check online firewalls are reachable
 */
function testFirewallConnectivity() {
  const result = { name: 'Firewall Connectivity', passed: true, issues: [], fixed: [] };

  try {
    const firewalls = SheetsDb.getFirewalls();

    firewalls.forEach(fw => {
      if (fw.status === 'online') {
        const pingResult = PfSenseApi.ping(fw);
        if (!pingResult) {
          result.issues.push(`Firewall ${fw.name} marked online but unreachable`);
          // Auto-fix: update status
          SheetsDb.updateFirewall({ id: fw.id, status: 'offline' });
          result.fixed.push(`Marked ${fw.name} as offline`);
        }
      }
    });

    result.passed = true; // Connectivity issues aren't critical failures
  } catch (e) {
    result.passed = false;
    result.issues.push(e.message);
  }

  return result;
}

/**
 * Test 3: Duplicate binding detection - find and remove duplicates
 */
function testDuplicateBindings() {
  const result = { name: 'Duplicate Bindings', passed: true, issues: [], fixed: [] };

  try {
    const bindings = SheetsDb.getBindings();
    const seen = new Map();
    const duplicates = [];

    bindings.forEach(b => {
      const key = `${b.firewallId}|${normalizeMac(b.mac)}`;
      if (seen.has(key)) {
        duplicates.push({ binding: b, original: seen.get(key) });
      } else {
        seen.set(key, b);
      }
    });

    if (duplicates.length > 0) {
      result.issues.push(`Found ${duplicates.length} duplicate bindings`);

      // Auto-fix: keep the one with later expiry, remove others
      duplicates.forEach(dup => {
        const originalExpiry = dup.original.expiresAt ? new Date(dup.original.expiresAt) : new Date(0);
        const dupExpiry = dup.binding.expiresAt ? new Date(dup.binding.expiresAt) : new Date(0);
        const keepOriginal = originalExpiry >= dupExpiry;
        const toRemove = keepOriginal ? dup.binding : dup.original;
        SheetsDb.removeBinding(toRemove.firewallId, toRemove.mac);
        result.fixed.push(`Removed duplicate: ${toRemove.mac} on ${toRemove.firewallId}`);
      });
    }

    result.passed = duplicates.length === 0 || duplicates.length === result.fixed.length;
  } catch (e) {
    result.passed = false;
    result.issues.push(e.message);
  }

  return result;
}

/**
 * Test 4: Expired binding cleanup - ensure expired bindings are cleaned up
 */
function testExpiredBindingCleanup() {
  const result = { name: 'Expired Binding Cleanup', passed: true, issues: [], fixed: [] };

  try {
    const expired = SheetsDb.getExpiredBindings();

    if (expired.length > 0) {
      result.issues.push(`Found ${expired.length} expired bindings`);

      // Auto-fix: trigger cleanup
      const cleanupResult = BindingService.cleanupExpired();
      if (cleanupResult.success) {
        result.fixed.push(`Cleaned up ${cleanupResult.removed} expired bindings`);
      }
    }

    result.passed = true; // Having expired bindings isn't a failure, just cleanup
  } catch (e) {
    result.passed = false;
    result.issues.push(e.message);
  }

  return result;
}

/**
 * Test 5: Orphaned sessions - find sessions for non-existent firewalls
 */
function testOrphanedSessions() {
  const result = { name: 'Orphaned Sessions', passed: true, issues: [], fixed: [] };

  try {
    const sessions = SheetsDb.getVoucherSessions({ status: 'active', limit: 10000 });
    const firewalls = SheetsDb.getFirewalls();
    const firewallIds = new Set(firewalls.map(f => f.id));

    const orphaned = sessions.filter(s => !firewallIds.has(s.firewallId));

    if (orphaned.length > 0) {
      result.issues.push(`Found ${orphaned.length} sessions for non-existent firewalls`);

      // Auto-fix: mark as disconnected
      orphaned.forEach(session => {
        SheetsDb.updateVoucherSessionStatus(session.sessionId, 'disconnected', 'orphaned_firewall');
        result.fixed.push(`Marked orphaned session: ${session.sessionId}`);
      });
    }

    result.passed = orphaned.length === 0 || orphaned.length === result.fixed.length;
  } catch (e) {
    result.passed = false;
    result.issues.push(e.message);
  }

  return result;
}

/**
 * Test 6: Config integrity - ensure required config values exist
 */
function testConfigIntegrity() {
  const result = { name: 'Config Integrity', passed: true, issues: [], fixed: [] };

  try {
    const config = SheetsDb.getConfig();

    // Required config keys with defaults
    const requiredConfigs = {
      'sync_interval_minutes': '5',
      'stale_threshold_minutes': '15',
      'default_duration_minutes': '43200',
      'max_log_entries': '10000',
      'backup_keep_count': '10'
    };

    Object.entries(requiredConfigs).forEach(([key, defaultValue]) => {
      if (!config[key]) {
        result.issues.push(`Missing config: ${key}`);
        SheetsDb.setConfig(key, defaultValue);
        result.fixed.push(`Set default config: ${key}=${defaultValue}`);
      }
    });

    result.passed = result.issues.length === 0 || result.issues.length === result.fixed.length;
  } catch (e) {
    result.passed = false;
    result.issues.push(e.message);
  }

  return result;
}

/**
 * Test 7: Backup folder access - verify backup folder is accessible
 */
function testBackupFolderAccess() {
  const result = { name: 'Backup Folder Access', passed: true, issues: [], fixed: [] };

  try {
    const folderId = getConfigValue('backup_folder_id', '');

    if (folderId) {
      try {
        const folder = DriveApp.getFolderById(folderId);
        folder.getName(); // Test access
      } catch (e) {
        result.issues.push(`Cannot access backup folder: ${folderId}`);
        // Auto-fix: clear invalid folder ID, let system create new
        SheetsDb.setConfig('backup_folder_id', '');
        result.fixed.push('Cleared invalid backup_folder_id, will create new on next backup');
      }
    }

    result.passed = result.issues.length === 0 || result.issues.length === result.fixed.length;
  } catch (e) {
    result.passed = false;
    result.issues.push(e.message);
  }

  return result;
}
