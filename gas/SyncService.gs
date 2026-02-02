/**
 * SyncService.gs - Sync logic for pfSense MAC Binding Manager
 *
 * Handles scheduled synchronization between Google Sheets and
 * multiple pfSense firewalls.
 */

/**
 * SyncService namespace
 */
const SyncService = {

  // Distributed lock name for sync operations
  SYNC_LOCK_NAME: 'sync_all_firewalls',
  
  /**
   * Sync a single firewall
   * Pulls current status and bindings from pfSense, updates Sheets
   */
  syncFirewall: function(firewallId) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    Logger.log('Syncing firewall: ' + firewall.name);
    const startTime = new Date().getTime();
    
    // Get status
    const statusResult = PfSenseApi.getStatus(firewall);
    
    if (!statusResult.success) {
      // Mark firewall as offline
      SheetsDb.updateFirewall({
        id: firewallId,
        status: 'offline',
        lastSync: new Date().toISOString()
      });
      
      SheetsDb.addLog(firewall.name, 'sync', 'error', 
        'Failed to get status: ' + statusResult.error);
      
      return {
        success: false,
        error: statusResult.error,
        firewall: firewall.name
      };
    }
    
    // Update firewall status
    const statusData = statusResult.data;
    SheetsDb.updateFirewall({
      id: firewallId,
      status: 'online',
      lastSync: new Date().toISOString(),
      activeBindings: statusData.bindings?.total || 0,
      zones: Object.keys(statusData.bindings?.by_zone || {}).join(', ')
    });
    
    // Get bindings
    const bindingsResult = PfSenseApi.getBindings(firewall);
    
    if (!bindingsResult.success) {
      SheetsDb.addLog(firewall.name, 'sync', 'warning', 
        'Failed to get bindings: ' + bindingsResult.error);
      
      return {
        success: true,
        warning: 'Status synced but bindings failed',
        firewall: firewall.name,
        status: statusData
      };
    }
    
    // Sync bindings to Sheets
    const remoteBindings = bindingsResult.data.bindings || [];
    const syncResult = SheetsDb.syncBindings(firewallId, remoteBindings);
    
    const duration = new Date().getTime() - startTime;
    
    SheetsDb.addLog(firewall.name, 'sync', 'success', 
      `Added: ${syncResult.added}, Updated: ${syncResult.updated}, Removed: ${syncResult.removed} (${duration}ms)`);
    
    return {
      success: true,
      firewall: firewall.name,
      status: {
        hostname: statusData.hostname,
        syncEnabled: statusData.sync_enabled,
        totalBindings: statusData.bindings?.total || 0,
        queuePending: statusData.queue?.pending || 0
      },
      sync: syncResult,
      duration: duration
    };
  },
  
  /**
   * Sync all firewalls with per-call timeout protection
   * Uses distributed lock to prevent multiple deployments from syncing simultaneously
   * Called by scheduled trigger
   */
  syncAllFirewalls: function() {
    // Acquire distributed lock to prevent concurrent sync from multiple deployments
    const lockResult = DistributedLock.acquire(this.SYNC_LOCK_NAME, 5 * 60 * 1000);
    if (!lockResult.success) {
      Logger.log('Could not acquire distributed sync lock: ' + lockResult.error);
      return {
        success: false,
        error: 'Another sync is already running',
        details: lockResult
      };
    }

    const lockId = lockResult.lockId;

    try {
      const firewalls = SheetsDb.getFirewalls();

      if (firewalls.length === 0) {
        Logger.log('No firewalls configured');
        return { success: true, message: 'No firewalls configured', results: [] };
      }

      Logger.log('Starting sync for ' + firewalls.length + ' firewalls');
    const startTime = new Date().getTime();
    const maxTotalTime = 5 * 60 * 1000; // 5 minutes total
    const maxPerFirewallTime = 60 * 1000; // 60 seconds per firewall

    const results = [];
    let successCount = 0;
    let errorCount = 0;
    let timeoutCount = 0;

    // Process firewalls in batches to avoid timeout
    const batchSize = CONFIG.SYNC.BATCH_SIZE;

    for (let i = 0; i < firewalls.length; i += batchSize) {
      const batch = firewalls.slice(i, i + batchSize);

      batch.forEach(firewall => {
        const firewallStartTime = new Date().getTime();

        try {
          // Check total elapsed time before starting
          const totalElapsed = new Date().getTime() - startTime;
          if (totalElapsed > maxTotalTime) {
            Logger.log('Total timeout reached, skipping remaining firewalls');
            results.push({
              success: false,
              firewall: firewall.name,
              error: 'Skipped due to total timeout'
            });
            errorCount++;
            return;
          }

          const result = this.syncFirewall(firewall.id);

          // Check if this single sync took too long
          const firewallElapsed = new Date().getTime() - firewallStartTime;
          if (firewallElapsed > maxPerFirewallTime) {
            Logger.log('Firewall ' + firewall.name + ' took ' + firewallElapsed + 'ms (over limit)');
            timeoutCount++;
          }

          results.push(result);

          if (result.success) {
            successCount++;
          } else {
            errorCount++;
          }
        } catch (error) {
          const firewallElapsed = new Date().getTime() - firewallStartTime;
          Logger.log('Sync error for ' + firewall.name + ' after ' + firewallElapsed + 'ms: ' + error.message);

          results.push({
            success: false,
            firewall: firewall.name,
            error: error.message,
            duration: firewallElapsed
          });
          errorCount++;

          // If error happened due to timeout, continue to next firewall
          if (firewallElapsed > maxPerFirewallTime) {
            timeoutCount++;
          }
        }
      });

      // Check if we're running out of time (GAS 6-minute limit)
      const elapsed = new Date().getTime() - startTime;
      if (elapsed > maxTotalTime) {
        Logger.log('Approaching timeout, stopping sync after ' + (i + batchSize) + ' firewalls');
        break;
      }
    }

    const totalDuration = new Date().getTime() - startTime;

    Logger.log(`Sync complete: ${successCount} success, ${errorCount} errors, ${timeoutCount} slow, ${totalDuration}ms`);

    return {
      success: true,
      totalFirewalls: firewalls.length,
      successCount: successCount,
      errorCount: errorCount,
      timeoutCount: timeoutCount,
      duration: totalDuration,
      results: results
    };
    } finally {
      // Always release the distributed lock
      DistributedLock.release(this.SYNC_LOCK_NAME, lockId);
    }
  },
  
  /**
   * Update stale firewall statuses
   * Marks firewalls as offline if they haven't been synced recently
   */
  updateStaleStatuses: function() {
    const firewalls = SheetsDb.getFirewalls();
    const staleThreshold = getConfigValue('stale_threshold_minutes', CONFIG.SYNC.STALE_THRESHOLD_MINUTES);
    const thresholdTime = new Date(Date.now() - staleThreshold * 60 * 1000);
    
    let staleCount = 0;
    
    firewalls.forEach(firewall => {
      if (firewall.status === 'online' && firewall.lastSync) {
        const lastSyncTime = new Date(firewall.lastSync);
        if (lastSyncTime < thresholdTime) {
          SheetsDb.updateFirewall({
            id: firewall.id,
            status: 'stale'
          });
          staleCount++;
        }
      }
    });
    
    if (staleCount > 0) {
      Logger.log('Marked ' + staleCount + ' firewalls as stale');
    }
    
    return { success: true, staleCount: staleCount };
  },
  
  /**
   * Push a binding to pfSense and update local DB
   */
  pushBinding: function(firewallId, binding) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    // Add binding on pfSense
    const result = PfSenseApi.addBinding(firewall, binding);
    
    if (result.success) {
      // Update local DB
      SheetsDb.upsertBinding({
        firewallId: firewallId,
        zone: binding.zone,
        mac: binding.mac,
        expiresAt: result.data.binding?.expires_at || binding.expiresAt,
        ip: binding.ip,
        status: 'active',
        addedBy: 'Manual'
      });
      
      // Update firewall binding count
      const status = PfSenseApi.getStatus(firewall);
      if (status.success) {
        SheetsDb.updateFirewall({
          id: firewallId,
          activeBindings: status.data.bindings?.total || 0
        });
      }
      
      SheetsDb.addLog(firewall.name, 'add_binding', 'success', 
        `MAC: ${binding.mac}, Zone: ${binding.zone}`);
    } else {
      SheetsDb.addLog(firewall.name, 'add_binding', 'error', 
        `MAC: ${binding.mac}, Error: ${result.error}`);
    }
    
    return result;
  },
  
  /**
   * Remove a binding from pfSense and update local DB
   */
  removeRemoteBinding: function(firewallId, mac, zone = null) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    // Remove binding on pfSense
    const result = PfSenseApi.removeBinding(firewall, mac, zone);
    
    if (result.success) {
      // Remove from local DB
      SheetsDb.removeBinding(firewallId, mac);
      
      // Update firewall binding count
      const status = PfSenseApi.getStatus(firewall);
      if (status.success) {
        SheetsDb.updateFirewall({
          id: firewallId,
          activeBindings: status.data.bindings?.total || 0
        });
      }
      
      SheetsDb.addLog(firewall.name, 'remove_binding', 'success', 
        `MAC: ${mac}, Zone: ${zone || 'all'}`);
    } else {
      SheetsDb.addLog(firewall.name, 'remove_binding', 'error', 
        `MAC: ${mac}, Error: ${result.error}`);
    }
    
    return result;
  },
  
  /**
   * Trigger immediate sync on a pfSense firewall
   */
  triggerRemoteSync: function(firewallId) {
    const firewall = SheetsDb.getFirewall(firewallId);
    if (!firewall) {
      return { success: false, error: 'Firewall not found: ' + firewallId };
    }
    
    const result = PfSenseApi.triggerSync(firewall);
    
    if (result.success) {
      SheetsDb.addLog(firewall.name, 'trigger_sync', 'success', 'Sync triggered');
      
      // Wait a moment then sync back
      Utilities.sleep(2000);
      return this.syncFirewall(firewallId);
    } else {
      SheetsDb.addLog(firewall.name, 'trigger_sync', 'error', result.error);
      return result;
    }
  },
  
  /**
   * Get sync status summary for all firewalls
   */
  getSyncStatus: function() {
    const firewalls = SheetsDb.getFirewalls();
    const now = new Date();
    
    const summary = {
      total: firewalls.length,
      online: 0,
      offline: 0,
      stale: 0,
      unknown: 0,
      lastSync: null,
      oldestSync: null
    };
    
    firewalls.forEach(fw => {
      switch (fw.status) {
        case 'online': summary.online++; break;
        case 'offline': summary.offline++; break;
        case 'stale': summary.stale++; break;
        default: summary.unknown++; break;
      }
      
      if (fw.lastSync) {
        const syncTime = new Date(fw.lastSync);
        if (!summary.lastSync || syncTime > summary.lastSync) {
          summary.lastSync = syncTime;
        }
        if (!summary.oldestSync || syncTime < summary.oldestSync) {
          summary.oldestSync = syncTime;
        }
      }
    });
    
    return summary;
  }
};
