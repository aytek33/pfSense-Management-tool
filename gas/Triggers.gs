/**
 * Triggers.gs - Time-based trigger setup for pfSense MAC Binding Manager
 * 
 * This file contains functions to set up and manage automated
 * synchronization triggers.
 */

/**
 * Create all required triggers
 * Run this function once after deploying the script
 */
function setupTriggers() {
  // First, remove any existing triggers to avoid duplicates
  removeAllTriggers();

  // Create sync trigger (every 5 minutes)
  ScriptApp.newTrigger('scheduledSync')
    .timeBased()
    .everyMinutes(5)
    .create();

  // Create cleanup trigger (every 15 minutes for timely expiration)
  ScriptApp.newTrigger('cleanupExpiredBindings')
    .timeBased()
    .everyMinutes(15)
    .create();

  // Create stale status update trigger (every 15 minutes)
  ScriptApp.newTrigger('updateStaleStatuses')
    .timeBased()
    .everyMinutes(15)
    .create();

  // Create scheduled backup checker trigger (every hour to check for due backups)
  // This checks the ScheduledBackups sheet and runs any due backups
  ScriptApp.newTrigger('runScheduledBackups')
    .timeBased()
    .everyHours(1)
    .create();

  // Create daily self-healing tests trigger (runs at 3 AM)
  ScriptApp.newTrigger('scheduledSelfHealingTests')
    .timeBased()
    .atHour(3)
    .everyDays(1)
    .create();

  Logger.log('Triggers created successfully');
  return {
    success: true,
    message: 'Triggers created: scheduledSync (5min), cleanupExpiredBindings (15min), updateStaleStatuses (15min), runScheduledBackups (hourly), scheduledSelfHealingTests (daily 3AM)'
  };
}

/**
 * Remove all existing triggers for this script
 */
function removeAllTriggers() {
  const triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(trigger => {
    ScriptApp.deleteTrigger(trigger);
  });
  Logger.log('Removed ' + triggers.length + ' existing triggers');
  return { success: true, removed: triggers.length };
}

/**
 * List all current triggers
 */
function listTriggers() {
  const triggers = ScriptApp.getProjectTriggers();
  const triggerInfo = triggers.map(trigger => ({
    functionName: trigger.getHandlerFunction(),
    eventType: trigger.getEventType().toString(),
    triggerSource: trigger.getTriggerSource().toString(),
    triggerId: trigger.getUniqueId()
  }));
  
  Logger.log('Current triggers: ' + JSON.stringify(triggerInfo));
  return { success: true, triggers: triggerInfo };
}

/**
 * Update stale firewall statuses
 * Called by scheduled trigger
 */
function updateStaleStatuses() {
  try {
    const result = SyncService.updateStaleStatuses();
    Logger.log('Stale status update: ' + JSON.stringify(result));
  } catch (error) {
    Logger.log('Stale status update error: ' + error.message);
  }
}

/**
 * Create custom sync interval trigger
 * 
 * @param {number} minutes - Interval in minutes (1, 5, 10, 15, or 30)
 */
function createCustomSyncTrigger(minutes) {
  // Validate interval
  const validIntervals = [1, 5, 10, 15, 30];
  if (!validIntervals.includes(minutes)) {
    return { 
      success: false, 
      error: 'Invalid interval. Must be one of: ' + validIntervals.join(', ') 
    };
  }
  
  // Remove existing sync trigger
  const triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(trigger => {
    if (trigger.getHandlerFunction() === 'scheduledSync') {
      ScriptApp.deleteTrigger(trigger);
    }
  });
  
  // Create new trigger
  ScriptApp.newTrigger('scheduledSync')
    .timeBased()
    .everyMinutes(minutes)
    .create();
  
  // Update config
  SheetsDb.setConfig('sync_interval_minutes', minutes);
  
  Logger.log('Created sync trigger with ' + minutes + ' minute interval');
  return { success: true, message: 'Sync trigger set to ' + minutes + ' minutes' };
}

/**
 * Pause all sync operations
 * Removes sync triggers but keeps cleanup
 */
function pauseSync() {
  const triggers = ScriptApp.getProjectTriggers();
  let removed = 0;
  
  triggers.forEach(trigger => {
    if (trigger.getHandlerFunction() === 'scheduledSync' || 
        trigger.getHandlerFunction() === 'updateStaleStatuses') {
      ScriptApp.deleteTrigger(trigger);
      removed++;
    }
  });
  
  SheetsDb.addLog('System', 'pause_sync', 'success', 'Removed ' + removed + ' sync triggers');
  return { success: true, message: 'Sync paused', removedTriggers: removed };
}

/**
 * Resume sync operations
 * Recreates sync triggers
 */
function resumeSync() {
  // Get configured interval
  const interval = parseInt(getConfigValue('sync_interval_minutes', 5));
  
  // Create sync trigger
  ScriptApp.newTrigger('scheduledSync')
    .timeBased()
    .everyMinutes(interval)
    .create();
  
  // Create stale status trigger
  ScriptApp.newTrigger('updateStaleStatuses')
    .timeBased()
    .everyMinutes(15)
    .create();
  
  SheetsDb.addLog('System', 'resume_sync', 'success', 'Created sync triggers');
  return { success: true, message: 'Sync resumed with ' + interval + ' minute interval' };
}

/**
 * Check if sync is currently enabled
 */
function isSyncEnabled() {
  const triggers = ScriptApp.getProjectTriggers();
  const hasSyncTrigger = triggers.some(t => t.getHandlerFunction() === 'scheduledSync');
  return { enabled: hasSyncTrigger };
}

/**
 * One-time initialization function
 * Sets up the spreadsheet and creates initial configuration
 */
