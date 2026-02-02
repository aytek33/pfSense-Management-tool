/**
 * PfSenseApi.gs - HTTP client for pfSense API communication
 * 
 * Handles all HTTP requests to pfSense firewalls with retry logic,
 * error handling, and response parsing.
 */

/**
 * PfSenseApi namespace
 */
const PfSenseApi = {
  
  /**
   * Make HTTP request to pfSense API with retry logic
   * 
   * @param {Object} firewall - Firewall object with url and apiKey
   * @param {string} action - API action (status, bindings, add, remove, etc.)
   * @param {string} method - HTTP method (GET or POST)
   * @param {Object} payload - Request body for POST requests
   * @returns {Object} Response object with success flag and data/error
   */
  request: function(firewall, action, method = 'GET', payload = null) {
    const url = this.buildUrl(firewall.url, action);
    
    // Always include api_key in body as fallback (nginx proxy may strip X-API-Key header)
    const bodyPayload = payload ? { ...payload, api_key: firewall.apiKey } : { api_key: firewall.apiKey };
    
    // Log payload for debugging (especially for backup requests)
    if (action === 'backup' && payload) {
      Logger.log('PfSenseApi: Sending backup payload - skip_rrd=' + bodyPayload.skip_rrd + 
                 ' (type: ' + typeof bodyPayload.skip_rrd + ')');
      Logger.log('PfSenseApi: Full payload keys: ' + Object.keys(bodyPayload).join(', '));
    }
    
    const jsonPayload = JSON.stringify(bodyPayload);
    
    // Log JSON string for debugging (truncate if too long)
    if (action === 'backup') {
      const preview = jsonPayload.length > 200 ? jsonPayload.substring(0, 200) + '...' : jsonPayload;
      Logger.log('PfSenseApi: JSON payload preview: ' + preview);
    }
    
    const options = {
      method: 'POST', // Always use POST to send api_key in body
      headers: {
        'X-API-Key': firewall.apiKey, // Keep header for direct connections
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      payload: jsonPayload,
      muteHttpExceptions: true,
      validateHttpsCertificates: false, // pfSense often uses self-signed certs
      followRedirects: true
    };
    
    // Retry logic
    const maxRetries = CONFIG.API.RETRY_COUNT;
    const retryDelay = CONFIG.API.RETRY_DELAY_MS;
    let lastError = null;
    
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
      try {
        const response = UrlFetchApp.fetch(url, options);
        const statusCode = response.getResponseCode();
        const responseText = response.getContentText();
        
        // Parse JSON response
        let data;
        try {
          data = JSON.parse(responseText);
        } catch (e) {
          data = { raw: responseText };
        }
        
        // Check for HTTP errors
        if (statusCode >= 400) {
          const errorMsg = data.error || `HTTP ${statusCode}`;
          
          // Don't retry on client errors (4xx)
          if (statusCode < 500) {
            return {
              success: false,
              error: errorMsg,
              statusCode: statusCode,
              firewall: firewall.id
            };
          }
          
          throw new Error(errorMsg);
        }
        
        // Success
        if (action === 'backup' && data) {
          const backupSize = data.size_bytes || 0;
          const backupSizeKB = Math.round(backupSize / 1024);
          Logger.log('PfSenseApi: Backup response received - size: ' + backupSizeKB + ' KB, status: ' + statusCode);
        }
        
        return {
          success: true,
          data: data,
          statusCode: statusCode,
          firewall: firewall.id
        };
        
      } catch (error) {
        lastError = error;
        Logger.log(`pfSense API error (attempt ${attempt}/${maxRetries}): ${error.message}`);
        
        if (attempt < maxRetries) {
          Utilities.sleep(retryDelay * attempt); // Exponential backoff
        }
      }
    }
    
    // All retries failed
    return {
      success: false,
      error: lastError ? lastError.message : 'Unknown error',
      firewall: firewall.id
    };
  },
  
  /**
   * Build API URL
   */
  buildUrl: function(baseUrl, action, params = {}) {
    // Ensure base URL ends without slash
    baseUrl = baseUrl.replace(/\/+$/, '');
    
    // Build URL with action
    let url = `${baseUrl}/macbind_api.php?action=${encodeURIComponent(action)}`;
    
    // Add query parameters
    Object.keys(params).forEach(key => {
      if (params[key] !== null && params[key] !== undefined) {
        url += `&${encodeURIComponent(key)}=${encodeURIComponent(params[key])}`;
      }
    });
    
    return url;
  },
  
  // =========================================================================
  // API METHODS
  // =========================================================================
  
  /**
   * Get status from pfSense
   */
  getStatus: function(firewall) {
    return this.request(firewall, 'status', 'GET');
  },
  
  /**
   * Get all bindings from pfSense
   */
  getBindings: function(firewall, zone = null) {
    const action = zone ? `bindings&zone=${encodeURIComponent(zone)}` : 'bindings';
    return this.request(firewall, action, 'GET');
  },
  
  /**
   * Get zones from pfSense
   */
  getZones: function(firewall) {
    return this.request(firewall, 'zones', 'GET');
  },
  
  /**
   * Add MAC binding on pfSense
   */
  addBinding: function(firewall, binding) {
    const payload = {
      zone: binding.zone,
      mac: binding.mac,
      duration_minutes: binding.durationMinutes || CONFIG.BINDING.DEFAULT_DURATION_MINUTES,
      expires_at: binding.expiresAt || null,
      ip: binding.ip || null
    };

    // Include voucher_hash if provided (for voucher reuse prevention)
    if (binding.voucherHash) {
      payload.voucher_hash = binding.voucherHash;
    }

    // Include description if provided
    if (binding.description) {
      payload.description = binding.description;
    }

    return this.request(firewall, 'add', 'POST', payload);
  },
  
  /**
   * Remove MAC binding from pfSense
   */
  removeBinding: function(firewall, mac, zone = null) {
    return this.request(firewall, 'remove', 'POST', {
      mac: mac,
      zone: zone
    });
  },
  
  /**
   * Trigger sync on pfSense
   */
  triggerSync: function(firewall) {
    return this.request(firewall, 'sync', 'POST');
  },
  
  /**
   * Cleanup expired bindings on pfSense
   */
  cleanupExpired: function(firewall) {
    return this.request(firewall, 'cleanup_expired', 'POST', {});
  },
  
  /**
   * Search bindings on pfSense
   */
  searchBindings: function(firewall, query) {
    return this.request(firewall, `search&q=${encodeURIComponent(query)}`, 'GET');
  },
  
  // =========================================================================
  // HELPER METHODS
  // =========================================================================
  
  /**
   * Test connection to pfSense
   */
  testConnection: function(firewallId) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    const startTime = new Date().getTime();
    const result = this.getStatus(firewall);
    const responseTime = new Date().getTime() - startTime;
    
    if (result.success) {
      // Update firewall status in sheet
      SheetsDb.updateFirewall({
        id: firewallId,
        status: 'online',
        lastSync: new Date().toISOString()
      });
      
      SheetsDb.addLog(firewall.name, 'test_connection', 'success', 
        `Response time: ${responseTime}ms`);
      
      return {
        success: true,
        message: 'Connection successful',
        responseTime: responseTime,
        hostname: result.data.hostname,
        version: result.data.version,
        bindingCount: result.data.bindings?.total || 0,
        zones: result.data.bindings?.by_zone || {}
      };
    } else {
      // Update firewall status in sheet
      SheetsDb.updateFirewall({
        id: firewallId,
        status: 'offline'
      });
      
      SheetsDb.addLog(firewall.name, 'test_connection', 'error', result.error);
      
      return {
        success: false,
        error: result.error
      };
    }
  },
  
  /**
   * Get firewall by ID and validate it exists
   */
  getFirewallOrError: function(firewallId) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      throw new Error('Firewall not found: ' + firewallId);
    }
    return firewall;
  },
  
  /**
   * Process multiple firewalls in parallel (up to batch size)
   * Note: GAS doesn't support true parallelism, but this helps organize batch operations
   */
  batchRequest: function(firewalls, action, method = 'GET', payload = null) {
    const results = {};
    
    firewalls.forEach(firewall => {
      try {
        results[firewall.id] = this.request(firewall, action, method, payload);
      } catch (error) {
        results[firewall.id] = {
          success: false,
          error: error.message,
          firewall: firewall.id
        };
      }
    });
    
    return results;
  },
  
  /**
   * Check if firewall is reachable (quick check)
   */
  ping: function(firewall) {
    try {
      const url = this.buildUrl(firewall.url, 'status');
      const response = UrlFetchApp.fetch(url, {
        method: 'POST', // Use POST to send api_key in body
        headers: { 
          'X-API-Key': firewall.apiKey,
          'Content-Type': 'application/json'
        },
        payload: JSON.stringify({ api_key: firewall.apiKey }),
        muteHttpExceptions: true,
        validateHttpsCertificates: false,
        followRedirects: false
      });
      return response.getResponseCode() < 400;
    } catch (e) {
      return false;
    }
  }
};
