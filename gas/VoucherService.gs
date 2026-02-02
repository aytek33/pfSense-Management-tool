/**
 * VoucherService.gs - Captive Portal Voucher/Session Management
 * 
 * Handles fetching active captive portal sessions (voucher users)
 * from pfSense firewalls and provides disconnect functionality.
 */

/**
 * VoucherService namespace
 */
const VoucherService = {
  
  // Default voucher data folder name in Google Drive
  VOUCHER_DATA_FOLDER_NAME: 'pfSense Voucher Data',
  
  // =========================================================================
  // PER-FIREWALL VOUCHER DATA FOLDER METHODS
  // =========================================================================
  
  /**
   * Get or create the voucher data folder in Google Drive
   * 
   * Priority:
   * 1. Use voucher_data_folder_id from Config (specific folder ID - recommended)
   * 2. Fall back to searching by folder name (creates in current user's Drive)
   * 
   * @returns {GoogleAppsScript.Drive.Folder} The voucher data folder
   */
  getVoucherDataFolder: function() {
    // First, check if a specific folder ID is configured (recommended approach)
    const folderId = getConfigValue('voucher_data_folder_id', '');
    if (folderId) {
      try {
        const folder = DriveApp.getFolderById(folderId);
        Logger.log('Using configured voucher data folder: ' + folder.getName());
        return folder;
      } catch (e) {
        Logger.log('Warning: Configured voucher_data_folder_id is invalid: ' + folderId + ' - ' + e.message);
        // Fall through to search by name
      }
    }
    
    // Fall back to searching by name (creates in script owner's Drive)
    const folderName = getConfigValue('voucher_data_folder_name', this.VOUCHER_DATA_FOLDER_NAME);
    
    // Search for existing folder
    const folders = DriveApp.getFoldersByName(folderName);
    if (folders.hasNext()) {
      return folders.next();
    }
    
    // Create new folder
    const folder = DriveApp.createFolder(folderName);
    Logger.log('Created voucher data folder: ' + folderName + ' (ID: ' + folder.getId() + ')');
    Logger.log('TIP: Add "voucher_data_folder_id" = "' + folder.getId() + '" to your Config sheet for consistent voucher data location');
    return folder;
  },
  
  /**
   * Get or create a per-firewall voucher spreadsheet
   * 
   * @param {string} firewallId - Firewall ID
   * @param {string} firewallName - Firewall name
   * @returns {GoogleAppsScript.Spreadsheet.Spreadsheet} The firewall's voucher spreadsheet
   */
  getFirewallVoucherSpreadsheet: function(firewallId, firewallName) {
    const voucherFolder = this.getVoucherDataFolder();
    const spreadsheetName = `${firewallName} (${firewallId}) Vouchers`;
    
    // Search for existing spreadsheet in folder
    const files = voucherFolder.getFilesByName(spreadsheetName);
    if (files.hasNext()) {
      const file = files.next();
      return SpreadsheetApp.openById(file.getId());
    }
    
    // Create new spreadsheet
    const spreadsheet = SpreadsheetApp.create(spreadsheetName);
    const file = DriveApp.getFileById(spreadsheet.getId());
    
    // Move to voucher data folder
    file.moveTo(voucherFolder);
    
    // Initialize with proper structure
    this.initializeVoucherSpreadsheet(spreadsheet, firewallId, firewallName);
    
    Logger.log('Created voucher spreadsheet for firewall ' + firewallName + ': ' + spreadsheet.getUrl());
    return spreadsheet;
  },
  
  /**
   * Initialize a voucher spreadsheet with proper sheet structure
   * 
   * @param {GoogleAppsScript.Spreadsheet.Spreadsheet} spreadsheet - The spreadsheet to initialize
   * @param {string} firewallId - Firewall ID
   * @param {string} firewallName - Firewall name
   */
  initializeVoucherSpreadsheet: function(spreadsheet, firewallId, firewallName) {
    // Get or create VoucherSessions sheet
    let sheet = spreadsheet.getSheetByName('VoucherSessions');
    if (!sheet) {
      // Rename first sheet or create new one
      const sheets = spreadsheet.getSheets();
      if (sheets.length > 0 && sheets[0].getName() === 'Sheet1') {
        sheet = sheets[0];
        sheet.setName('VoucherSessions');
      } else {
        sheet = spreadsheet.insertSheet('VoucherSessions');
      }
    }
    
    // Check if headers exist
    const firstCell = sheet.getRange(1, 1).getValue();
    if (!firstCell) {
      // Add headers (same schema as main VoucherSessions sheet, minus Firewall_ID/Firewall_Name since it's per-firewall)
      sheet.appendRow([
        'Session_ID', 'Zone', 'MAC', 'IP', 'Username',
        'Session_Start', 'Session_End', 'Duration_Seconds', 'Remaining_Seconds',
        'Bytes_In', 'Bytes_Out', 'Status', 'Disconnect_Reason',
        'Last_Updated', 'Notes'
      ]);
      
      // Format headers (teal background like main VoucherSessions)
      sheet.getRange(1, 1, 1, 15).setFontWeight('bold').setBackground('#009688').setFontColor('white');
      sheet.setFrozenRows(1);
      
      // Add metadata sheet with firewall info
      let metaSheet = spreadsheet.getSheetByName('Metadata');
      if (!metaSheet) {
        metaSheet = spreadsheet.insertSheet('Metadata');
      }
      metaSheet.clear();
      metaSheet.appendRow(['Key', 'Value']);
      metaSheet.appendRow(['Firewall_ID', firewallId]);
      metaSheet.appendRow(['Firewall_Name', firewallName]);
      metaSheet.appendRow(['Created_At', new Date().toISOString()]);
      metaSheet.getRange(1, 1, 1, 2).setFontWeight('bold').setBackground('#607d8b').setFontColor('white');
    }
  },
  
  // =========================================================================
  // ACTIVE VOUCHER METHODS
  // =========================================================================
  
  /**
   * Get active voucher sessions from a specific firewall
   * 
   * @param {string} firewallId - Firewall ID
   * @param {string} zone - Optional zone filter
   * @returns {Object} Result with sessions array
   */
  getActiveVouchers: function(firewallId, zone = null) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    const payload = zone ? { zone: zone } : {};
    const result = PfSenseApi.request(firewall, 'vouchers', 'GET', payload);
    
    if (!result.success) {
      SheetsDb.addLog(firewall.name, 'get_vouchers', 'error', result.error);
      return {
        success: false,
        error: result.error,
        firewallId: firewallId
      };
    }
    
    // Enrich sessions with firewall info
    const sessions = (result.data.sessions || []).map(session => ({
      ...session,
      firewallId: firewallId,
      firewallName: firewall.name
    }));
    
    return {
      success: true,
      firewallId: firewallId,
      firewallName: firewall.name,
      count: sessions.length,
      sessions: sessions,
      zones: result.data.zones || []
    };
  },
  
  /**
   * Get active voucher sessions from all firewalls
   * 
   * @param {string} zone - Optional zone filter
   * @returns {Object} Aggregated result with all sessions
   */
  getAllActiveVouchers: function(zone = null) {
    const firewalls = SheetsDb.getFirewalls();
    
    if (firewalls.length === 0) {
      return { 
        success: true, 
        message: 'No firewalls configured', 
        sessions: [],
        count: 0
      };
    }
    
    const allSessions = [];
    const results = [];
    let successCount = 0;
    let errorCount = 0;
    
    firewalls.forEach(firewall => {
      // Skip offline firewalls
      if (firewall.status === 'offline') {
        results.push({
          firewallId: firewall.id,
          firewallName: firewall.name,
          success: false,
          error: 'Firewall is offline'
        });
        errorCount++;
        return;
      }
      
      try {
        const result = this.getActiveVouchers(firewall.id, zone);
        results.push(result);
        
        if (result.success) {
          successCount++;
          allSessions.push(...result.sessions);
        } else {
          errorCount++;
        }
      } catch (error) {
        results.push({
          firewallId: firewall.id,
          firewallName: firewall.name,
          success: false,
          error: error.message
        });
        errorCount++;
      }
    });
    
    // Sort all sessions by remaining time (ascending - expiring soon first)
    allSessions.sort((a, b) => a.remaining_seconds - b.remaining_seconds);
    
    // Calculate statistics
    const stats = this.calculateStats(allSessions);
    
    // Sync sessions to sheet for persistent storage
    // SKIP sync if too many sessions to avoid timeout (Google Apps Script has 6 min limit)
    const SYNC_THRESHOLD = 200; // Only sync if under this many sessions
    let syncResult = { success: true, added: 0, updated: 0, expired: 0, skipped: false };
    
    // Check if per-firewall storage is configured
    const voucherFolderId = getConfigValue('voucher_data_folder_id', '');
    const usePerFirewallStorage = !!voucherFolderId;
    
    if (allSessions.length > SYNC_THRESHOLD) {
      // Skip sync for large datasets - can be run manually later
      syncResult = { 
        success: true, 
        skipped: true, 
        reason: 'Too many sessions (' + allSessions.length + '). Sync skipped to avoid timeout. Run manual sync later.',
        added: 0, 
        updated: 0, 
        expired: 0 
      };
      SheetsDb.addLog('System', 'voucher_sync', 'info', 
        'Skipped sync: ' + allSessions.length + ' sessions exceeds threshold of ' + SYNC_THRESHOLD);
    } else {
      try {
        if (usePerFirewallStorage) {
          // Sync to per-firewall spreadsheets in Google Drive folder
          syncResult = this.syncSessionsToFirewallSheets(allSessions);
        } else {
          // Fallback: sync to main VoucherSessions sheet
          syncResult = this.syncSessionsToSheet(allSessions);
        }
      } catch (syncError) {
        // Log error but don't fail the main request
        SheetsDb.addLog('System', 'voucher_sync', 'warning', 
          'Sync failed but continuing: ' + syncError.message);
      }
    }
    
    return {
      success: errorCount === 0,
      totalFirewalls: firewalls.length,
      successCount: successCount,
      errorCount: errorCount,
      count: allSessions.length,
      sessions: allSessions,
      stats: stats,
      results: results,
      sync: syncResult
    };
  },
  
  /**
   * Calculate voucher session statistics
   * 
   * @param {Array} sessions - Array of session objects
   * @returns {Object} Statistics
   */
  calculateStats: function(sessions) {
    const stats = {
      total: sessions.length,
      byZone: {},
      byFirewall: {},
      expiringSoon: 0,      // Critical: < 10 minutes (< 600 seconds)
      expiringMedium: 0,    // Expiring (tomorrow): 1 day to 3 days (86400 to 259200 seconds) OR Expiring (today-10-30 min): 600 to 1800 seconds
      healthy: 0            // Healthy: > 3 days (>= 259200 seconds)
    };
    
    sessions.forEach(session => {
      // By zone
      const zone = session.zone || 'unknown';
      if (!stats.byZone[zone]) {
        stats.byZone[zone] = 0;
      }
      stats.byZone[zone]++;
      
      // By firewall
      const fwId = session.firewallId || 'unknown';
      if (!stats.byFirewall[fwId]) {
        stats.byFirewall[fwId] = {
          name: session.firewallName || fwId,
          count: 0
        };
      }
      stats.byFirewall[fwId].count++;
      
      // By remaining time - new categories:
      // Healthy: > 3 days (259200 seconds)
      // Expiring (tomorrow): 1 day to 3 days (86400 to 259200 seconds)
      // Expiring (today-10-30 min): 600 to 1800 seconds
      // Critical: < 10 minutes (< 600 seconds)
      const remainingSeconds = session.remaining_seconds || 0;
      if (remainingSeconds >= 259200) {
        stats.healthy++;
      } else if (remainingSeconds >= 86400) {
        stats.expiringMedium++; // Expiring tomorrow
      } else if (remainingSeconds >= 600) {
        stats.expiringMedium++; // Expiring today 10-30 min
      } else {
        stats.expiringSoon++; // Critical < 10 min
      }
    });
    
    return stats;
  },
  
  /**
   * Disconnect a voucher session
   * 
   * @param {string} firewallId - Firewall ID
   * @param {string} sessionId - Session ID to disconnect
   * @param {string} zone - Zone name (optional, helps with lookup)
   * @returns {Object} Result
   */
  disconnectSession: function(firewallId, sessionId, zone = 'default') {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    if (!sessionId) {
      return { success: false, error: 'Session ID is required' };
    }
    
    const payload = {
      session_id: sessionId
    };
    
    // Only include zone if provided and not empty
    if (zone && zone !== 'default' && zone.trim() !== '') {
      payload.zone = zone;
    }
    
    const result = PfSenseApi.request(firewall, 'voucher_disconnect', 'POST', payload);
    
    if (result.success) {
      SheetsDb.addLog(firewall.name, 'voucher_disconnect', 'success', 
        `Disconnected session: ${sessionId}`);
      
      // Mark session as ended in the sheet
      this.markSessionEnded(sessionId, 'manual');
      
      return {
        success: true,
        message: 'Session disconnected',
        disconnected: result.data.disconnected
      };
    } else {
      SheetsDb.addLog(firewall.name, 'voucher_disconnect', 'error', result.error);
      return {
        success: false,
        error: result.error
      };
    }
  },
  
  /**
   * Disconnect a session by MAC address
   * 
   * @param {string} firewallId - Firewall ID
   * @param {string} mac - MAC address
   * @param {string} zone - Zone name (optional)
   * @returns {Object} Result
   */
  disconnectByMac: function(firewallId, mac, zone = null) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    const payload = { mac: mac };
    if (zone) payload.zone = zone;
    
    const result = PfSenseApi.request(firewall, 'voucher_disconnect', 'POST', payload);
    
    if (result.success) {
      SheetsDb.addLog(firewall.name, 'voucher_disconnect', 'success', 
        `Disconnected MAC: ${mac}`);
      
      // Mark session as ended in the sheet by MAC
      this.markSessionEndedByMac(firewallId, mac, 'manual');
      
      return {
        success: true,
        message: 'Session disconnected',
        disconnected: result.data.disconnected
      };
    } else {
      SheetsDb.addLog(firewall.name, 'voucher_disconnect', 'error', result.error);
      return {
        success: false,
        error: result.error
      };
    }
  },
  
  /**
   * Get voucher sessions that are expiring soon (within threshold)
   * 
   * @param {number} thresholdMinutes - Threshold in minutes (default 10)
   * @returns {Object} Sessions expiring soon
   */
  getExpiringSessions: function(thresholdMinutes = 10) {
    const allResult = this.getAllActiveVouchers();
    
    if (!allResult.success && allResult.count === 0) {
      return allResult;
    }
    
    const thresholdSeconds = thresholdMinutes * 60;
    const expiringSessions = allResult.sessions.filter(
      session => session.remaining_seconds > 0 && session.remaining_seconds < thresholdSeconds
    );
    
    return {
      success: true,
      count: expiringSessions.length,
      sessions: expiringSessions,
      threshold_minutes: thresholdMinutes
    };
  },
  
  // =========================================================================
  // SESSION PERSISTENCE METHODS
  // =========================================================================
  
  /**
   * Sync live sessions to the VoucherSessions sheet
   * 
   * This method:
   * 1. Gets all active sessions from the sheet
   * 2. For each live session: UPDATE if exists, INSERT if new
   * 3. For each sheet session with status='active' not in live: mark as expired
   * 
   * @param {Array} liveSessions - Array of live sessions from pfSense API
   * @returns {Object} Sync result with counts
   */
  syncSessionsToSheet: function(liveSessions) {
    try {
      if (!liveSessions || liveSessions.length === 0) {
        // If no live sessions, mark all active sheet sessions as expired
        return this.reconcileSessions([]);
      }
      
      // Get all active sessions from sheet for comparison
      const sheetSessions = SheetsDb.getVoucherSessions({ status: 'active', limit: 50000 });
      
      // Build lookup map of sheet sessions by Session_ID for O(1) access
      const sheetSessionMap = new Map();
      sheetSessions.forEach(session => {
        sheetSessionMap.set(session.sessionId, session);
      });
      
      // Build set of live session IDs
      const liveSessionIds = new Set();
      
      let added = 0;
      let updated = 0;
      
      // Process each live session
      liveSessions.forEach(liveSession => {
        // Normalize MAC address for consistent storage and lookup
        const normalizedMac = normalizeMac(liveSession.mac);
        
        // Generate session ID if not present (use MAC + firewall as fallback)
        const sessionId = liveSession.session_id || 
                         liveSession.sessionId || 
                         `${liveSession.firewallId}_${normalizedMac}`;
        
        liveSessionIds.add(sessionId);
        
        const sessionData = {
          sessionId: sessionId,
          firewallId: liveSession.firewallId,
          firewallName: liveSession.firewallName,
          zone: liveSession.zone,
          mac: normalizedMac,  // Store normalized MAC for consistent lookups
          ip: liveSession.ip,
          username: liveSession.username || liveSession.user || '',
          remainingSeconds: liveSession.remaining_seconds || liveSession.remainingSeconds || 0,
          bytesIn: liveSession.bytes_in || liveSession.bytesIn || 0,
          bytesOut: liveSession.bytes_out || liveSession.bytesOut || 0,
          status: 'active'
        };
        
        // Check if exists in sheet
        if (sheetSessionMap.has(sessionId)) {
          // Update existing - only update dynamic fields
          const result = SheetsDb.upsertVoucherSession(sessionData);
          if (result.success && result.message === 'Session updated') {
            updated++;
          }
        } else {
          // Insert new session with start time
          sessionData.sessionStart = toIso8601(new Date());
          const result = SheetsDb.upsertVoucherSession(sessionData);
          if (result.success && result.message === 'Session added') {
            added++;
          }
        }
      });
      
      // Mark sessions that are no longer live as expired
      let expired = 0;
      sheetSessions.forEach(sheetSession => {
        if (!liveSessionIds.has(sheetSession.sessionId)) {
          const result = SheetsDb.updateVoucherSessionStatus(
            sheetSession.sessionId, 
            'expired', 
            'session_ended'
          );
          if (result.success) {
            expired++;
          }
        }
      });
      
      // Log sync results
      if (added > 0 || updated > 0 || expired > 0) {
        SheetsDb.addLog('System', 'voucher_sync', 'success', 
          `Synced sessions: ${added} added, ${updated} updated, ${expired} expired`);
      }
      
      return {
        success: true,
        added: added,
        updated: updated,
        expired: expired,
        totalLive: liveSessions.length
      };
    } catch (error) {
      SheetsDb.addLog('System', 'voucher_sync', 'error', error.message);
      return {
        success: false,
        error: error.message
      };
    }
  },
  
  /**
   * Sync sessions to per-firewall spreadsheets
   * Groups sessions by firewall and syncs each group to its dedicated spreadsheet
   * 
   * @param {Array} allSessions - Array of all live sessions from all firewalls
   * @returns {Object} Sync result with per-firewall counts
   */
  syncSessionsToFirewallSheets: function(allSessions) {
    try {
      // Group sessions by firewall
      const sessionsByFirewall = new Map();
      
      allSessions.forEach(session => {
        const fwId = session.firewallId;
        const fwName = session.firewallName || fwId;
        
        if (!sessionsByFirewall.has(fwId)) {
          sessionsByFirewall.set(fwId, {
            firewallName: fwName,
            sessions: []
          });
        }
        sessionsByFirewall.get(fwId).sessions.push(session);
      });
      
      // IMPORTANT: Include ALL firewalls (even those with 0 sessions)
      // This ensures orphaned "active" sessions get marked as expired
      // when a firewall goes from having sessions to having none
      const firewalls = SheetsDb.getFirewalls();
      firewalls.forEach(fw => {
        if (!sessionsByFirewall.has(fw.id)) {
          sessionsByFirewall.set(fw.id, {
            firewallName: fw.name,
            sessions: []  // Empty - will trigger expire-all logic in syncSessionsToFirewallSheet
          });
        }
      });
      
      const results = {};
      let totalAdded = 0;
      let totalUpdated = 0;
      let totalExpired = 0;
      
      // Sync each firewall's sessions to its dedicated spreadsheet
      sessionsByFirewall.forEach((data, firewallId) => {
        try {
          const result = this.syncSessionsToFirewallSheet(firewallId, data.firewallName, data.sessions);
          results[firewallId] = result;
          
          if (result.success) {
            totalAdded += result.added || 0;
            totalUpdated += result.updated || 0;
            totalExpired += result.expired || 0;
          }
        } catch (error) {
          results[firewallId] = { success: false, error: error.message };
          Logger.log('Error syncing firewall ' + firewallId + ': ' + error.message);
        }
      });
      
      // Log overall results
      if (totalAdded > 0 || totalUpdated > 0 || totalExpired > 0) {
        SheetsDb.addLog('System', 'voucher_sync_firewalls', 'success', 
          `Synced to ${sessionsByFirewall.size} firewall sheets: ${totalAdded} added, ${totalUpdated} updated, ${totalExpired} expired`);
      }
      
      return {
        success: true,
        firewallCount: sessionsByFirewall.size,
        totalAdded: totalAdded,
        totalUpdated: totalUpdated,
        totalExpired: totalExpired,
        results: results
      };
    } catch (error) {
      SheetsDb.addLog('System', 'voucher_sync_firewalls', 'error', error.message);
      return {
        success: false,
        error: error.message
      };
    }
  },
  
  /**
   * Sync sessions to a specific firewall's voucher spreadsheet
   * 
   * @param {string} firewallId - Firewall ID
   * @param {string} firewallName - Firewall name
   * @param {Array} sessions - Array of sessions for this firewall
   * @returns {Object} Sync result with counts
   */
  syncSessionsToFirewallSheet: function(firewallId, firewallName, sessions) {
    try {
      const spreadsheet = this.getFirewallVoucherSpreadsheet(firewallId, firewallName);
      const sheet = spreadsheet.getSheetByName('VoucherSessions');
      
      if (!sheet) {
        return { success: false, error: 'VoucherSessions sheet not found' };
      }
      
      // Get existing data from sheet
      const data = sheet.getDataRange().getValues();
      const headers = data[0] || [];
      
      // Build lookup map by Session_ID (column 0)
      const existingSessionMap = new Map();
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        if (row[0]) {
          existingSessionMap.set(row[0], {
            rowIndex: i + 1,  // 1-based
            status: row[11] || 'active'  // Status column
          });
        }
      }
      
      // Build set of live session IDs
      const liveSessionIds = new Set();
      
      let added = 0;
      let updated = 0;
      const now = toIso8601(new Date());
      
      // Process each live session
      sessions.forEach(session => {
        // Normalize MAC address for consistent storage and lookup
        const normalizedMac = normalizeMac(session.mac);
        
        const sessionId = session.session_id || session.sessionId || 
                         `${firewallId}_${normalizedMac}`;
        
        liveSessionIds.add(sessionId);
        
        const rowData = [
          sessionId,
          session.zone || '',
          normalizedMac || '',  // Store normalized MAC for consistent lookups
          session.ip || '',
          session.username || session.user || '',
          session.sessionStart || now,  // Session_Start
          '',  // Session_End (active sessions don't have this)
          session.duration_seconds || session.durationSeconds || 0,
          session.remaining_seconds || session.remainingSeconds || 0,
          session.bytes_in || session.bytesIn || 0,
          session.bytes_out || session.bytesOut || 0,
          'active',  // Status
          '',  // Disconnect_Reason
          now,  // Last_Updated
          ''  // Notes
        ];
        
        const existing = existingSessionMap.get(sessionId);
        
        if (existing) {
          // Update existing row (update dynamic fields)
          const rowIndex = existing.rowIndex;
          sheet.getRange(rowIndex, 8).setValue(rowData[7]);  // Duration
          sheet.getRange(rowIndex, 9).setValue(rowData[8]);  // Remaining
          sheet.getRange(rowIndex, 10).setValue(rowData[9]); // Bytes_In
          sheet.getRange(rowIndex, 11).setValue(rowData[10]); // Bytes_Out
          sheet.getRange(rowIndex, 12).setValue('active');   // Status
          sheet.getRange(rowIndex, 14).setValue(now);        // Last_Updated
          updated++;
        } else {
          // Insert new row
          sheet.appendRow(rowData);
          added++;
        }
      });
      
      // Mark sessions no longer live as expired
      let expired = 0;
      existingSessionMap.forEach((data, sessionId) => {
        if (!liveSessionIds.has(sessionId) && data.status === 'active') {
          const rowIndex = data.rowIndex;
          sheet.getRange(rowIndex, 7).setValue(now);  // Session_End
          sheet.getRange(rowIndex, 12).setValue('expired');  // Status
          sheet.getRange(rowIndex, 13).setValue('session_ended');  // Disconnect_Reason
          sheet.getRange(rowIndex, 14).setValue(now);  // Last_Updated
          expired++;
        }
      });
      
      return {
        success: true,
        firewallId: firewallId,
        added: added,
        updated: updated,
        expired: expired,
        totalLive: sessions.length
      };
    } catch (error) {
      Logger.log('Error syncing to firewall sheet ' + firewallId + ': ' + error.message);
      return {
        success: false,
        firewallId: firewallId,
        error: error.message
      };
    }
  },
  
  /**
   * Mark a session as ended (used after manual disconnect)
   * 
   * @param {string} sessionId - Session ID to mark as ended
   * @param {string} reason - Reason for disconnect (manual/expired/timeout/system)
   * @returns {Object} Result
   */
  markSessionEnded: function(sessionId, reason = 'manual') {
    if (!sessionId) {
      return { success: false, error: 'Missing sessionId' };
    }
    
    const result = SheetsDb.updateVoucherSessionStatus(sessionId, 'disconnected', reason);
    
    if (result.success) {
      SheetsDb.addLog('System', 'session_ended', 'success', 
        `Session ${sessionId} marked as disconnected (${reason})`);
    }
    
    return result;
  },
  
  /**
   * Mark a session by MAC address as ended
   * 
   * @param {string} firewallId - Firewall ID
   * @param {string} mac - MAC address
   * @param {string} reason - Reason for disconnect
   * @returns {Object} Result
   */
  markSessionEndedByMac: function(firewallId, mac, reason = 'manual') {
    const normalizedMac = normalizeMac(mac);
    
    // Find the active session for this MAC on this firewall
    const sessions = SheetsDb.getVoucherSessions({
      firewallId: firewallId,
      mac: normalizedMac,
      status: 'active',
      limit: 1
    });
    
    if (sessions.length === 0) {
      return { success: false, error: 'No active session found for MAC: ' + mac };
    }
    
    return this.markSessionEnded(sessions[0].sessionId, reason);
  },
  
  /**
   * Get historical session data (non-active sessions)
   * 
   * @param {Object} filters - Filter options
   * @param {string} filters.firewallId - Filter by firewall
   * @param {string} filters.zone - Filter by zone
   * @param {string} filters.mac - Filter by MAC address
   * @param {string} filters.startDate - Start date (ISO8601)
   * @param {string} filters.endDate - End date (ISO8601)
   * @param {number} filters.limit - Max results (default 100)
   * @returns {Object} Historical sessions
   */
  getSessionHistory: function(filters = {}) {
    try {
      // Get all non-active sessions
      const allSessions = SheetsDb.getVoucherSessions({
        ...filters,
        limit: filters.limit || 100
      });
      
      // Filter to only completed sessions (expired or disconnected)
      const historicalSessions = allSessions.filter(
        s => s.status === 'expired' || s.status === 'disconnected'
      );
      
      // Sort by session end time descending (most recent first)
      historicalSessions.sort((a, b) => {
        const dateA = a.sessionEnd ? new Date(a.sessionEnd) : new Date(0);
        const dateB = b.sessionEnd ? new Date(b.sessionEnd) : new Date(0);
        return dateB - dateA;
      });
      
      // Calculate statistics for historical data
      const stats = {
        total: historicalSessions.length,
        totalBytesIn: 0,
        totalBytesOut: 0,
        avgDurationSeconds: 0,
        byDisconnectReason: {
          manual: 0,
          expired: 0,
          session_ended: 0,
          timeout: 0,
          system: 0,
          unknown: 0
        }
      };
      
      let totalDuration = 0;
      
      historicalSessions.forEach(session => {
        stats.totalBytesIn += parseInt(session.bytesIn) || 0;
        stats.totalBytesOut += parseInt(session.bytesOut) || 0;
        totalDuration += parseInt(session.durationSeconds) || 0;
        
        const reason = session.disconnectReason || 'unknown';
        if (stats.byDisconnectReason[reason] !== undefined) {
          stats.byDisconnectReason[reason]++;
        } else {
          stats.byDisconnectReason.unknown++;
        }
      });
      
      if (historicalSessions.length > 0) {
        stats.avgDurationSeconds = Math.round(totalDuration / historicalSessions.length);
      }
      
      return {
        success: true,
        count: historicalSessions.length,
        sessions: historicalSessions,
        stats: stats
      };
    } catch (error) {
      return {
        success: false,
        error: error.message
      };
    }
  },
  
  /**
   * Reconcile sessions - mark orphaned active sessions as expired
   * 
   * This is useful when the sync doesn't catch all ended sessions,
   * or when running a batch cleanup.
   * 
   * @param {Array} liveSessionIds - Array of currently live session IDs (optional)
   * @returns {Object} Result with count of reconciled sessions
   */
  reconcileSessions: function(liveSessionIds = null) {
    try {
      // If no live session IDs provided, just get current active from sheet
      const activeSheetSessions = SheetsDb.getVoucherSessions({ status: 'active', limit: 50000 });
      
      if (activeSheetSessions.length === 0) {
        return { success: true, reconciled: 0, message: 'No active sessions to reconcile' };
      }
      
      // If liveSessionIds is null, we can't reconcile (need to know what's live)
      if (liveSessionIds === null) {
        return { 
          success: true, 
          reconciled: 0, 
          message: 'No live session data provided for reconciliation',
          activeCount: activeSheetSessions.length
        };
      }
      
      // Convert to Set for O(1) lookup
      const liveSet = new Set(liveSessionIds);
      
      let reconciled = 0;
      
      activeSheetSessions.forEach(session => {
        if (!liveSet.has(session.sessionId)) {
          const result = SheetsDb.updateVoucherSessionStatus(
            session.sessionId,
            'expired',
            'reconciled'
          );
          if (result.success) {
            reconciled++;
          }
        }
      });
      
      if (reconciled > 0) {
        SheetsDb.addLog('System', 'session_reconcile', 'success', 
          `Reconciled ${reconciled} orphaned sessions`);
      }
      
      return {
        success: true,
        reconciled: reconciled
      };
    } catch (error) {
      SheetsDb.addLog('System', 'session_reconcile', 'error', error.message);
      return {
        success: false,
        error: error.message
      };
    }
  },
  
  /**
   * Get session statistics with optional filters
   * 
   * @param {Object} filters - Filter options
   * @returns {Object} Session statistics
   */
  getSessionStats: function(filters = {}) {
    return SheetsDb.getVoucherSessionStats(filters);
  },
  
  /**
   * Run diagnostic on a firewall to understand captive portal database structure
   * This helps debug why sessions aren't being returned
   * 
   * @param {string} firewallId - Firewall ID to diagnose
   * @returns {Object} Diagnostic information
   */
  runDiagnostic: function(firewallId) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    // Call the vouchers endpoint with diagnostic=true
    const result = PfSenseApi.request(firewall, 'vouchers&diagnostic=true', 'GET', { diagnostic: true });
    
    if (!result.success) {
      return {
        success: false,
        error: result.error,
        firewallId: firewallId
      };
    }
    
    return {
      success: true,
      firewallId: firewallId,
      firewallName: firewall.name,
      diagnostic: result.data.diagnostic || {},
      zones: result.data.zones || [],
      sessionCount: result.data.count || 0
    };
  }
};