function initializeApp() {
  try {
    // Create/initialize all sheets
    SheetsDb.initializeAllSheets();
    
    // Set up triggers
    setupTriggers();
    
    // Log initialization
    SheetsDb.addLog('System', 'initialize', 'success', 'Application initialized');
    
    return { 
      success: true, 
      message: 'Application initialized successfully. Add your first firewall to get started.' 
    };
  } catch (error) {
    Logger.log('Initialization error: ' + error.message);
    return { success: false, error: error.message };
  }
}

/**
 * Get current trigger status
 */
function getTriggerStatus() {
  const triggers = ScriptApp.getProjectTriggers();
  
  const status = {
    syncEnabled: false,
    syncInterval: null,
    cleanupEnabled: false,
    staleCheckEnabled: false,
    backupEnabled: false,
    totalTriggers: triggers.length
  };
  
  triggers.forEach(trigger => {
    const func = trigger.getHandlerFunction();
    if (func === 'scheduledSync') {
      status.syncEnabled = true;
    } else if (func === 'cleanupExpiredBindings') {
      status.cleanupEnabled = true;
    } else if (func === 'updateStaleStatuses') {
      status.staleCheckEnabled = true;
    } else if (func === 'scheduledBackup') {
      status.backupEnabled = true;
    }
  });
  
  status.syncInterval = parseInt(getConfigValue('sync_interval_minutes', 5));
  
  return status;
}

/**
 * Enable scheduled backups
 * 
 * @param {number} hour - Hour of day to run backup (0-23), default 2 AM
 */
function enableScheduledBackup(hour = 2) {
  // Remove existing backup trigger
  const triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(trigger => {
    if (trigger.getHandlerFunction() === 'scheduledBackup') {
      ScriptApp.deleteTrigger(trigger);
    }
  });
  
  // Create new backup trigger
  ScriptApp.newTrigger('scheduledBackup')
    .timeBased()
    .atHour(hour)
    .everyDays(1)
    .create();
  
  SheetsDb.setConfig('scheduled_backup_enabled', 'true');
  SheetsDb.setConfig('scheduled_backup_hour', hour);
  SheetsDb.addLog('System', 'enable_backup', 'success', `Scheduled backup enabled at ${hour}:00`);
  
  return { success: true, message: `Scheduled backup enabled at ${hour}:00 daily` };
}

/**
 * Disable scheduled backups
 */
function disableScheduledBackup() {
  const triggers = ScriptApp.getProjectTriggers();
  let removed = 0;
  
  triggers.forEach(trigger => {
    if (trigger.getHandlerFunction() === 'scheduledBackup') {
      ScriptApp.deleteTrigger(trigger);
      removed++;
    }
  });
  
  SheetsDb.setConfig('scheduled_backup_enabled', 'false');
  SheetsDb.addLog('System', 'disable_backup', 'success', 'Scheduled backup disabled');
  
  return { success: true, message: 'Scheduled backup disabled', removed: removed };
}

/**
 * Set backup retention count
 *
 * @param {number} count - Number of backups to keep per firewall
 */
function setBackupRetention(count) {
  if (count < 1 || count > 100) {
    return { success: false, error: 'Retention count must be between 1 and 100' };
  }

  SheetsDb.setConfig('backup_keep_count', count);
  return { success: true, message: `Backup retention set to ${count} per firewall` };
}

/**
 * Scheduled self-healing tests - called by daily trigger
 * Runs system integrity tests and auto-fixes common issues
 */
function scheduledSelfHealingTests() {
  try {
    Logger.log('Starting scheduled self-healing tests...');
    const result = runSelfHealingTests();

    // Log summary
    const passedCount = result.tests.filter(t => t.passed).length;
    const totalCount = result.tests.length;
    const fixedCount = result.autoFixed.length;

    Logger.log(`Self-healing tests complete: ${passedCount}/${totalCount} passed, ${fixedCount} auto-fixed`);

    // If there were failures that weren't auto-fixed, log them as warnings
    if (passedCount < totalCount) {
      const failedTests = result.tests.filter(t => !t.passed);
      failedTests.forEach(test => {
        Logger.log(`Test FAILED: ${test.name} - Issues: ${test.issues.join(', ')}`);
      });
    }

    return result;
  } catch (error) {
    Logger.log('Scheduled self-healing tests error: ' + error.message);
    SheetsDb.addLog('System', 'self_healing_tests', 'error', error.message);
    return { success: false, error: error.message };
  }
}

/**
 * Enable daily self-healing tests
 *
 * @param {number} hour - Hour of day to run tests (0-23), default 3 AM
 */
function enableSelfHealingTests(hour = 3) {
  // Remove existing self-test trigger
  const triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(trigger => {
    if (trigger.getHandlerFunction() === 'scheduledSelfHealingTests') {
      ScriptApp.deleteTrigger(trigger);
    }
  });

  // Create new trigger
  ScriptApp.newTrigger('scheduledSelfHealingTests')
    .timeBased()
    .atHour(hour)
    .everyDays(1)
    .create();

  SheetsDb.addLog('System', 'enable_selftest', 'success', `Self-healing tests enabled at ${hour}:00 daily`);

  return { success: true, message: `Self-healing tests enabled at ${hour}:00 daily` };
}

/**
 * Disable daily self-healing tests
 */
function disableSelfHealingTests() {
  const triggers = ScriptApp.getProjectTriggers();
  let removed = 0;

  triggers.forEach(trigger => {
    if (trigger.getHandlerFunction() === 'scheduledSelfHealingTests') {
      ScriptApp.deleteTrigger(trigger);
      removed++;
    }
  });

  SheetsDb.addLog('System', 'disable_selftest', 'success', 'Self-healing tests disabled');

  return { success: true, message: 'Self-healing tests disabled', removed: removed };
}
