/**
 * Config.gs - Configuration constants for pfSense MAC Binding Manager
 */

/**
 * Global configuration object
 */
const CONFIG = {
  // Google Sheets Configuration
  // Replace with your actual spreadsheet ID after creating it
  SPREADSHEET_ID: '', // Set via SheetsDb.setSpreadsheetId() or manually here
  
  // Sheet names
  SHEETS: {
    FIREWALLS: 'Firewalls',
    BINDINGS: 'Bindings',
    LOGS: 'Logs',
    CONFIG: 'Config',
    VOUCHER_SESSIONS: 'VoucherSessions'
  },
  
  // API request settings
  API: {
    TIMEOUT_MS: 30000,  // 30 seconds
    RETRY_COUNT: 3,
    RETRY_DELAY_MS: 1000
  },
  
  // Sync settings
  SYNC: {
    BATCH_SIZE: 10,           // Process N firewalls per sync batch
    INTERVAL_MINUTES: 5,      // How often to sync
    STALE_THRESHOLD_MINUTES: 15  // Mark firewall offline if not synced in N minutes
  },
  
  // Binding defaults
  BINDING: {
    DEFAULT_DURATION_MINUTES: 43200,  // 30 days
    CLEANUP_BATCH_SIZE: 100
  },
  
  // Logging
  LOG: {
    MAX_ENTRIES: 10000,  // Max log entries to keep
    RETENTION_DAYS: 30   // Days to keep logs
  },
  
  // UI
  UI: {
    PAGE_SIZE: 50,       // Items per page in tables
    REFRESH_INTERVAL_MS: 60000  // Auto-refresh interval
  }
};

/**
 * Get spreadsheet ID from script properties or CONFIG
 */
function getSpreadsheetId() {
  // Try script properties first (allows per-deployment config)
  const props = PropertiesService.getScriptProperties();
  const propId = props.getProperty('SPREADSHEET_ID');
  
  if (propId) {
    return propId;
  }
  
  // Fall back to CONFIG constant
  if (CONFIG.SPREADSHEET_ID) {
    return CONFIG.SPREADSHEET_ID;
  }
  
  // Try to find spreadsheet in same folder as script
  throw new Error('Spreadsheet ID not configured. Set SPREADSHEET_ID in script properties or Config.gs');
}

/**
 * Set spreadsheet ID in script properties
 */
function setSpreadsheetId(spreadsheetId) {
  const props = PropertiesService.getScriptProperties();
  props.setProperty('SPREADSHEET_ID', spreadsheetId);
  Logger.log('Spreadsheet ID set to: ' + spreadsheetId);
}

/**
 * Get configuration value from Config sheet
 */
function getConfigValue(key, defaultValue = null) {
  try {
    const config = SheetsDb.getConfig();
    return config[key] !== undefined ? config[key] : defaultValue;
  } catch (e) {
    return defaultValue;
  }
}

/**
 * Generate a random API key
 */