// ============================================
// Wrapper functions for google.script.run
// ============================================

/**
 * Get active vouchers from a specific firewall
 */
function getActiveVouchers(firewallId, zone) {
  return VoucherService.getActiveVouchers(firewallId, zone);
}

/**
 * Get active vouchers from all firewalls
 */
function getAllActiveVouchers(zone) {
  return VoucherService.getAllActiveVouchers(zone);
}

/**
 * Disconnect a voucher session
 */
function disconnectVoucherSession(firewallId, sessionId, zone) {
  return VoucherService.disconnectSession(firewallId, sessionId, zone);
}

/**
 * Disconnect a session by MAC address
 */
function disconnectVoucherByMac(firewallId, mac, zone) {
  return VoucherService.disconnectByMac(firewallId, mac, zone);
}

/**
 * Get sessions expiring soon
 */
function getExpiringSessions(thresholdMinutes) {
  return VoucherService.getExpiringSessions(thresholdMinutes);
}

/**
 * Get voucher sessions from sheet with filters
 */
function getVoucherSessions(filters) {
  return { 
    success: true, 
    sessions: SheetsDb.getVoucherSessions(filters || {}) 
  };
}

/**
 * Get historical (completed) voucher sessions
 */
function getVoucherSessionHistory(filters) {
  return VoucherService.getSessionHistory(filters);
}

