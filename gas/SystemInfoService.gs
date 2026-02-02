/**
 * SystemInfoService.gs - Firewall system monitoring and metrics
 * 
 * Fetches and caches system information from pfSense firewalls
 * including uptime, NTP status, CPU/RAM usage, disk, and admin logins.
 */

/**
 * SystemInfoService namespace
 */
const SystemInfoService = {
  
  // Cache duration in seconds (5 minutes)
  CACHE_DURATION_SECONDS: 300,
  
  /**
   * Get system information for a single firewall
   * 
   * @param {string} firewallId - Firewall ID
   * @param {boolean} useCache - Whether to use cached data (default true)
   * @returns {Object} System info with success flag
   */
  getFirewallSystemInfo: function(firewallId, useCache = true) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    // Check cache first
    if (useCache) {
      const cached = this.getCachedSystemInfo(firewallId);
      if (cached) {
        return { success: true, data: cached, cached: true };
      }
    }
    
    // Fetch fresh data from pfSense
    const result = PfSenseApi.request(firewall, 'sysinfo', 'GET');
    
    if (!result.success) {
      return { 
        success: false, 
        error: result.error,
        firewallId: firewallId,
        firewallName: firewall.name
      };
    }
    
    const sysinfo = result.data;
    
    // Add metadata
    sysinfo.firewallId = firewallId;
    sysinfo.firewallName = firewall.name;
    sysinfo.fetchedAt = new Date().toISOString();
    
    // Cache the result
    this.cacheSystemInfo(firewallId, sysinfo);
    
    // Track admin logins in sheet
    if (sysinfo.last_admin_logins && sysinfo.last_admin_logins.length > 0) {
      this.recordAdminLogins(firewallId, firewall.name, sysinfo.last_admin_logins);
    }
    
    return { success: true, data: sysinfo, cached: false };
  },
  
  /**
   * Get system info for all firewalls (batch)
   * 
   * @param {boolean} onlineOnly - Only fetch from online firewalls (default true)
   * @returns {Object} Results for all firewalls
   */
  getFirewallsWithSystemInfo: function(onlineOnly = true) {
    const firewalls = SheetsDb.getFirewalls();
    const results = [];
    let successCount = 0;
    let errorCount = 0;
    
    firewalls.forEach(firewall => {
      // Skip offline firewalls if onlineOnly is true
      if (onlineOnly && firewall.status !== 'online' && firewall.status !== 'unknown') {
        results.push({
          firewallId: firewall.id,
          firewallName: firewall.name,
          success: false,
          error: 'Firewall is ' + firewall.status,
          skipped: true
        });
        return;
      }
      
      try {
        const result = this.getFirewallSystemInfo(firewall.id);
        results.push({
          firewallId: firewall.id,
          firewallName: firewall.name,
          ...result
        });
        
        if (result.success) {
          successCount++;
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
    
    return {
      success: errorCount === 0,
      totalFirewalls: firewalls.length,
      successCount: successCount,
      errorCount: errorCount,
      results: results
    };
  },
  
  /**
   * Get cached system info for a firewall
   * 
   * @param {string} firewallId - Firewall ID
   * @returns {Object|null} Cached data or null if expired/not found
   */
  getCachedSystemInfo: function(firewallId) {
    const cache = CacheService.getScriptCache();
    const cacheKey = 'sysinfo_' + firewallId;
    const cached = cache.get(cacheKey);
    
    if (cached) {
      try {
        return JSON.parse(cached);
      } catch (e) {
        return null;
      }
    }
    
    return null;
  },
  
  /**
   * Cache system info for a firewall
   * 
   * @param {string} firewallId - Firewall ID
   * @param {Object} sysinfo - System info data
   */
  cacheSystemInfo: function(firewallId, sysinfo) {
    const cache = CacheService.getScriptCache();
    const cacheKey = 'sysinfo_' + firewallId;
    
    try {
      cache.put(cacheKey, JSON.stringify(sysinfo), this.CACHE_DURATION_SECONDS);
    } catch (e) {
      Logger.log('Failed to cache system info: ' + e.message);
    }
  },
  
  /**
   * Clear cached system info for a firewall
   * 
   * @param {string} firewallId - Firewall ID (or null to clear all)
   */
  clearCache: function(firewallId = null) {
    const cache = CacheService.getScriptCache();
    
    if (firewallId) {
      cache.remove('sysinfo_' + firewallId);
    } else {
      // Clear all sysinfo caches
      const firewalls = SheetsDb.getFirewalls();
      firewalls.forEach(fw => {
        cache.remove('sysinfo_' + fw.id);
      });
    }
  },
  
  /**
   * Record admin logins in AdminLogins sheet
   * 
   * @param {string} firewallId - Firewall ID
   * @param {string} firewallName - Firewall name
   * @param {Array} logins - Array of login records from pfSense
   */
  recordAdminLogins: function(firewallId, firewallName, logins) {
    const sheet = this.getAdminLoginsSheet();
    
    // Get existing login timestamps to avoid duplicates
    const existingData = sheet.getDataRange().getValues();
    const existingKeys = new Set();
    
    for (let i = 1; i < existingData.length; i++) {
      const row = existingData[i];
      // Create a key from firewallId + user + time to detect duplicates
      const key = row[1] + '_' + row[2] + '_' + row[4];
      existingKeys.add(key);
    }
    
    // Add new logins
    const now = new Date().toISOString();
    let added = 0;
    
    logins.forEach(login => {
      const key = firewallId + '_' + login.user + '_' + login.time;
      if (!existingKeys.has(key)) {
        sheet.appendRow([
          now,                    // Recorded timestamp
          firewallId,             // Firewall ID
          login.user,             // Username
          login.ip || 'unknown',  // Source IP
          login.time,             // Login time from pfSense
          login.method || 'unknown', // Login method (ssh/webui)
          firewallName            // Firewall name for display
        ]);
        added++;
      }
    });
    
    if (added > 0) {
      Logger.log('Recorded ' + added + ' admin logins for ' + firewallName);
    }
    
    // Trim old entries (keep last 1000)
    const maxEntries = 1000;
    const rowCount = sheet.getLastRow();
    if (rowCount > maxEntries + 1) {
      const toDelete = rowCount - maxEntries - 1;
      sheet.deleteRows(2, toDelete);
    }
  },
  
  /**
   * Get or create AdminLogins sheet
   */
  getAdminLoginsSheet: function() {
    const ss = SheetsDb.getSpreadsheet();
    let sheet = ss.getSheetByName('AdminLogins');
    
    if (!sheet) {
      sheet = ss.insertSheet('AdminLogins');
      sheet.appendRow([
        'Recorded_At', 'Firewall_ID', 'User', 'Source_IP', 
        'Login_Time', 'Method', 'Firewall_Name'
      ]);
      sheet.getRange(1, 1, 1, 7).setFontWeight('bold').setBackground('#e91e63').setFontColor('white');
      sheet.setFrozenRows(1);
      Logger.log('Created AdminLogins sheet');
    }
    
    return sheet;
  },
  
  /**
   * Get recent admin logins from sheet
   * 
   * @param {string} firewallId - Optional firewall filter
   * @param {number} limit - Maximum entries to return
   * @returns {Array} Login records
   */
  getAdminLogins: function(firewallId = null, limit = 50) {
    const sheet = this.getAdminLoginsSheet();
    const data = sheet.getDataRange().getValues();
    
    if (data.length <= 1) return [];
    
    const logins = [];
    for (let i = data.length - 1; i >= 1 && logins.length < limit; i--) {
      const row = data[i];
      
      if (firewallId && row[1] !== firewallId) continue;
      
      logins.push({
        recordedAt: row[0],
        firewallId: row[1],
        user: row[2],
        sourceIp: row[3],
        loginTime: row[4],
        method: row[5],
        firewallName: row[6]
      });
    }
    
    return logins;
  },
  
  /**
   * Get dashboard summary with system info for all firewalls
   * 
   * @returns {Object} Dashboard data
   */
  getDashboardSummary: function() {
    const firewalls = SheetsDb.getFirewalls();
    const dashboard = {
      totalFirewalls: firewalls.length,
      onlineCount: 0,
      offlineCount: 0,
      firewalls: []
    };
    
    firewalls.forEach(firewall => {
      const sysinfo = this.getCachedSystemInfo(firewall.id);
      
      const fwData = {
        id: firewall.id,
        name: firewall.name,
        status: firewall.status,
        lastSync: firewall.lastSync,
        activeBindings: firewall.activeBindings
      };
      
      if (sysinfo) {
        fwData.uptime = sysinfo.uptime;
        fwData.ntpStatus = sysinfo.ntp_status;
        fwData.cpu = sysinfo.cpu;
        fwData.memory = sysinfo.memory;
        fwData.disk = sysinfo.disk;
        fwData.version = sysinfo.system ? sysinfo.system.version : null;
        fwData.cachedAt = sysinfo.fetchedAt;
      }
      
      if (firewall.status === 'online') {
        dashboard.onlineCount++;
      } else {
        dashboard.offlineCount++;
      }
      
      dashboard.firewalls.push(fwData);
    });
    
    return dashboard;
  }
};

/**
 * Wrapper function for google.script.run
 */
function getFirewallSystemInfo(firewallId) {
  return SystemInfoService.getFirewallSystemInfo(firewallId);
}

/**
 * Wrapper function for google.script.run
 */
function getFirewallsWithSystemInfo() {
  return SystemInfoService.getFirewallsWithSystemInfo();
}

/**
 * Wrapper function for google.script.run
 */
function getDashboardSummary() {
  return SystemInfoService.getDashboardSummary();
}

/**
 * Wrapper function for google.script.run
 */
function getAdminLogins(firewallId, limit) {
  return SystemInfoService.getAdminLogins(firewallId, limit);
}