function generateApiKey() {
  const chars = '0123456789abcdef';
  let key = '';
  for (let i = 0; i < 64; i++) {
    key += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return key;
}

/**
 * Validate MAC address format
 */
function isValidMac(mac) {
  if (!mac || typeof mac !== 'string') return false;
  const normalized = mac.toLowerCase().replace(/[^0-9a-f]/g, '');
  return normalized.length === 12;
}

/**
 * Normalize MAC address to lowercase colon-separated format
 */
function normalizeMac(mac) {
  if (!mac) return '';
  const hex = mac.toLowerCase().replace(/[^0-9a-f]/g, '');
  if (hex.length !== 12) return '';
  return hex.match(/.{2}/g).join(':');
}

/**
 * Format date to ISO8601
 */
function toIso8601(date) {
  if (!date) return '';
  if (typeof date === 'string') {
    date = new Date(date);
  }
  return date.toISOString();
}

/**
 * Parse ISO8601 string to Date
 */
function parseIso8601(dateStr) {
  if (!dateStr) return null;
  const date = new Date(dateStr);
  return isNaN(date.getTime()) ? null : date;
}

// ============================================
// Distributed Lock (Properties-based mutex)
// ============================================

/**
 * Distributed lock implementation using Script Properties
 * Prevents multiple script deployments from running simultaneously
 */
const DistributedLock = {
  LOCK_KEY_PREFIX: 'DIST_LOCK_',
  DEFAULT_TIMEOUT_MS: 300000, // 5 minutes default
  STALE_THRESHOLD_MS: 360000, // 6 minutes - locks older than this are considered stale

  /**
   * Acquire a distributed lock
   * @param {string} lockName - Name of the lock (e.g., 'sync', 'backup')
   * @param {number} timeoutMs - Lock timeout in milliseconds
   * @returns {Object} Result with success flag and lockId if acquired
   */
  acquire: function(lockName, timeoutMs = this.DEFAULT_TIMEOUT_MS) {
    const props = PropertiesService.getScriptProperties();
    const lockKey = this.LOCK_KEY_PREFIX + lockName;
    const lockId = Utilities.getUuid();
    const now = new Date().getTime();

    // Check for existing lock
    const existingLockData = props.getProperty(lockKey);
    if (existingLockData) {
      try {
        const lock = JSON.parse(existingLockData);
        const lockAge = now - lock.timestamp;

        // Check if lock is stale (held too long or crashed process)
        if (lockAge < this.STALE_THRESHOLD_MS) {
          Logger.log('Distributed lock "' + lockName + '" already held by ' + lock.lockId);
          return { success: false, error: 'Lock already held', existingLockId: lock.lockId, age: lockAge };
        }

        // Lock is stale, clear it
        Logger.log('Clearing stale distributed lock "' + lockName + '" (age: ' + lockAge + 'ms)');
      } catch (e) {
        // Invalid lock data, clear it
        Logger.log('Clearing invalid distributed lock data for "' + lockName + '"');
      }
    }

    // Acquire lock
    const newLockData = {
      lockId: lockId,
      timestamp: now,
      expires: now + timeoutMs,
      holder: Session.getActiveUser().getEmail() || 'system'
    };

    props.setProperty(lockKey, JSON.stringify(newLockData));

    // Verify we got the lock (handle race condition)
    Utilities.sleep(50); // Brief pause to allow concurrent acquires to settle
    const verifyData = props.getProperty(lockKey);
    if (verifyData) {
      try {
        const verified = JSON.parse(verifyData);
        if (verified.lockId !== lockId) {
          // Another process won the race
          return { success: false, error: 'Lost lock race', winnerId: verified.lockId };
        }
      } catch (e) {
        return { success: false, error: 'Lock verification failed' };
      }
    }

    Logger.log('Acquired distributed lock "' + lockName + '" with id ' + lockId);
    return { success: true, lockId: lockId, lockName: lockName };
  },

  /**
   * Release a distributed lock
   * @param {string} lockName - Name of the lock
   * @param {string} lockId - Lock ID returned from acquire
   * @returns {Object} Result with success flag
   */
  release: function(lockName, lockId) {
    const props = PropertiesService.getScriptProperties();
    const lockKey = this.LOCK_KEY_PREFIX + lockName;

    const existingLockData = props.getProperty(lockKey);
    if (!existingLockData) {
      return { success: true, message: 'Lock already released' };
    }

    try {
      const lock = JSON.parse(existingLockData);
      if (lock.lockId !== lockId) {
        Logger.log('Cannot release lock "' + lockName + '" - wrong lockId');
        return { success: false, error: 'Lock owned by different process' };
      }
    } catch (e) {
      // Invalid lock data, just delete it
    }

    props.deleteProperty(lockKey);
    Logger.log('Released distributed lock "' + lockName + '"');
    return { success: true };
  },

  /**
   * Check if a lock is held
   * @param {string} lockName - Name of the lock
   * @returns {Object} Lock status
   */
  isLocked: function(lockName) {
    const props = PropertiesService.getScriptProperties();
    const lockKey = this.LOCK_KEY_PREFIX + lockName;
    const now = new Date().getTime();

    const existingLockData = props.getProperty(lockKey);
    if (!existingLockData) {
      return { locked: false };
    }

    try {
      const lock = JSON.parse(existingLockData);
      const lockAge = now - lock.timestamp;

      if (lockAge >= this.STALE_THRESHOLD_MS) {
        return { locked: false, stale: true };
      }

      return {
        locked: true,
        lockId: lock.lockId,
        holder: lock.holder,
        age: lockAge,
        remainingMs: lock.expires - now
      };
    } catch (e) {
      return { locked: false, invalid: true };
    }
  },

  /**
   * Force clear a lock (admin function)
   * @param {string} lockName - Name of the lock
   */
  forceClear: function(lockName) {
    const props = PropertiesService.getScriptProperties();
    const lockKey = this.LOCK_KEY_PREFIX + lockName;
    props.deleteProperty(lockKey);
    Logger.log('Force cleared distributed lock "' + lockName + '"');
    return { success: true };
  }
};

/**
 * Calculate remaining time as human-readable string
 */
function formatRemaining(expiresAt) {
  if (!expiresAt) return 'N/A';
  
  const now = new Date();
  const expires = new Date(expiresAt);
  const diff = expires - now;
  
  if (diff <= 0) return 'Expired';
  
  const days = Math.floor(diff / (24 * 60 * 60 * 1000));
  const hours = Math.floor((diff % (24 * 60 * 60 * 1000)) / (60 * 60 * 1000));
  const minutes = Math.floor((diff % (60 * 60 * 1000)) / (60 * 1000));
  
  if (days > 0) {
    return days + 'd ' + hours + 'h';
  } else if (hours > 0) {
    return hours + 'h ' + minutes + 'm';
  } else {
    return minutes + 'm';
  }
}