/**
 * Get voucher session statistics
 */
function getVoucherSessionStats(filters) {
  return { 
    success: true, 
    stats: VoucherService.getSessionStats(filters || {}) 
  };
}

/**
 * Clean up old voucher sessions
 */
function cleanupVoucherSessions(retentionDays) {
  return SheetsDb.cleanupOldVoucherSessions(retentionDays);
}

/**
 * Reconcile voucher sessions (mark orphaned active as expired)
 * 
 * Fetches live sessions from all firewalls and marks any active sheet sessions
 * that are no longer live as expired.
 */
function reconcileVoucherSessions() {
  // Fetch live sessions from all firewalls
  const liveResult = VoucherService.getAllActiveVouchers();
  
  if (!liveResult.success || !liveResult.sessions || liveResult.sessions.length === 0) {
    // No live sessions - mark all active sheet sessions as expired
    return VoucherService.reconcileSessions([]);
  }
  
  // Build set of live session IDs (same logic as syncSessionsToSheet)
  const liveSessionIds = new Set();
  
  liveResult.sessions.forEach(liveSession => {
    // Generate session ID if not present (use MAC + firewall as fallback)
    const sessionId = liveSession.session_id || 
                     liveSession.sessionId || 
                     `${liveSession.firewallId}_${normalizeMac(liveSession.mac)}`;
    
    liveSessionIds.add(sessionId);
  });
  
  // Pass live session IDs to reconcileSessions
  return VoucherService.reconcileSessions(Array.from(liveSessionIds));
}

/**
 * Run diagnostic on a firewall to debug captive portal database issues
 */
function runVoucherDiagnostic(firewallId) {
  return VoucherService.runDiagnostic(firewallId);
}

/**
 * Run diagnostic on all firewalls
 */
function runAllVoucherDiagnostics() {
  const firewalls = SheetsDb.getFirewalls();
  const results = {};
  
  firewalls.forEach(fw => {
    if (fw.status === 'online') {
      results[fw.id] = VoucherService.runDiagnostic(fw.id);
    }
  });
  
  return { success: true, diagnostics: results };
}
