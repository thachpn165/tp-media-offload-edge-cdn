/**
 * Admin JavaScript for CloudFlare R2 Offload & CDN
 */

import '../scss/admin.scss';

(function ($) {
  'use strict';

  const CFR2OffLoadAdmin = {
    /**
     * Initialize the admin module.
     */
    init() {
      this.cacheElements();
      this.bindEvents();
      // Hide save button on Dashboard tab and Bulk Actions tab.
      $('.cloudflare-r2-offload-cdn-form-actions').hide();
      this.progressInterval = null;
      this.activityLogInterval = null;
      this.bulkStartTime = null;
      this.bulkStats = { completed: 0, failed: 0, total: 0 };
      this.isProcessing = false;
      this.currentAction = 'offload'; // 'offload' or 'restore'
    },

    /**
     * Cache DOM elements.
     */
    cacheElements() {
      this.$tabs = $('.cloudflare-r2-offload-cdn-tabs li');
      this.$tabContents = $('.cloudflare-r2-offload-cdn-tab-content');
      this.$form = $('#cloudflare-r2-offload-cdn-settings-form');
      this.$saveBtn = $('.cloudflare-r2-offload-cdn-save-btn');
      this.$toast = $('#cloudflare-r2-offload-cdn-toast');
    },

    /**
     * Bind event handlers.
     */
    bindEvents() {
      this.$tabs.on('click', this.handleTabClick.bind(this));
      this.$form.on('submit', this.handleFormSubmit.bind(this));
      $(document).on('click', '.cloudflare-r2-offload-cdn-accordion-header', this.handleAccordionClick);
      $('#test-r2-connection').on('click', this.handleTestR2Connection.bind(this));
      $('#cfr2-bulk-offload-all').on('click', this.handleBulkOffload.bind(this));
      $('#cfr2-bulk-restore-all').on('click', this.handleBulkRestore.bind(this));
      $('#cfr2-bulk-delete-local').on('click', this.handleBulkDeleteLocal.bind(this));
      $('#cfr2-cancel-bulk').on('click', this.handleCancelBulk.bind(this));
      $('#cdn_enabled').on('change', this.handleCDNToggle.bind(this));
      $('#smart_sizes').on('change', this.handleSmartSizesToggle.bind(this));
      $('#quality').on('input', this.handleQualityChange.bind(this));
      $('#deploy-worker').on('click', this.handleDeployWorker.bind(this));
      $('#remove-worker').on('click', this.handleRemoveWorker.bind(this));
      $('#cfr2-retry-all-failed').on('click', this.handleRetryFailed.bind(this));
      $('#cfr2-retry-all').on('click', this.handleRetryFailed.bind(this));
      $('#cfr2-clear-log').on('click', this.handleClearLog.bind(this));
      $(document).on('click', '.cfr2-retry-single', this.handleRetrySingle.bind(this));
      $('#goto-bulk-actions').on('click', this.handleGotoBulkActions.bind(this));

      // Media attachment detail page offload button.
      $(document).on('click', '.cfr2-offload-btn', this.handleAttachmentOffload.bind(this));

      // CDN DNS validation.
      $('#validate-cdn-dns').on('click', this.handleValidateCdnDns.bind(this));
      $(document).on('click', '.cfr2-enable-proxy-btn', this.handleEnableDnsProxy.bind(this));

      // Pending items management.
      $('#cfr2-pending-stat').on('click', this.handleShowPending.bind(this));
      $('#cfr2-close-pending').on('click', this.handleClosePending.bind(this));
      $('#cfr2-clear-pending').on('click', this.handleClearPending.bind(this));
      $(document).on('click', '.cfr2-cancel-pending-item', this.handleCancelPendingItem.bind(this));
    },

    /**
     * Handle tab click.
     *
     * @param {Event} e Click event.
     */
    handleTabClick(e) {
      const $tab = $(e.currentTarget);
      const tabId = $tab.data('tab');

      // Update active tab.
      this.$tabs.removeClass('active');
      $tab.addClass('active');

      // Show corresponding content.
      this.$tabContents.removeClass('active');
      $('#tab-' + tabId).addClass('active');

      // Hide save button on Dashboard tab and Bulk Actions tab.
      $('.cloudflare-r2-offload-cdn-form-actions').toggle(tabId !== 'dashboard' && tabId !== 'bulk-actions');

      // Load activity log and refresh counts if on bulk actions tab.
      if (tabId === 'bulk-actions') {
        this.loadActivityLog();
        this.refreshButtonCounts();
        this.checkBulkProgress();
      }
    },

    /**
     * Handle accordion header click.
     *
     * @param {Event} e Click event.
     */
    handleAccordionClick(e) {
      const $header = $(e.currentTarget);
      const $content = $header.next('.cloudflare-r2-offload-cdn-accordion-content');

      $header.toggleClass('active');
      $content.toggleClass('active');
    },

    /**
     * Handle form submit (AJAX save).
     *
     * @param {Event} e Submit event.
     */
    handleFormSubmit(e) {
      e.preventDefault();

      if (this.$saveBtn.hasClass('is-loading')) {
        return;
      }

      this.setLoading(true);

      const formData = this.$form.serialize();

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: formData + '&action=cfr2_save_settings',
        success: this.handleSaveSuccess.bind(this),
        error: this.handleSaveError.bind(this),
        complete: () => this.setLoading(false),
      });
    },

    /**
     * Handle successful save.
     *
     * @param {Object} response AJAX response.
     */
    handleSaveSuccess(response) {
      if (response.success) {
        this.showToast(response.data.message, 'success');
      } else {
        this.showToast(response.data.message || cfr2Admin.strings.error, 'error');
      }
    },

    /**
     * Handle save error.
     */
    handleSaveError() {
      this.showToast(cfr2Admin.strings.error, 'error');
    },

    /**
     * Set loading state.
     *
     * @param {boolean} isLoading Loading state.
     */
    setLoading(isLoading) {
      this.$saveBtn.toggleClass('is-loading', isLoading);
    },

    /**
     * Show toast notification.
     *
     * @param {string} message Toast message.
     * @param {string} type    Toast type (success|error).
     */
    showToast(message, type = 'success') {
      const icon = type === 'success' ? 'yes-alt' : 'warning';
      const $toast = $('<div>', { class: `cloudflare-r2-offload-cdn-toast-item ${type}` }).append(
        $('<span>', { class: `dashicons dashicons-${icon}` }),
        $('<span>').text(message) // Use .text() to prevent XSS
      );

      this.$toast.append($toast);

      // Auto-remove after 3 seconds.
      setTimeout(() => {
        $toast.addClass('fade-out');
        setTimeout(() => $toast.remove(), 300);
      }, 3000);
    },

    /**
     * Handle R2 connection test.
     *
     * @param {Event} e Click event.
     */
    handleTestR2Connection(e) {
      e.preventDefault();

      const $btn = $(e.currentTarget);
      const $result = $('#r2-connection-result');

      if ($btn.hasClass('is-loading')) {
        return;
      }

      $btn.addClass('is-loading').prop('disabled', true);
      $result.html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_test_r2',
          cfr2_nonce: $('#cfr2_nonce').val(),
          r2_account_id: $('#r2_account_id').val(),
          r2_access_key_id: $('#r2_access_key_id').val(),
          r2_secret_access_key: $('#r2_secret_access_key').val(),
          r2_bucket: $('#r2_bucket').val(),
        },
        success: (response) => {
          if (response.success) {
            $result.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
            this.showToast(response.data.message, 'success');
          } else {
            $result.html('<span style="color: #dc3232;">✗ ' + response.data.message + '</span>');
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          $result.html('<span style="color: #dc3232;">✗ Connection failed</span>');
          this.showToast('Connection test failed', 'error');
        },
        complete: () => {
          $btn.removeClass('is-loading').prop('disabled', false);
        },
      });
    },

    /**
     * Handle bulk offload.
     *
     * @param {Event} e Click event.
     */
    handleBulkOffload(e) {
      e.preventDefault();

      if (!confirm('Queue all media files for offload to R2?')) {
        return;
      }

      const $btn = $(e.currentTarget);
      $btn.prop('disabled', true).text('Queuing...');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_bulk_offload_all',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            this.terminalLog('info', `Queued ${response.data.total} files for offload.`);
            this.bulkStats = { completed: 0, failed: 0, total: response.data.total };
            this.startBulkProcessing();
          } else {
            this.terminalLog('error', response.data.message);
            this.showToast(response.data.message, 'error');
            $btn.prop('disabled', false).text('Offload All Media');
          }
        },
        error: () => {
          this.terminalLog('error', 'Failed to queue files.');
          this.showToast('Failed to start bulk offload', 'error');
          $btn.prop('disabled', false).text('Offload All Media');
        },
      });
    },

    /**
     * Handle bulk restore.
     *
     * @param {Event} e Click event.
     */
    handleBulkRestore(e) {
      e.preventDefault();

      if (!confirm('Restore all media files from R2 to local?')) {
        return;
      }

      const $btn = $(e.currentTarget);
      $btn.prop('disabled', true).text('Queuing...');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_bulk_restore_all',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            this.terminalLog('info', `Queued ${response.data.total} files for restore.`);
            this.bulkStats = { completed: 0, failed: 0, total: response.data.total };
            this.currentAction = 'restore';
            this.startBulkProcessing();
          } else {
            this.terminalLog('error', response.data.message);
            this.showToast(response.data.message, 'error');
            $btn.prop('disabled', false).text($btn.data('original-text') || 'Restore All');
          }
        },
        error: () => {
          this.terminalLog('error', 'Failed to queue files for restore.');
          this.showToast('Failed to start bulk restore', 'error');
          $btn.prop('disabled', false).text($btn.data('original-text') || 'Restore All');
        },
      });
    },

    /**
     * Handle bulk delete local (disk saving).
     *
     * @param {Event} e Click event.
     */
    handleBulkDeleteLocal(e) {
      e.preventDefault();

      if (!confirm('Delete local files for all offloaded media? Files will remain on R2. This cannot be undone.')) {
        return;
      }

      const $btn = $(e.currentTarget);
      $btn.prop('disabled', true).text('Queuing...');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_bulk_delete_local',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            this.terminalLog('info', `Queued ${response.data.total} files for local deletion.`);
            this.bulkStats = { completed: 0, failed: 0, total: response.data.total };
            this.currentAction = 'delete_local';
            this.startBulkProcessing();
          } else {
            this.terminalLog('error', response.data.message);
            this.showToast(response.data.message, 'error');
            $btn.prop('disabled', false).text($btn.data('original-text') || 'Free Disk Space');
          }
        },
        error: () => {
          this.terminalLog('error', 'Failed to queue files for deletion.');
          this.showToast('Failed to start bulk delete', 'error');
          $btn.prop('disabled', false).text($btn.data('original-text') || 'Free Disk Space');
        },
      });
    },

    /**
     * Handle cancel bulk.
     *
     * @param {Event} e Click event.
     */
    handleCancelBulk(e) {
      e.preventDefault();

      if (!confirm('Cancel bulk operation?')) {
        return;
      }

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_cancel_bulk',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            this.terminalLog('warning', 'Bulk operation cancelled by user.');
            this.showToast(response.data.message, 'success');
            this.stopBulkProcessing();
          } else {
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          this.showToast('Failed to cancel bulk operation', 'error');
        },
      });
    },

    /**
     * Start bulk processing via AJAX.
     */
    startBulkProcessing() {
      this.isProcessing = true;
      this.bulkStartTime = Date.now();

      $('#cfr2-bulk-offload-all').hide();
      $('#cfr2-bulk-restore-all').hide();
      $('#cfr2-bulk-delete-local').hide();
      $('#cfr2-retry-all-failed').hide();
      $('#cfr2-cancel-bulk').show();
      $('#cfr2-bulk-progress-section').show();

      const actionTexts = { offload: 'offload', restore: 'restore', delete_local: 'local file deletion' };
      const actionText = actionTexts[this.currentAction] || 'offload';
      this.terminalLog('info', `Starting ${actionText} process...`);
      this.updateProgressDisplay();

      // Start processing loop.
      this.processNextItem();

      // Update elapsed time every second.
      this.progressInterval = setInterval(() => {
        this.updateElapsedTime();
      }, 1000);
    },

    /**
     * Stop bulk processing.
     */
    stopBulkProcessing() {
      this.isProcessing = false;

      if (this.progressInterval) {
        clearInterval(this.progressInterval);
        this.progressInterval = null;
      }

      $('#cfr2-bulk-offload-all').show().prop('disabled', false);
      $('#cfr2-bulk-restore-all').show().prop('disabled', false);
      $('#cfr2-bulk-delete-local').show().prop('disabled', false);
      $('#cfr2-retry-all-failed').show();
      $('#cfr2-cancel-bulk').hide();

      this.bulkStartTime = null;
      this.currentAction = 'offload'; // Reset to default.

      // Refresh button counts via AJAX instead of page reload.
      this.refreshButtonCounts();
    },

    /**
     * Refresh button counts via AJAX.
     */
    refreshButtonCounts() {
      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_get_bulk_counts',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            const data = response.data;

            // Update Offload All button.
            const $offloadBtn = $('#cfr2-bulk-offload-all');
            if (data.not_offloaded > 0) {
              $offloadBtn.show().text(`Offload All Media (${data.not_offloaded})`);
            } else {
              $offloadBtn.hide();
            }

            // Update Restore All button.
            const $restoreBtn = $('#cfr2-bulk-restore-all');
            if (data.offloaded > 0) {
              $restoreBtn.show().text(`Restore All (${data.offloaded})`);
            } else {
              $restoreBtn.hide();
            }

            // Update Free Disk Space button.
            const $deleteLocalBtn = $('#cfr2-bulk-delete-local');
            if (data.disk_saveable > 0) {
              $deleteLocalBtn.show().text(`Free Disk Space (${data.disk_saveable})`);
            } else {
              $deleteLocalBtn.hide();
            }
          }
        },
      });
    },

    /**
     * Process next item in queue via AJAX.
     */
    processNextItem() {
      if (!this.isProcessing) {
        return;
      }

      const ajaxActions = {
        offload: 'cfr2_process_bulk_item',
        restore: 'cfr2_process_restore_item',
        delete_local: 'cfr2_process_delete_local_item',
      };
      const actionTexts = { offload: 'offload', restore: 'restore', delete_local: 'delete local' };
      const ajaxAction = ajaxActions[this.currentAction] || 'cfr2_process_bulk_item';
      const actionText = actionTexts[this.currentAction] || 'offload';

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: ajaxAction,
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            const data = response.data;

            if (data.done) {
              // All done or cancelled.
              this.terminalLog('info', data.message);
              this.terminalLog('info', `Completed: ${this.bulkStats.completed} | Failed: ${this.bulkStats.failed}`);
              this.showToast(`Bulk ${actionText} completed`, 'success');
              this.stopBulkProcessing();
              return;
            }

            // Log the result.
            const logType = data.status === 'success' ? 'success' : 'error';
            this.terminalLog(logType, `${data.filename} - ${data.message}`);

            // Update stats.
            if (data.status === 'success') {
              this.bulkStats.completed++;
            } else {
              this.bulkStats.failed++;
            }

            this.updateProgressDisplay();

            // Process next item immediately.
            this.processNextItem();
          } else {
            this.terminalLog('error', response.data.message || 'Unknown error');
            this.stopBulkProcessing();
          }
        },
        error: () => {
          this.terminalLog('error', 'Network error. Retrying in 3 seconds...');
          // Retry after delay.
          setTimeout(() => this.processNextItem(), 3000);
        },
      });
    },

    /**
     * Update progress display.
     */
    updateProgressDisplay() {
      const { completed, failed, total } = this.bulkStats;
      const processed = completed + failed;
      // Cap percentage at 100% to handle edge cases (retry operations).
      const percentage = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
      const remaining = Math.max(0, total - processed);

      $('.cfr2-progress-fill').css('width', percentage + '%');
      $('.cfr2-progress-percentage').text(percentage + '%');
      $('.cfr2-progress-text').html(
        `<span style="color: #46b450;">✓ ${completed}</span> | ` +
        `<span style="color: #dc3232;">✗ ${failed}</span> | ` +
        `<span style="color: #646970;">○ ${remaining} remaining</span>`
      );
    },

    /**
     * Update elapsed time display.
     */
    updateElapsedTime() {
      if (this.bulkStartTime) {
        const elapsed = Math.floor((Date.now() - this.bulkStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        $('#cfr2-elapsed').text(`${minutes}m ${seconds}s`);
      }
    },

    /**
     * Log message to terminal.
     *
     * @param {string} type    Log type (info|success|error|warning).
     * @param {string} message Log message.
     */
    terminalLog(type, message) {
      const $terminal = $('#cfr2-terminal-output');
      const time = new Date().toLocaleTimeString();
      const icons = {
        info: '●',
        success: '✓',
        error: '✗',
        warning: '⚠',
      };

      const $line = $(`
        <div class="cfr2-terminal-line cfr2-terminal-${type}">
          <span class="cfr2-terminal-time">[${time}]</span>
          <span class="cfr2-terminal-icon">${icons[type] || '●'}</span>
          <span class="cfr2-terminal-message">${this.escapeHtml(message)}</span>
        </div>
      `);

      $terminal.append($line);
      $terminal.scrollTop($terminal[0].scrollHeight);
    },

    /**
     * Escape HTML to prevent XSS.
     *
     * @param {string} text Text to escape.
     * @return {string} Escaped text.
     */
    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    },

    /**
     * Handle CDN toggle.
     *
     * @param {Event} e Change event.
     */
    handleCDNToggle(e) {
      const isEnabled = $(e.currentTarget).is(':checked');
      $('.cdn-fields').toggle(isEnabled);
    },

    /**
     * Handle smart sizes toggle.
     *
     * @param {Event} e Change event.
     */
    handleSmartSizesToggle(e) {
      const isEnabled = $(e.currentTarget).is(':checked');
      $('.smart-sizes-options').toggle(isEnabled);
    },

    /**
     * Handle quality slider change.
     *
     * @param {Event} e Input event.
     */
    handleQualityChange(e) {
      const value = $(e.currentTarget).val();
      $('#quality-value').text(value);
    },

    /**
     * Handle deploy worker.
     *
     * @param {Event} e Click event.
     */
    handleDeployWorker(e) {
      e.preventDefault();

      const $btn = $(e.currentTarget);
      const $result = $('#worker-deploy-result');

      if ($btn.hasClass('is-loading')) {
        return;
      }

      $btn.addClass('is-loading').prop('disabled', true);
      $result.html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_deploy_worker',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            $result.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
            this.showToast(response.data.message, 'success');
            $('#remove-worker').show();
            // Refresh page to update status
            setTimeout(() => location.reload(), 1500);
          } else {
            $result.html('<span style="color: #dc3232;">✗ ' + response.data.message + '</span>');
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          $result.html('<span style="color: #dc3232;">✗ Deployment failed</span>');
          this.showToast('Worker deployment failed', 'error');
        },
        complete: () => {
          $btn.removeClass('is-loading').prop('disabled', false);
        },
      });
    },

    /**
     * Handle remove worker.
     *
     * @param {Event} e Click event.
     */
    handleRemoveWorker(e) {
      e.preventDefault();

      if (!confirm('Remove deployed Worker? This will disable CDN URL rewriting.')) {
        return;
      }

      const $btn = $(e.currentTarget);
      const $result = $('#worker-deploy-result');

      if ($btn.hasClass('is-loading')) {
        return;
      }

      $btn.addClass('is-loading').prop('disabled', true);
      $result.html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_remove_worker',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            $result.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
            this.showToast(response.data.message, 'success');
            $btn.hide();
            // Refresh page to update status
            setTimeout(() => location.reload(), 1500);
          } else {
            $result.html('<span style="color: #dc3232;">✗ ' + response.data.message + '</span>');
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          $result.html('<span style="color: #dc3232;">✗ Remove failed</span>');
          this.showToast('Worker removal failed', 'error');
        },
        complete: () => {
          $btn.removeClass('is-loading').prop('disabled', false);
        },
      });
    },

    /**
     * Load activity log into terminal.
     */
    loadActivityLog() {
      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_get_activity_log',
          nonce: $('#cfr2_nonce').val(),
          limit: 50,
        },
        success: (response) => {
          if (response.success && response.data.logs.length > 0) {
            const $terminal = $('#cfr2-terminal-output');

            // Only load if terminal is empty (except initial message).
            if ($terminal.children().length <= 1) {
              response.data.logs.reverse().forEach((entry) => {
                const type = entry.status === 'success' ? 'success' : 'error';
                const time = new Date(entry.timestamp * 1000).toLocaleTimeString();
                const icons = { success: '✓', error: '✗' };

                const $line = $(`
                  <div class="cfr2-terminal-line cfr2-terminal-${type}">
                    <span class="cfr2-terminal-time">[${time}]</span>
                    <span class="cfr2-terminal-icon">${icons[type]}</span>
                    <span class="cfr2-terminal-message">${this.escapeHtml(entry.filename)} - ${this.escapeHtml(entry.message)}</span>
                  </div>
                `);

                $terminal.append($line);
              });

              $terminal.scrollTop($terminal[0].scrollHeight);
            }
          }
        },
      });
    },

    /**
     * Check bulk progress on page load.
     * Note: Auto-resume disabled to prevent issues with stale queue data.
     * Users should manually click buttons to start operations.
     */
    checkBulkProgress() {
      // Auto-resume disabled - was causing issues with incorrect counts
      // and infinite loops when queue had items from different action types.
    },

    /**
     * Load failed items.
     */
    loadFailedItems() {
      // This would query failed items from queue and display in error summary.
      // For now, we'll check if there are any failed items via the retry button visibility.
    },

    /**
     * Handle retry failed.
     *
     * @param {Event} e Click event.
     */
    handleRetryFailed(e) {
      e.preventDefault();

      if (!confirm('Retry all failed items?')) {
        return;
      }

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_retry_failed',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            this.terminalLog('info', `Queued ${response.data.queued} items for retry.`);
            this.bulkStats = { completed: 0, failed: 0, total: response.data.queued };
            this.startBulkProcessing();
          } else {
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          this.showToast('Failed to retry items', 'error');
        },
      });
    },

    /**
     * Handle retry single.
     *
     * @param {Event} e Click event.
     */
    handleRetrySingle(e) {
      e.preventDefault();

      const attachmentId = $(e.currentTarget).data('attachment-id');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_retry_single',
          nonce: $('#cfr2_nonce').val(),
          attachment_id: attachmentId,
        },
        success: (response) => {
          if (response.success) {
            this.showToast(response.data.message, 'success');
            $(e.currentTarget).closest('.cfr2-error-item').fadeOut();
          } else {
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          this.showToast('Failed to retry item', 'error');
        },
      });
    },

    /**
     * Handle offload from attachment details page.
     *
     * @param {Event} e Click event.
     */
    handleAttachmentOffload(e) {
      e.preventDefault();

      const $btn = $(e.currentTarget);
      const attachmentId = $btn.data('id');
      const nonce = $btn.data('nonce');
      const $status = $btn.siblings('.cfr2-offload-status');

      // Disable button and show loading.
      $btn.prop('disabled', true).text('Offloading...');
      $status.html('<span class="spinner is-active" style="float: none;"></span>');

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
          action: 'cfr2_offload_attachment',
          attachment_id: attachmentId,
          nonce: nonce,
        },
        success: (response) => {
          if (response.success) {
            $status.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
            $btn.hide();

            // Update the status display after a short delay.
            setTimeout(() => {
              $btn.closest('td').find('span').first().html(
                '<span style="color: #46b450; font-weight: bold;">' +
                '<span class="dashicons dashicons-cloud" style="vertical-align: middle;"></span> ' +
                'Offloaded to R2</span>' +
                (response.data.url ? '<br><small style="color: #666;">' + response.data.url + '</small>' : '')
              );
              $status.empty();
            }, 2000);
          } else {
            $status.html('<span style="color: #dc3232;">✗ ' + response.data.message + '</span>');
            $btn.prop('disabled', false).text('Offload to R2');
          }
        },
        error: () => {
          $status.html('<span style="color: #dc3232;">✗ Request failed</span>');
          $btn.prop('disabled', false).text('Offload to R2');
        },
      });
    },

    /**
     * Handle clear log.
     *
     * @param {Event} e Click event.
     */
    handleClearLog(e) {
      e.preventDefault();

      if (!confirm('Clear process log?')) {
        return;
      }

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_clear_log',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            this.showToast(response.data.message, 'success');
            $('#cfr2-terminal-output').html(`
              <div class="cfr2-terminal-line cfr2-terminal-info">
                <span class="cfr2-terminal-prompt">$</span>
                <span>Ready. Click "Offload All Media" to start.</span>
              </div>
            `);
          } else {
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          this.showToast('Failed to clear log', 'error');
        },
      });
    },

    /**
     * Handle goto bulk actions tab.
     *
     * @param {Event} e Click event.
     */
    handleGotoBulkActions(e) {
      e.preventDefault();

      // Trigger tab click.
      $('.cloudflare-r2-offload-cdn-tabs li[data-tab="bulk-actions"]').trigger('click');
    },

    /**
     * Handle CDN DNS validation.
     *
     * @param {Event} e Click event.
     */
    handleValidateCdnDns(e) {
      e.preventDefault();

      const $btn = $(e.currentTarget);
      const $status = $('#cdn-dns-status');
      const cdnUrl = $('#cdn_url').val();

      if (!cdnUrl) {
        this.showToast('Please enter a CDN URL first', 'error');
        return;
      }

      if ($btn.hasClass('is-loading')) {
        return;
      }

      $btn.addClass('is-loading').prop('disabled', true).text('Validating...');
      $status.show().html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span> Checking DNS...');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_validate_cdn_dns',
          nonce: $('#cfr2_nonce').val(),
          cdn_url: cdnUrl,
        },
        success: (response) => {
          if (response.success) {
            const data = response.data;

            if (data.action === 'created') {
              // DNS record was created.
              $status.html(
                '<div class="cfr2-dns-success">' +
                '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ' +
                data.message +
                '</div>'
              );
              this.showToast(data.message, 'success');
            } else if (data.action === 'exists') {
              // DNS record exists.
              if (data.warnings && data.warnings.length > 0) {
                // Has warnings - proxy disabled.
                $status.html(
                  '<div class="cfr2-dns-warning">' +
                  '<span class="dashicons dashicons-warning" style="color: #dba617;"></span> ' +
                  data.warnings.join('<br>') +
                  '<br><button type="button" class="button cfr2-enable-proxy-btn" ' +
                  'data-zone-id="' + data.zone_id + '" data-record-id="' + data.record_id + '">' +
                  'Enable Proxy Now</button>' +
                  '</div>'
                );
                this.showToast('DNS record found but needs configuration', 'error');
              } else {
                // All good.
                $status.html(
                  '<div class="cfr2-dns-success">' +
                  '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ' +
                  'DNS record found with proxy enabled. Ready to deploy!' +
                  '</div>'
                );
                this.showToast('DNS validation passed!', 'success');
              }
            }
          } else {
            $status.html(
              '<div class="cfr2-dns-error">' +
              '<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ' +
              response.data.message +
              '</div>'
            );
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          $status.html(
            '<div class="cfr2-dns-error">' +
            '<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ' +
            'Validation request failed' +
            '</div>'
          );
          this.showToast('DNS validation failed', 'error');
        },
        complete: () => {
          $btn.removeClass('is-loading').prop('disabled', false).text('Validate DNS');
        },
      });
    },

    /**
     * Handle enable DNS proxy.
     *
     * @param {Event} e Click event.
     */
    handleEnableDnsProxy(e) {
      e.preventDefault();

      const $btn = $(e.currentTarget);
      const zoneId = $btn.data('zone-id');
      const recordId = $btn.data('record-id');
      const $status = $('#cdn-dns-status');

      if ($btn.hasClass('is-loading')) {
        return;
      }

      $btn.addClass('is-loading').prop('disabled', true).text('Enabling...');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_enable_dns_proxy',
          nonce: $('#cfr2_nonce').val(),
          zone_id: zoneId,
          record_id: recordId,
        },
        success: (response) => {
          if (response.success) {
            $status.html(
              '<div class="cfr2-dns-success">' +
              '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ' +
              response.data.message + ' Ready to deploy!' +
              '</div>'
            );
            this.showToast(response.data.message, 'success');
          } else {
            this.showToast(response.data.message, 'error');
            $btn.removeClass('is-loading').prop('disabled', false).text('Enable Proxy Now');
          }
        },
        error: () => {
          this.showToast('Failed to enable proxy', 'error');
          $btn.removeClass('is-loading').prop('disabled', false).text('Enable Proxy Now');
        },
      });
    },

    /**
     * Handle show pending items.
     *
     * @param {Event} e Click event.
     */
    handleShowPending(e) {
      e.preventDefault();

      const $section = $('#cfr2-pending-section');
      $section.show();
      this.loadPendingItems();
    },

    /**
     * Handle close pending section.
     *
     * @param {Event} e Click event.
     */
    handleClosePending(e) {
      e.preventDefault();
      $('#cfr2-pending-section').hide();
    },

    /**
     * Load pending items via AJAX.
     */
    loadPendingItems() {
      const $list = $('#cfr2-pending-list');
      $list.html('<p class="cfr2-loading">Loading...</p>');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_get_pending_items',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            const items = response.data.items;

            if (items.length === 0) {
              $list.html('<p class="cfr2-empty">No pending items in queue.</p>');
              return;
            }

            let html = '<table class="cfr2-pending-table"><thead><tr>' +
              '<th>File</th><th>Action</th><th>Status</th><th></th>' +
              '</tr></thead><tbody>';

            items.forEach((item) => {
              const statusClass = item.status === 'processing' ? 'cfr2-status-processing' : 'cfr2-status-pending';
              const canCancel = item.status === 'pending';
              const safeId = parseInt(item.id, 10) || 0;

              html += `<tr data-id="${safeId}">
                <td class="cfr2-pending-filename">${this.escapeHtml(item.filename)}</td>
                <td><span class="cfr2-action-badge">${this.escapeHtml(item.action)}</span></td>
                <td><span class="${statusClass}">${this.escapeHtml(item.status)}</span></td>
                <td>${canCancel ? `<button type="button" class="button button-small cfr2-cancel-pending-item" data-id="${safeId}">Cancel</button>` : '<span class="cfr2-processing-indicator">●</span>'}</td>
              </tr>`;
            });

            html += '</tbody></table>';
            $list.html(html);
          } else {
            $list.html('<p class="cfr2-error">Failed to load pending items.</p>');
          }
        },
        error: () => {
          $list.html('<p class="cfr2-error">Failed to load pending items.</p>');
        },
      });
    },

    /**
     * Handle cancel single pending item.
     *
     * @param {Event} e Click event.
     */
    handleCancelPendingItem(e) {
      e.preventDefault();

      const $btn = $(e.currentTarget);
      const itemId = $btn.data('id');
      const $row = $btn.closest('tr');

      $btn.prop('disabled', true).text('Cancelling...');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_cancel_pending_item',
          nonce: $('#cfr2_nonce').val(),
          item_id: itemId,
        },
        success: (response) => {
          if (response.success) {
            $row.fadeOut(300, () => {
              $row.remove();
              // Check if table is empty.
              if ($('#cfr2-pending-list tbody tr').length === 0) {
                $('#cfr2-pending-list').html('<p class="cfr2-empty">No pending items in queue.</p>');
              }
            });
            this.showToast(response.data.message, 'success');
            this.updatePendingStat();
          } else {
            this.showToast(response.data.message, 'error');
            $btn.prop('disabled', false).text('Cancel');
          }
        },
        error: () => {
          this.showToast('Failed to cancel item', 'error');
          $btn.prop('disabled', false).text('Cancel');
        },
      });
    },

    /**
     * Handle clear all pending items.
     *
     * @param {Event} e Click event.
     */
    handleClearPending(e) {
      e.preventDefault();

      if (!confirm('Cancel all pending items? This will not affect items currently being processed.')) {
        return;
      }

      const $btn = $(e.currentTarget);
      $btn.prop('disabled', true).text('Clearing...');

      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_clear_pending',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success) {
            this.showToast(response.data.message, 'success');
            this.loadPendingItems();
            this.updatePendingStat();
          } else {
            this.showToast(response.data.message, 'error');
          }
        },
        error: () => {
          this.showToast('Failed to clear pending items', 'error');
        },
        complete: () => {
          $btn.prop('disabled', false).text('Clear All');
        },
      });
    },

    /**
     * Update pending stat count.
     */
    updatePendingStat() {
      $.ajax({
        url: cfr2Admin.ajaxUrl,
        type: 'POST',
        data: {
          action: 'cfr2_get_bulk_counts',
          nonce: $('#cfr2_nonce').val(),
        },
        success: (response) => {
          if (response.success && typeof response.data.pending !== 'undefined') {
            const pendingCount = response.data.pending;
            const $stat = $('#cfr2-pending-stat');
            $stat.find('.cfr2-stat-value').text(pendingCount.toLocaleString());

            // Update clickable state.
            if (pendingCount > 0) {
              $stat.addClass('cfr2-stat-clickable');
              $stat.find('.dashicons').show();
            } else {
              $stat.removeClass('cfr2-stat-clickable');
              $stat.find('.dashicons').hide();
            }
          }
        },
      });
    },
  };

  // Initialize when DOM ready.
  $(document).ready(() => {
    CFR2OffLoadAdmin.init();
  });
})(jQuery);
