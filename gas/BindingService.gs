/**
 * BindingService.gs - MAC binding CRUD operations
 *
 * Handles adding, removing, and managing MAC bindings
 * across multiple pfSense firewalls.
 */

/**
 * BindingService namespace
 */
const BindingService = {

  /**
   * Check if a voucher hash has already been used
   * Used to prevent voucher reuse attacks
   *
   * @param {string} voucherHash - Hash of the voucher code
   * @param {string} firewallId - Firewall ID to check
   * @returns {Object} Result with isUsed flag and existing binding if found
   */
  checkVoucherReuse: function(voucherHash, firewallId) {
    if (!voucherHash) {
      return { isUsed: false };
    }

    // Get all bindings for this firewall
    const bindings = SheetsDb.getBindings(firewallId);

    // Check if any existing binding has this voucher hash
    const existingBinding = bindings.find(b => b.voucherHash === voucherHash);

    if (existingBinding) {
      // Check if the binding is still active (not expired)
      if (existingBinding.expiresAt) {
        const expiresDate = new Date(existingBinding.expiresAt);
        if (expiresDate > new Date()) {
          return {
            isUsed: true,
            existingBinding: existingBinding,
            reason: 'Voucher already used for MAC: ' + existingBinding.mac
          };
        }
      }
    }

    return { isUsed: false };
  },

  /**
   * Add a MAC binding with voucher reuse prevention
   *
   * @param {Object} data - Binding data
   * @param {string} data.firewallId - Target firewall ID
   * @param {string} data.zone - Captive portal zone
   * @param {string} data.mac - MAC address
   * @param {number} data.durationMinutes - Binding duration in minutes
   * @param {string} data.expiresAt - Optional explicit expiry (ISO8601)
   * @param {string} data.ip - Optional IP address
   * @param {string} data.description - Optional description
   * @param {string} data.action - Optional action ('pass' or 'block', default 'pass')
   * @param {string} data.voucherHash - Optional voucher hash for reuse prevention
   * @returns {Object} Result with success flag
   */
  addBinding: function(data) {
    // Validate required fields
    if (!data.firewallId) {
      return { success: false, error: 'Missing required field: firewallId' };
    }
    if (!data.mac) {
      return { success: false, error: 'Missing required field: mac' };
    }

    // Normalize MAC
    const normalizedMac = normalizeMac(data.mac);
    if (!normalizedMac) {
      return { success: false, error: 'Invalid MAC address format' };
    }

    // Get firewall
    const firewall = SheetsDb.getFirewall(data.firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + data.firewallId };
    }

    // Voucher reuse prevention with locking
    if (data.voucherHash) {
      const lock = LockService.getScriptLock();
      try {
        lock.waitLock(10000);
      } catch (e) {
        return { success: false, error: 'Could not acquire lock for voucher validation: ' + e.message };
      }

      try {
        const reuseCheck = this.checkVoucherReuse(data.voucherHash, data.firewallId);
        if (reuseCheck.isUsed) {
          return {
            success: false,
            error: 'Voucher reuse detected',
            reason: reuseCheck.reason,
            existingMac: reuseCheck.existingBinding?.mac
          };
        }
      } finally {
        lock.releaseLock();
      }
    }

    // Calculate expiry
    let expiresAt = data.expiresAt;
    if (!expiresAt) {
      const durationMinutes = data.durationMinutes ||
        getConfigValue('default_duration_minutes', CONFIG.BINDING.DEFAULT_DURATION_MINUTES);
      const expiresDate = new Date(Date.now() + durationMinutes * 60 * 1000);
      expiresAt = expiresDate.toISOString();
    }

    // Validate action if provided
    const action = data.action || 'pass';
    if (action !== 'pass' && action !== 'block') {
      return { success: false, error: 'Invalid action. Must be "pass" or "block"' };
    }

    // Prepare binding object
    const binding = {
      zone: data.zone || 'default',
      mac: normalizedMac,
      durationMinutes: data.durationMinutes || CONFIG.BINDING.DEFAULT_DURATION_MINUTES,
      expiresAt: expiresAt,
      ip: data.ip || null,
      description: data.description || '',
      action: action,
      voucherHash: data.voucherHash || null  // For voucher reuse prevention
    };

    // Push to pfSense
    const result = SyncService.pushBinding(data.firewallId, binding);

    // If successful, also update local Sheets DB with description/action
    if (result.success) {
      SheetsDb.upsertBinding({
        firewallId: data.firewallId,
        zone: binding.zone,
        mac: normalizedMac,
        expiresAt: expiresAt,
        ip: binding.ip,
        description: binding.description,
        action: binding.action,
        voucherHash: binding.voucherHash
      });
    }

    return result;
  },
  
  /**
   * Update an existing MAC binding
   * 
   * @param {Object} data - Update data
   * @param {string} data.firewallId - Target firewall ID
   * @param {string} data.mac - MAC address to update
   * @param {string} data.description - New description (optional)
   * @param {string} data.action - 'pass' or 'block' (optional)
   * @param {string} data.expiresAt - New expiry time (optional)
   * @param {string} data.zone - Zone filter (optional)
   * @returns {Object} Result with success flag
   */
  updateBinding: function(data) {
    if (!data.firewallId) {
      return { success: false, error: 'Missing required field: firewallId' };
    }
    if (!data.mac) {
      return { success: false, error: 'Missing required field: mac' };
    }
    
    const normalizedMac = normalizeMac(data.mac);
    if (!normalizedMac) {
      return { success: false, error: 'Invalid MAC address format' };
    }
    
    // Validate action if provided
    if (data.action !== undefined && data.action !== 'pass' && data.action !== 'block') {
      return { success: false, error: 'Invalid action. Must be "pass" or "block"' };
    }
    
    // Get existing binding from local DB
    const existing = SheetsDb.getBinding(data.firewallId, normalizedMac);
    if (!existing) {
      return { success: false, error: 'Binding not found in local database' };
    }
    
    // Get firewall
    const firewall = SheetsDb.getFirewall(data.firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + data.firewallId };
    }
    
    // Build update payload for pfSense API
    const updatePayload = { 
      mac: normalizedMac,
      zone: data.zone || existing.zone
    };
    
    if (data.description !== undefined) {
      updatePayload.description = data.description;
    }
    if (data.action !== undefined) {
      updatePayload.action = data.action;
    }
    if (data.expiresAt !== undefined) {
      updatePayload.expires_at = data.expiresAt;
    }
    
    // Call pfSense API to update the binding
    const result = PfSenseApi.request(firewall, 'update', 'POST', updatePayload);
    
    if (result.success) {
      // Update local Sheets DB
      const updateData = {
        firewallId: data.firewallId,
        mac: normalizedMac
      };
      
      if (data.description !== undefined) updateData.description = data.description;
      if (data.action !== undefined) updateData.action = data.action;
      if (data.expiresAt !== undefined) updateData.expiresAt = data.expiresAt;
      
      SheetsDb.upsertBinding(updateData);
      
      SheetsDb.addLog(firewall.name, 'update_binding', 'success', 
        'MAC: ' + normalizedMac);
    } else {
      SheetsDb.addLog(firewall.name, 'update_binding', 'error', 
        'MAC: ' + normalizedMac + ' - ' + result.error);
    }
    
    return result;
  },
  
  /**
   * Remove a MAC binding
   * 
   * @param {Object} data - Removal data
   * @param {string} data.firewallId - Target firewall ID
   * @param {string} data.mac - MAC address to remove
   * @param {string} data.zone - Optional zone filter
   * @returns {Object} Result with success flag
   */
  removeBinding: function(data) {
    if (!data.firewallId) {
      return { success: false, error: 'Missing required field: firewallId' };
    }
    if (!data.mac) {
      return { success: false, error: 'Missing required field: mac' };
    }
    
    const normalizedMac = normalizeMac(data.mac);
    if (!normalizedMac) {
      return { success: false, error: 'Invalid MAC address format' };
    }
    
    // Normalize zone: convert empty string to null
    const zone = (data.zone && data.zone.trim() !== '') ? data.zone : null;
    
    // Remove from pfSense and local DB
    return SyncService.removeRemoteBinding(data.firewallId, normalizedMac, zone);
  },
  
  /**
   * Add binding to multiple firewalls
   * 
   * @param {Object} data - Binding data with firewallIds array
   * @returns {Object} Results for each firewall
   */
  addBindingMultiple: function(data) {
    const firewallIds = data.firewallIds || [];
    if (firewallIds.length === 0) {
      return { success: false, error: 'No firewalls specified' };
    }
    
    const results = {};
    let successCount = 0;
    let errorCount = 0;
    
    firewallIds.forEach(firewallId => {
      const bindingData = Object.assign({}, data, { firewallId: firewallId });
      const result = this.addBinding(bindingData);
      results[firewallId] = result;
      
      if (result.success) {
        successCount++;
      } else {
        errorCount++;
      }
    });
    
    return {
      success: errorCount === 0,
      successCount: successCount,
      errorCount: errorCount,
      results: results
    };
  },
  
  /**
   * Remove binding from multiple firewalls
   * 
   * @param {Object} data - Removal data with firewallIds array
   * @returns {Object} Results for each firewall
   */
  removeBindingMultiple: function(data) {
    const firewallIds = data.firewallIds || [];
    if (firewallIds.length === 0) {
      return { success: false, error: 'No firewalls specified' };
    }
    
    const results = {};
    let successCount = 0;
    let errorCount = 0;
    
    firewallIds.forEach(firewallId => {
      const removeData = Object.assign({}, data, { firewallId: firewallId });
      const result = this.removeBinding(removeData);
      results[firewallId] = result;
      
      if (result.success) {
        successCount++;
      } else {
        errorCount++;
      }
    });
    
    return {
      success: errorCount === 0,
      successCount: successCount,
      errorCount: errorCount,
      results: results
    };
  },
  
  /**
   * Cleanup expired bindings from Sheets and pfSense
   * Only removes bindings that are tracked in Sheets WITH an expiration time
   * Uses the same removal method as the Remove button (SyncService.removeRemoteBinding)
   */
  cleanupExpired: function() {
    const expiredBindings = SheetsDb.getExpiredBindings();

    Logger.log('cleanupExpired: Starting - ' + expiredBindings.length + ' expired bindings in Sheets');

    if (expiredBindings.length === 0) {
      return { success: true, removed: 0, pfSenseRemoved: 0, message: 'No expired bindings' };
    }

    let removed = 0;
    let pfSenseRemoved = 0;
    let errors = [];
    const batchSize = CONFIG.BINDING.CLEANUP_BATCH_SIZE;

    // Process in batches
    const toProcess = expiredBindings.slice(0, batchSize);

    // Remove each expired binding using the same method as Remove button
    toProcess.forEach(binding => {
      try {
        Logger.log('cleanupExpired: Removing ' + binding.mac + ' from ' + binding.firewallId + ' (zone: ' + (binding.zone || 'all') + ')');

        // Use SyncService.removeRemoteBinding - same as Remove button
        const result = SyncService.removeRemoteBinding(binding.firewallId, binding.mac, binding.zone || null);

        if (result.success) {
          removed++;
          pfSenseRemoved++;
          Logger.log('cleanupExpired: Successfully removed ' + binding.mac);
        } else {
          Logger.log('cleanupExpired: Failed to remove ' + binding.mac + ' - ' + result.error);
          errors.push({ mac: binding.mac, error: result.error });
        }
      } catch (e) {
        Logger.log('cleanupExpired: Exception removing ' + binding.mac + ' - ' + e.message);
        errors.push({ mac: binding.mac, error: e.message });
      }
    });

    Logger.log('cleanupExpired: Complete - removed ' + removed + ' bindings, ' + errors.length + ' errors');

    if (removed > 0) {
      SheetsDb.addLog('System', 'cleanup_expired', 'success',
        `Removed ${removed} expired bindings from Sheets and pfSense`);
    }

    return {
      success: true,
      removed: removed,
      pfSenseRemoved: pfSenseRemoved,
      errors: errors.length,
      remaining: expiredBindings.length - removed
    };
  },
  
  /**
   * Extend a binding's expiry
   * 
   * @param {Object} data - Extension data
   * @param {string} data.firewallId - Firewall ID
   * @param {string} data.mac - MAC address
   * @param {number} data.additionalMinutes - Minutes to add to expiry
   * @returns {Object} Result with success flag
   */
  extendBinding: function(data) {
    if (!data.firewallId || !data.mac) {
      return { success: false, error: 'Missing firewallId or mac' };
    }
    
    const normalizedMac = normalizeMac(data.mac);
    const binding = SheetsDb.getBinding(data.firewallId, normalizedMac);
    
    if (!binding) {
      return { success: false, error: 'Binding not found' };
    }
    
    // Calculate new expiry
    const currentExpiry = new Date(binding.expiresAt);
    const additionalMs = (data.additionalMinutes || CONFIG.BINDING.DEFAULT_DURATION_MINUTES) * 60 * 1000;
    const newExpiry = new Date(currentExpiry.getTime() + additionalMs);
    
    // Update binding with new expiry (need to add/update on pfSense)
    return this.addBinding({
      firewallId: data.firewallId,
      zone: binding.zone,
      mac: normalizedMac,
      expiresAt: newExpiry.toISOString(),
      ip: binding.ip
    });
  },
  
  /**
   * Get bindings expiring soon
   * 
   * @param {number} withinMinutes - Minutes until expiry
   * @returns {Array} List of expiring bindings
   */
  getExpiringSoon: function(withinMinutes = 60) {
    const bindings = SheetsDb.getBindings();
    const threshold = new Date(Date.now() + withinMinutes * 60 * 1000);
    const now = new Date();
    
    return bindings.filter(b => {
      if (!b.expiresAt) return false;
      const expires = new Date(b.expiresAt);
      return expires > now && expires < threshold;
    });
  },
  
  /**
   * Search bindings across all firewalls
   * 
   * @param {string} query - Search query (MAC or IP)
   * @returns {Array} Matching bindings
   */
  search: function(query) {
    return SheetsDb.searchBindings(query);
  },
  
  /**
   * Get binding statistics
   */
  getStats: function() {
    const bindings = SheetsDb.getBindings();
    const now = new Date();
    
    const stats = {
      total: bindings.length,
      active: 0,
      expired: 0,
      expiringSoon: 0, // Within 1 hour
      byFirewall: {},
      byZone: {}
    };
    
    const oneHour = new Date(now.getTime() + 60 * 60 * 1000);
    
    bindings.forEach(b => {
      // Count by firewall
      const fwId = b.firewallId || 'unknown';
      stats.byFirewall[fwId] = (stats.byFirewall[fwId] || 0) + 1;
      
      // Count by zone
      const zone = b.zone || 'unknown';
      stats.byZone[zone] = (stats.byZone[zone] || 0) + 1;
      
      // Check expiry status
      if (b.expiresAt) {
        const expires = new Date(b.expiresAt);
        if (expires < now) {
          stats.expired++;
        } else if (expires < oneHour) {
          stats.expiringSoon++;
          stats.active++;
        } else {
          stats.active++;
        }
      } else {
        stats.active++;
      }
    });
    
    return stats;
  },
  
  /**
   * Import bindings from CSV
   * CSV format: firewall_id,zone,mac,expires_at,ip
   * 
   * @param {string} csvContent - CSV content
   * @returns {Object} Import results
   */
  importFromCsv: function(csvContent) {
    const lines = csvContent.split('\n');
    let imported = 0;
    let errors = 0;
    const errorDetails = [];
    
    lines.forEach((line, index) => {
      line = line.trim();
      if (!line || line.startsWith('#') || index === 0) {
        // Skip empty lines, comments, and header
        return;
      }
      
      const parts = line.split(',').map(p => p.trim());
      if (parts.length < 3) {
        errors++;
        errorDetails.push(`Line ${index + 1}: Not enough fields`);
        return;
      }
      
      const [firewallId, zone, mac, expiresAt, ip] = parts;
      
      try {
        const result = this.addBinding({
          firewallId: firewallId,
          zone: zone,
          mac: mac,
          expiresAt: expiresAt || null,
          ip: ip || null
        });
        
        if (result.success) {
          imported++;
        } else {
          errors++;
          errorDetails.push(`Line ${index + 1}: ${result.error}`);
        }
      } catch (e) {
        errors++;
        errorDetails.push(`Line ${index + 1}: ${e.message}`);
      }
    });
    
    SheetsDb.addLog('System', 'import_csv', 
      errors === 0 ? 'success' : 'warning',
      `Imported: ${imported}, Errors: ${errors}`);
    
    return {
      success: errors === 0,
      imported: imported,
      errors: errors,
      errorDetails: errorDetails.slice(0, 10) // Limit error details
    };
  },
  
  /**
   * Export bindings to CSV format
   * 
   * @param {string} firewallId - Optional firewall filter
   * @returns {string} CSV content
   */
  exportToCsv: function(firewallId = null) {
    const bindings = SheetsDb.getBindings(firewallId);
    
    const headers = ['firewall_id', 'zone', 'mac', 'expires_at', 'ip', 'last_seen', 'status'];
    const lines = [headers.join(',')];
    
    bindings.forEach(b => {
      lines.push([
        b.firewallId,
        b.zone,
        b.mac,
        b.expiresAt,
        b.ip,
        b.lastSeen,
        b.status
      ].join(','));
    });
    
    return lines.join('\n');
  }
};
