/**
 * SheetsDb.gs - Google Sheets data layer for pfSense MAC Binding Manager
 * 
 * Handles all CRUD operations for the Google Sheets database.
 */

/**
 * SheetsDb namespace
 */
const SheetsDb = {
  
  /**
   * Get the spreadsheet instance
   */
  getSpreadsheet: function() {
    const id = getSpreadsheetId();
    return SpreadsheetApp.openById(id);
  },
  
  /**
   * Get or create a sheet by name
   */
  getSheet: function(sheetName) {
    const ss = this.getSpreadsheet();
    let sheet = ss.getSheetByName(sheetName);
    
    if (!sheet) {
      sheet = ss.insertSheet(sheetName);
      this.initializeSheet(sheet, sheetName);
    }
    
    return sheet;
  },
  
  /**
   * Initialize sheet with headers based on type
   */
  initializeSheet: function(sheet, sheetName) {
    switch (sheetName) {
      case CONFIG.SHEETS.FIREWALLS:
        sheet.appendRow([
          'ID', 'Name', 'URL', 'API_Key', 'Zones', 
          'Last_Sync', 'Status', 'Active_Bindings', 'Notes'
        ]);
        sheet.getRange(1, 1, 1, 9).setFontWeight('bold').setBackground('#4285f4').setFontColor('white');
        sheet.setFrozenRows(1);
        break;
        
      case CONFIG.SHEETS.BINDINGS:
        sheet.appendRow([
          'Firewall_ID', 'Zone', 'MAC', 'Expires_At', 
          'IP', 'Voucher_Hash', 'Last_Seen', 'Status', 'Added_By',
          'Description', 'Action'
        ]);
        sheet.getRange(1, 1, 1, 11).setFontWeight('bold').setBackground('#34a853').setFontColor('white');
        sheet.setFrozenRows(1);
        break;
      
      case 'ScheduledBackups':
        sheet.appendRow([
          'ID', 'Firewall_ID', 'Schedule', 'Options_JSON', 
          'Enabled', 'Last_Run', 'Next_Run', 'Created_At', 'Notes'
        ]);
        sheet.getRange(1, 1, 1, 9).setFontWeight('bold').setBackground('#ff9800').setFontColor('white');
        sheet.setFrozenRows(1);
        break;
        
      case CONFIG.SHEETS.LOGS:
        sheet.appendRow([
          'Timestamp', 'Firewall', 'Action', 'Details', 'Result', 'User'
        ]);
        sheet.getRange(1, 1, 1, 6).setFontWeight('bold').setBackground('#ea4335').setFontColor('white');
        sheet.setFrozenRows(1);
        break;
        
      case CONFIG.SHEETS.CONFIG:
        sheet.appendRow(['Key', 'Value', 'Description']);
        sheet.getRange(1, 1, 1, 3).setFontWeight('bold').setBackground('#fbbc04').setFontColor('black');
        // Add default config values
        sheet.appendRow(['sync_interval_minutes', '5', 'How often to sync with pfSense (minutes)']);
        sheet.appendRow(['stale_threshold_minutes', '15', 'Mark firewall offline if not synced in N minutes']);
        sheet.appendRow(['default_duration_minutes', '43200', 'Default binding duration (30 days)']);
        sheet.appendRow(['max_log_entries', '10000', 'Maximum log entries to keep']);
        sheet.appendRow(['backup_folder_id', '', 'Google Drive folder ID for backups (REQUIRED - get from folder URL)']);
        sheet.appendRow(['backup_folder_name', 'pfSense Backups', 'Backup folder name (only used if backup_folder_id is empty)']);
        sheet.appendRow(['backup_keep_count', '10', 'Number of backups to keep per firewall']);
        sheet.appendRow(['scheduled_backup_enabled', 'true', 'Enable scheduled backups (true/false)']);
        sheet.appendRow(['voucher_session_retention_days', '90', 'Days to keep voucher session history']);
        sheet.appendRow(['voucher_data_folder_id', '', 'Google Drive folder ID for per-firewall voucher spreadsheets']);
        sheet.appendRow(['voucher_data_folder_name', 'pfSense Voucher Data', 'Folder name (only used if voucher_data_folder_id is empty)']);
        sheet.setFrozenRows(1);
        break;
        
      case CONFIG.SHEETS.VOUCHER_SESSIONS:
        sheet.appendRow([
          'Session_ID', 'Firewall_ID', 'Firewall_Name', 'Zone',
          'MAC', 'IP', 'Username',
          'Session_Start', 'Session_End', 'Duration_Seconds', 'Remaining_Seconds',
          'Bytes_In', 'Bytes_Out',
          'Status', 'Disconnect_Reason', 'Last_Updated', 'Notes'
        ]);
        sheet.getRange(1, 1, 1, 17).setFontWeight('bold').setBackground('#009688').setFontColor('white');
        sheet.setFrozenRows(1);
        break;
    }
  },
  
  /**
   * Initialize all sheets (creates them if they don't exist)
   */
  initializeAllSheets: function() {
    Object.values(CONFIG.SHEETS).forEach(sheetName => {
      this.getSheet(sheetName);
    });
    Logger.log('All sheets initialized');
    return { success: true, message: 'All sheets initialized' };
  },
  
  // =========================================================================
  // FIREWALLS CRUD
  // =========================================================================
  
  /**
   * Get all firewalls
   */
  getFirewalls: function() {
    const sheet = this.getSheet(CONFIG.SHEETS.FIREWALLS);
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return []; // Only headers
    
    const firewalls = [];
    for (let i = 1; i < data.length; i++) {
      const row = data[i];
      if (!row[0]) continue; // Skip empty rows
      
      firewalls.push({
        id: row[0],
        name: row[1],
        url: row[2],
        apiKey: row[3],
        zones: row[4] ? row[4].split(',').map(z => z.trim()) : [],
        lastSync: row[5],
        status: row[6] || 'unknown',
        activeBindings: parseInt(row[7]) || 0,
        notes: row[8] || '',
        rowIndex: i + 1 // 1-based row number in sheet
      });
    }
    
    return firewalls;
  },
  
  /**
   * Get firewall by ID
   */
  getFirewall: function(firewallId) {
    const firewalls = this.getFirewalls();
    return firewalls.find(fw => fw.id === firewallId) || null;
  },
  
  /**
   * Add new firewall
   */
  addFirewall: function(firewall) {
    if (!firewall.id || !firewall.name || !firewall.url || !firewall.apiKey) {
      return { success: false, error: 'Missing required fields: id, name, url, apiKey' };
    }
    
    // Check for duplicate ID
    const existing = this.getFirewall(firewall.id);
    if (existing) {
      return { success: false, error: 'Firewall ID already exists: ' + firewall.id };
    }
    
    const sheet = this.getSheet(CONFIG.SHEETS.FIREWALLS);
    sheet.appendRow([
      firewall.id,
      firewall.name,
      firewall.url,
      firewall.apiKey,
      Array.isArray(firewall.zones) ? firewall.zones.join(', ') : (firewall.zones || ''),
      '', // lastSync
      'unknown', // status
      0, // activeBindings
      firewall.notes || ''
    ]);
    
    this.addLog(firewall.name, 'add_firewall', 'success', 'Added firewall: ' + firewall.url);
    return { success: true, message: 'Firewall added', firewallId: firewall.id };
  },
  
  /**
   * Update firewall
   */
  updateFirewall: function(firewall) {
    if (!firewall.id) {
      return { success: false, error: 'Missing firewall ID' };
    }
    
    const existing = this.getFirewall(firewall.id);
    if (!existing) {
      return { success: false, error: 'Firewall not found: ' + firewall.id };
    }
    
    const sheet = this.getSheet(CONFIG.SHEETS.FIREWALLS);
    const rowIndex = existing.rowIndex;
    
    // Update only provided fields
    if (firewall.name !== undefined) sheet.getRange(rowIndex, 2).setValue(firewall.name);
    if (firewall.url !== undefined) sheet.getRange(rowIndex, 3).setValue(firewall.url);
    if (firewall.apiKey !== undefined) sheet.getRange(rowIndex, 4).setValue(firewall.apiKey);
    if (firewall.zones !== undefined) {
      const zonesStr = Array.isArray(firewall.zones) ? firewall.zones.join(', ') : firewall.zones;
      sheet.getRange(rowIndex, 5).setValue(zonesStr);
    }
    if (firewall.lastSync !== undefined) sheet.getRange(rowIndex, 6).setValue(firewall.lastSync);
    if (firewall.status !== undefined) sheet.getRange(rowIndex, 7).setValue(firewall.status);
    if (firewall.activeBindings !== undefined) sheet.getRange(rowIndex, 8).setValue(firewall.activeBindings);
    if (firewall.notes !== undefined) sheet.getRange(rowIndex, 9).setValue(firewall.notes);
    
    return { success: true, message: 'Firewall updated' };
  },
  
  /**
   * Remove firewall
   */
  removeFirewall: function(firewallId) {
    const existing = this.getFirewall(firewallId);
    if (!existing) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    const sheet = this.getSheet(CONFIG.SHEETS.FIREWALLS);
    sheet.deleteRow(existing.rowIndex);
    
    // Also remove associated bindings
    this.removeBindingsByFirewall(firewallId);
    
    this.addLog(existing.name, 'remove_firewall', 'success', 'Removed firewall: ' + existing.url);
    return { success: true, message: 'Firewall removed' };
  },
  
  // =========================================================================
  // BINDINGS CRUD
  // =========================================================================
  
  /**
   * Get all bindings, optionally filtered by firewall
   */
  getBindings: function(firewallId = null) {
    const sheet = this.getSheet(CONFIG.SHEETS.BINDINGS);
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return [];
    
    const bindings = [];
    for (let i = 1; i < data.length; i++) {
      const row = data[i];
      if (!row[2]) continue; // Skip rows without MAC
      
      if (firewallId && row[0] !== firewallId) continue;
      
      bindings.push({
        firewallId: row[0],
        zone: row[1],
        mac: row[2],
        expiresAt: row[3],
        ip: row[4],
        voucherHash: row[5],
        lastSeen: row[6],
        status: row[7] || 'active',
        addedBy: row[8] || '',
        description: row[9] || '',
        action: row[10] || 'pass',
        rowIndex: i + 1
      });
    }
    
    return bindings;
  },
  
  /**
   * Get binding by MAC and firewall
   */
  getBinding: function(firewallId, mac) {
    const normalizedMac = normalizeMac(mac);
    const bindings = this.getBindings(firewallId);
    return bindings.find(b => normalizeMac(b.mac) === normalizedMac) || null;
  },
  
  /**
   * Add or update binding
   * Uses locking to prevent race conditions during concurrent read-modify-write
   */
  upsertBinding: function(binding) {
    if (!binding.firewallId || !binding.mac) {
      return { success: false, error: 'Missing required fields: firewallId, mac' };
    }

    const normalizedMac = normalizeMac(binding.mac);
    if (!normalizedMac) {
      return { success: false, error: 'Invalid MAC address format' };
    }

    // Acquire lock to prevent race conditions
    const lock = LockService.getScriptLock();
    try {
      lock.waitLock(10000); // Wait up to 10 seconds
    } catch (e) {
      return { success: false, error: 'Could not acquire lock for binding upsert: ' + e.message };
    }

    try {
      const existing = this.getBinding(binding.firewallId, normalizedMac);
      const sheet = this.getSheet(CONFIG.SHEETS.BINDINGS);

      if (existing) {
        // Update existing
        const rowIndex = existing.rowIndex;
        if (binding.zone !== undefined) sheet.getRange(rowIndex, 2).setValue(binding.zone);
        sheet.getRange(rowIndex, 3).setValue(normalizedMac);
        if (binding.expiresAt !== undefined) sheet.getRange(rowIndex, 4).setValue(binding.expiresAt);
        if (binding.ip !== undefined) sheet.getRange(rowIndex, 5).setValue(binding.ip);
        if (binding.voucherHash !== undefined) sheet.getRange(rowIndex, 6).setValue(binding.voucherHash);
        if (binding.lastSeen !== undefined) sheet.getRange(rowIndex, 7).setValue(binding.lastSeen);
        if (binding.status !== undefined) sheet.getRange(rowIndex, 8).setValue(binding.status);
        if (binding.description !== undefined) sheet.getRange(rowIndex, 10).setValue(binding.description);
        if (binding.action !== undefined) sheet.getRange(rowIndex, 11).setValue(binding.action);

        return { success: true, message: 'Binding updated', mac: normalizedMac };
      } else {
        // Add new
        sheet.appendRow([
          binding.firewallId,
          binding.zone || '',
          normalizedMac,
          binding.expiresAt || '',
          binding.ip || '',
          binding.voucherHash || '',
          binding.lastSeen || new Date().toISOString(),
          binding.status || 'active',
          binding.addedBy || 'API',
          binding.description || '',
          binding.action || 'pass'
        ]);

        return { success: true, message: 'Binding added', mac: normalizedMac };
      }
    } finally {
      lock.releaseLock();
    }
  },
  
  /**
   * Remove binding
   */
  removeBinding: function(firewallId, mac) {
    const normalizedMac = normalizeMac(mac);
    const existing = this.getBinding(firewallId, normalizedMac);
    
    if (!existing) {
      return { success: false, error: 'Binding not found' };
    }
    
    const sheet = this.getSheet(CONFIG.SHEETS.BINDINGS);
    sheet.deleteRow(existing.rowIndex);
    
    return { success: true, message: 'Binding removed', mac: normalizedMac };
  },
  
  /**
   * Remove all bindings for a firewall
   */
  removeBindingsByFirewall: function(firewallId) {
    const bindings = this.getBindings(firewallId);
    const sheet = this.getSheet(CONFIG.SHEETS.BINDINGS);
    
    // Delete rows in reverse order to maintain correct indices
    const rowsToDelete = bindings.map(b => b.rowIndex).sort((a, b) => b - a);
    rowsToDelete.forEach(rowIndex => {
      sheet.deleteRow(rowIndex);
    });
    
    return { success: true, removed: bindings.length };
  },
  
  /**
   * Bulk update bindings from pfSense sync
   * Uses locking and timestamp-based conflict resolution to prevent race conditions
   */
  syncBindings: function(firewallId, remoteBindings) {
    // Acquire lock for entire sync operation to prevent concurrent sync race
    const lock = LockService.getScriptLock();
    try {
      lock.waitLock(30000); // Wait up to 30 seconds for sync operations
    } catch (e) {
      Logger.log('Could not acquire lock for syncBindings: ' + e.message);
      return { success: false, error: 'Could not acquire lock for sync', added: 0, updated: 0, removed: 0 };
    }

    try {
      const syncTimestamp = new Date();
      const localBindings = this.getBindings(firewallId);
      const localMacs = new Set(localBindings.map(b => normalizeMac(b.mac)));
      const remoteMacs = new Set(remoteBindings.map(b => normalizeMac(b.mac)));

      // Build local lookup map for conflict resolution
      const localBindingMap = new Map();
      localBindings.forEach(b => {
        localBindingMap.set(normalizeMac(b.mac), b);
      });

      let added = 0;
      let updated = 0;
      let removed = 0;
      let skipped = 0;

      // Add/update remote bindings
      remoteBindings.forEach(binding => {
        binding.firewallId = firewallId;

        // Normalize field names: convert expires_at (snake_case) to expiresAt (camelCase)
        if (binding.expires_at !== undefined && binding.expiresAt === undefined) {
          binding.expiresAt = binding.expires_at;
          delete binding.expires_at;
        }

        const normalizedMac = normalizeMac(binding.mac);
        const existing = localBindingMap.get(normalizedMac);

        // Timestamp-based conflict resolution
        if (existing) {
          // Preserve local expiry if remote doesn't have one
          if (!binding.expiresAt || binding.expiresAt === null || binding.expiresAt === '') {
            if (existing.expiresAt) {
              binding.expiresAt = existing.expiresAt;
            }
          } else {
            // If both have expiry, keep the later one (user may have extended locally)
            const localExpiry = existing.expiresAt ? new Date(existing.expiresAt) : null;
            const remoteExpiry = binding.expiresAt ? new Date(binding.expiresAt) : null;
            if (localExpiry && remoteExpiry && localExpiry > remoteExpiry) {
              binding.expiresAt = existing.expiresAt;
              skipped++;
              return; // Skip this update to preserve local changes
            }
          }
        }

        const result = this.upsertBinding(binding);
        if (result.message === 'Binding added') added++;
        else if (result.message === 'Binding updated') updated++;
      });

      // Remove bindings that no longer exist on pfSense OR are expired locally
      localBindings.forEach(local => {
        const mac = normalizeMac(local.mac);
        const isExpired = local.expiresAt && new Date(local.expiresAt) < new Date();
        const notOnRemote = !remoteMacs.has(mac);

        if (notOnRemote || isExpired) {
          this.removeBinding(firewallId, mac);
          if (isExpired) {
            Logger.log(`Removing expired binding: ${mac}, expired at: ${local.expiresAt}`);
          }
          removed++;
        }
      });

      return { success: true, added, updated, removed, skipped };
    } finally {
      lock.releaseLock();
    }
  },
  
  /**
   * Search bindings by MAC or IP
   */
  searchBindings: function(query) {
    if (!query) return [];
    
    const queryLower = query.toLowerCase();
    const bindings = this.getBindings();
    
    return bindings.filter(b => {
      const mac = (b.mac || '').toLowerCase();
      const ip = (b.ip || '').toLowerCase();
      return mac.includes(queryLower) || ip.includes(queryLower);
    });
  },
  
  /**
   * Get expired bindings
   */
  getExpiredBindings: function() {
    const now = new Date();
    const bindings = this.getBindings();
    
    return bindings.filter(b => {
      if (!b.expiresAt) return false;
      const expires = new Date(b.expiresAt);
      return expires < now;
    });
  },
  
  // =========================================================================
  // LOGS
  // =========================================================================
  
  /**
   * Add log entry
   */
  addLog: function(firewall, action, result, details, user = 'System') {
    const sheet = this.getSheet(CONFIG.SHEETS.LOGS);
    sheet.appendRow([
      new Date().toISOString(),
      firewall,
      action,
      details,
      result,
      user
    ]);
    
    // Trim old logs if needed
    const maxLogs = getConfigValue('max_log_entries', CONFIG.LOG.MAX_ENTRIES);
    const rowCount = sheet.getLastRow();
    if (rowCount > maxLogs + 1) { // +1 for header
      const toDelete = rowCount - maxLogs - 1;
      sheet.deleteRows(2, toDelete);
    }
  },
  
  /**
   * Get recent logs
   */
  getLogs: function(limit = 100) {
    const sheet = this.getSheet(CONFIG.SHEETS.LOGS);
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return [];
    
    const logs = [];
    // Read in reverse order (newest first)
    for (let i = data.length - 1; i >= 1 && logs.length < limit; i--) {
      const row = data[i];
      logs.push({
        timestamp: row[0],
        firewall: row[1],
        action: row[2],
        details: row[3],
        result: row[4],
        user: row[5]
      });
    }
    
    return logs;
  },
  
  /**
   * Get logs with filtering options
   * 
   * @param {Object} filters - Filter options
   * @param {string} filters.startDate - Start date (ISO8601)
   * @param {string} filters.endDate - End date (ISO8601)
   * @param {string} filters.firewall - Firewall name filter
   * @param {Array<string>} filters.actions - Action types to include
   * @param {string} filters.result - Result filter (success/error/warning)
   * @param {string} filters.search - Text search in details
   * @param {number} filters.limit - Max results (default 100)
   * @returns {Array} Filtered log entries
   */
  getLogsFiltered: function(filters = {}) {
    const sheet = this.getSheet(CONFIG.SHEETS.LOGS);
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return [];
    
    const limit = filters.limit || 100;
    const startDate = filters.startDate ? new Date(filters.startDate) : null;
    const endDate = filters.endDate ? new Date(filters.endDate) : null;
    const firewallFilter = filters.firewall ? filters.firewall.toLowerCase() : null;
    const actionsFilter = filters.actions && filters.actions.length > 0 
      ? filters.actions.map(a => a.toLowerCase()) 
      : null;
    const resultFilter = filters.result ? filters.result.toLowerCase() : null;
    const searchTerm = filters.search ? filters.search.toLowerCase() : null;
    
    const logs = [];
    
    // Read in reverse order (newest first)
    for (let i = data.length - 1; i >= 1 && logs.length < limit; i--) {
      const row = data[i];
      const timestamp = row[0];
      const firewall = row[1] || '';
      const action = row[2] || '';
      const details = row[3] || '';
      const result = row[4] || '';
      const user = row[5] || '';
      
      // Apply date filters
      if (startDate || endDate) {
        const logDate = new Date(timestamp);
        if (startDate && logDate < startDate) continue;
        if (endDate && logDate > endDate) continue;
      }
      
      // Apply firewall filter
      if (firewallFilter && firewall.toLowerCase() !== firewallFilter) {
        continue;
      }
      
      // Apply action type filter
      if (actionsFilter && !actionsFilter.includes(action.toLowerCase())) {
        continue;
      }
      
      // Apply result filter
      if (resultFilter && result.toLowerCase() !== resultFilter) {
        continue;
      }
      
      // Apply search filter
      if (searchTerm && 
          !details.toLowerCase().includes(searchTerm) &&
          !firewall.toLowerCase().includes(searchTerm) &&
          !action.toLowerCase().includes(searchTerm)) {
        continue;
      }
      
      logs.push({
        timestamp: timestamp,
        firewall: firewall,
        action: action,
        details: details,
        result: result,
        user: user
      });
    }
    
    return logs;
  },
  
  /**
   * Get unique values for log filters (action types, firewalls)
   * 
   * @returns {Object} Unique values for filter dropdowns
   */
  getLogFilterOptions: function() {
    const sheet = this.getSheet(CONFIG.SHEETS.LOGS);
    const data = sheet.getDataRange().getValues();
    
    const firewalls = new Set();
    const actions = new Set();
    const results = new Set();
    
    for (let i = 1; i < data.length; i++) {
      const row = data[i];
      if (row[1]) firewalls.add(row[1]);
      if (row[2]) actions.add(row[2]);
      if (row[4]) results.add(row[4]);
    }
    
    return {
      firewalls: Array.from(firewalls).sort(),
      actions: Array.from(actions).sort(),
      results: Array.from(results).sort()
    };
  },
  
  // =========================================================================
  // CONFIG
  // =========================================================================
  
  /**
   * Get all config as object
   */
  getConfig: function() {
    const sheet = this.getSheet(CONFIG.SHEETS.CONFIG);
    const data = sheet.getDataRange().getValues();
    
    const config = {};
    for (let i = 1; i < data.length; i++) {
      const key = data[i][0];
      const value = data[i][1];
      if (key) {
        config[key] = value;
      }
    }
    
    return config;
  },
  
  /**
   * Set config value
   */
  setConfig: function(key, value) {
    const sheet = this.getSheet(CONFIG.SHEETS.CONFIG);
    const data = sheet.getDataRange().getValues();
    
    // Find existing row
    for (let i = 1; i < data.length; i++) {
      if (data[i][0] === key) {
        sheet.getRange(i + 1, 2).setValue(value);
        return { success: true, message: 'Config updated' };
      }
    }
    
    // Add new row
    sheet.appendRow([key, value, '']);
    return { success: true, message: 'Config added' };
  },
  
  // =========================================================================
  // VOUCHER SESSIONS CRUD
  // =========================================================================
  
  /**
   * Get voucher sessions with optional filters
   * 
   * @param {Object} filters - Optional filters
   * @param {string} filters.firewallId - Filter by firewall ID
   * @param {string} filters.zone - Filter by zone
   * @param {string} filters.mac - Filter by MAC address
   * @param {string} filters.status - Filter by status (active/expired/disconnected)
   * @param {string} filters.startDate - Filter sessions starting after this date (ISO8601)
   * @param {string} filters.endDate - Filter sessions starting before this date (ISO8601)
   * @param {number} filters.limit - Max results (default 500)
   * @returns {Array} Array of voucher session objects
   */
  getVoucherSessions: function(filters = {}) {
    const sheet = this.getSheet(CONFIG.SHEETS.VOUCHER_SESSIONS);
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return []; // Only headers
    
    const limit = filters.limit || 500;
    const startDate = filters.startDate ? new Date(filters.startDate) : null;
    const endDate = filters.endDate ? new Date(filters.endDate) : null;
    const macFilter = filters.mac ? normalizeMac(filters.mac) : null;
    
    const sessions = [];
    
    for (let i = 1; i < data.length && sessions.length < limit; i++) {
      const row = data[i];
      if (!row[0]) continue; // Skip empty rows
      
      // Apply filters
      if (filters.firewallId && row[1] !== filters.firewallId) continue;
      if (filters.zone && row[3] !== filters.zone) continue;
      if (filters.status && row[13] !== filters.status) continue;
      if (macFilter && normalizeMac(row[4]) !== macFilter) continue;
      
      // Date filters on Session_Start
      if (startDate || endDate) {
        const sessionStart = row[7] ? new Date(row[7]) : null;
        if (sessionStart) {
          if (startDate && sessionStart < startDate) continue;
          if (endDate && sessionStart > endDate) continue;
        }
      }
      
      sessions.push({
        sessionId: row[0],
        firewallId: row[1],
        firewallName: row[2],
        zone: row[3],
        mac: row[4],
        ip: row[5],
        username: row[6],
        sessionStart: row[7],
        sessionEnd: row[8],
        durationSeconds: row[9],
        remainingSeconds: row[10],
        bytesIn: row[11],
        bytesOut: row[12],
        status: row[13] || 'active',
        disconnectReason: row[14],
        lastUpdated: row[15],
        notes: row[16],
        rowIndex: i + 1
      });
    }
    
    return sessions;
  },
  
  /**
   * Get a single voucher session by Session_ID
   * 
   * @param {string} sessionId - The session ID to find
   * @returns {Object|null} Session object or null if not found
   */
  getVoucherSession: function(sessionId) {
    const sessions = this.getVoucherSessions({ limit: 10000 });
    return sessions.find(s => s.sessionId === sessionId) || null;
  },
  
  /**
   * Insert or update a voucher session by Session_ID
   * 
   * @param {Object} session - Session data
   * @returns {Object} Result with success status
   */
  upsertVoucherSession: function(session) {
    if (!session.sessionId) {
      return { success: false, error: 'Missing required field: sessionId' };
    }
    
    const normalizedMac = session.mac ? normalizeMac(session.mac) : '';
    const now = toIso8601(new Date());
    
    try {
      const existing = this.getVoucherSession(session.sessionId);
      const sheet = this.getSheet(CONFIG.SHEETS.VOUCHER_SESSIONS);
      
      if (existing) {
        // Update existing session
        const rowIndex = existing.rowIndex;
        
        if (session.firewallId !== undefined) sheet.getRange(rowIndex, 2).setValue(session.firewallId);
        if (session.firewallName !== undefined) sheet.getRange(rowIndex, 3).setValue(session.firewallName);
        if (session.zone !== undefined) sheet.getRange(rowIndex, 4).setValue(session.zone);
        if (normalizedMac) sheet.getRange(rowIndex, 5).setValue(normalizedMac);
        if (session.ip !== undefined) sheet.getRange(rowIndex, 6).setValue(session.ip);
        if (session.username !== undefined) sheet.getRange(rowIndex, 7).setValue(session.username);
        if (session.sessionStart !== undefined) sheet.getRange(rowIndex, 8).setValue(session.sessionStart);
        if (session.sessionEnd !== undefined) sheet.getRange(rowIndex, 9).setValue(session.sessionEnd);
        if (session.durationSeconds !== undefined) sheet.getRange(rowIndex, 10).setValue(session.durationSeconds);
        if (session.remainingSeconds !== undefined) sheet.getRange(rowIndex, 11).setValue(session.remainingSeconds);
        if (session.bytesIn !== undefined) sheet.getRange(rowIndex, 12).setValue(session.bytesIn);
        if (session.bytesOut !== undefined) sheet.getRange(rowIndex, 13).setValue(session.bytesOut);
        if (session.status !== undefined) sheet.getRange(rowIndex, 14).setValue(session.status);
        if (session.disconnectReason !== undefined) sheet.getRange(rowIndex, 15).setValue(session.disconnectReason);
        sheet.getRange(rowIndex, 16).setValue(now); // Always update Last_Updated
        if (session.notes !== undefined) sheet.getRange(rowIndex, 17).setValue(session.notes);
        
        return { success: true, message: 'Session updated', sessionId: session.sessionId };
      } else {
        // Insert new session
        sheet.appendRow([
          session.sessionId,
          session.firewallId || '',
          session.firewallName || '',
          session.zone || '',
          normalizedMac,
          session.ip || '',
          session.username || '',
          session.sessionStart || now,
          session.sessionEnd || '',
          session.durationSeconds || 0,
          session.remainingSeconds || 0,
          session.bytesIn || 0,
          session.bytesOut || 0,
          session.status || 'active',
          session.disconnectReason || '',
          now,
          session.notes || ''
        ]);
        
        return { success: true, message: 'Session added', sessionId: session.sessionId };
      }
    } catch (error) {
      this.addLog('System', 'upsert_voucher_session', 'error', 
        `Failed to upsert session ${session.sessionId}: ${error.message}`);
      return { success: false, error: error.message };
    }
  },
  
  /**
   * Update voucher session status
   * 
   * @param {string} sessionId - Session ID to update
   * @param {string} status - New status (active/expired/disconnected)
   * @param {string} reason - Disconnect reason (manual/expired/timeout/system)
   * @returns {Object} Result with success status
   */
  updateVoucherSessionStatus: function(sessionId, status, reason = '') {
    try {
      const existing = this.getVoucherSession(sessionId);
      if (!existing) {
        return { success: false, error: 'Session not found: ' + sessionId };
      }
      
      const sheet = this.getSheet(CONFIG.SHEETS.VOUCHER_SESSIONS);
      const rowIndex = existing.rowIndex;
      const now = toIso8601(new Date());
      
      // Update status
      sheet.getRange(rowIndex, 14).setValue(status);
      
      // Set disconnect reason if status is not active
      if (status !== 'active' && reason) {
        sheet.getRange(rowIndex, 15).setValue(reason);
      }
      
      // Set session end time if ending the session
      if (status !== 'active' && !existing.sessionEnd) {
        sheet.getRange(rowIndex, 9).setValue(now);
        
        // Calculate duration if we have a start time
        if (existing.sessionStart) {
          const startTime = new Date(existing.sessionStart);
          const endTime = new Date(now);
          const durationSeconds = Math.floor((endTime - startTime) / 1000);
          sheet.getRange(rowIndex, 10).setValue(durationSeconds);
        }
      }
      
      // Update last updated timestamp
      sheet.getRange(rowIndex, 16).setValue(now);
      
      return { success: true, message: 'Session status updated', sessionId: sessionId, status: status };
    } catch (error) {
      this.addLog('System', 'update_voucher_session_status', 'error', 
        `Failed to update session ${sessionId}: ${error.message}`);
      return { success: false, error: error.message };
    }
  },
  
  /**
   * Get aggregated voucher session statistics
   * 
   * @param {Object} filters - Optional filters (same as getVoucherSessions)
   * @returns {Object} Statistics object
   */
  getVoucherSessionStats: function(filters = {}) {
    const sessions = this.getVoucherSessions({ ...filters, limit: 50000 });
    
    const stats = {
      total: sessions.length,
      byStatus: {
        active: 0,
        expired: 0,
        disconnected: 0
      },
      byFirewall: {},
      byZone: {},
      totalBytesIn: 0,
      totalBytesOut: 0,
      avgDurationSeconds: 0
    };
    
    let totalDuration = 0;
    let durationCount = 0;
    
    sessions.forEach(session => {
      // By status
      const status = session.status || 'active';
      if (stats.byStatus[status] !== undefined) {
        stats.byStatus[status]++;
      }
      
      // By firewall
      const fwId = session.firewallId || 'unknown';
      if (!stats.byFirewall[fwId]) {
        stats.byFirewall[fwId] = {
          name: session.firewallName || fwId,
          count: 0
        };
      }
      stats.byFirewall[fwId].count++;
      
      // By zone
      const zone = session.zone || 'unknown';
      if (!stats.byZone[zone]) {
        stats.byZone[zone] = 0;
      }
      stats.byZone[zone]++;
      
      // Bytes
      stats.totalBytesIn += parseInt(session.bytesIn) || 0;
      stats.totalBytesOut += parseInt(session.bytesOut) || 0;
      
      // Duration (only for completed sessions)
      if (session.durationSeconds && session.durationSeconds > 0) {
        totalDuration += parseInt(session.durationSeconds);
        durationCount++;
      }
    });
    
    // Calculate average duration
    if (durationCount > 0) {
      stats.avgDurationSeconds = Math.round(totalDuration / durationCount);
    }
    
    return stats;
  },
  
  /**
   * Clean up old voucher sessions based on retention period
   * 
   * @param {number} retentionDays - Days to keep (default from config or 90)
   * @returns {Object} Result with count of deleted sessions
   */
  cleanupOldVoucherSessions: function(retentionDays = null) {
    try {
      // Get retention days from config if not provided
      if (retentionDays === null) {
        retentionDays = parseInt(getConfigValue('voucher_session_retention_days', 90));
      }
      
      const cutoffDate = new Date();
      cutoffDate.setDate(cutoffDate.getDate() - retentionDays);
      
      const sheet = this.getSheet(CONFIG.SHEETS.VOUCHER_SESSIONS);
      const data = sheet.getDataRange().getValues();
      
      const rowsToDelete = [];
      
      // Find rows to delete (must be non-active and older than cutoff)
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        const status = row[13];
        const sessionEnd = row[8];
        
        // Only delete completed sessions (expired or disconnected)
        if (status === 'active') continue;
        
        // Check if session end is before cutoff
        if (sessionEnd) {
          const endDate = new Date(sessionEnd);
          if (endDate < cutoffDate) {
            rowsToDelete.push(i + 1); // 1-based row index
          }
        }
      }
      
      // Delete rows in reverse order to maintain correct indices
      rowsToDelete.sort((a, b) => b - a);
      rowsToDelete.forEach(rowIndex => {
        sheet.deleteRow(rowIndex);
      });
      
      if (rowsToDelete.length > 0) {
        this.addLog('System', 'cleanup_voucher_sessions', 'success', 
          `Deleted ${rowsToDelete.length} sessions older than ${retentionDays} days`);
      }
      
      return { 
        success: true, 
        deleted: rowsToDelete.length, 
        retentionDays: retentionDays 
      };
    } catch (error) {
      this.addLog('System', 'cleanup_voucher_sessions', 'error', error.message);
      return { success: false, error: error.message };
    }
  },
  
  /**
   * Batch upsert multiple voucher sessions (for sync efficiency)
   * 
   * @param {Array} sessions - Array of session objects
   * @returns {Object} Result with counts
   */
  batchUpsertVoucherSessions: function(sessions) {
    let added = 0;
    let updated = 0;
    let errors = 0;
    
    sessions.forEach(session => {
      const result = this.upsertVoucherSession(session);
      if (result.success) {
        if (result.message === 'Session added') added++;
        else if (result.message === 'Session updated') updated++;
      } else {
        errors++;
      }
    });
    
    return { success: errors === 0, added, updated, errors };
  }
};
