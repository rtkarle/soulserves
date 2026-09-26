/**
 * SoulServe UI Enhancement Library
 * - Instant feedback button loaders
 * - Interactive confirmation dialogs
 * - Celebratory success modals
 * - Floating toasts
 * - Notification bell drawer
 */
(function(window) {
  'use strict';

  // ── 1. TOAST NOTIFICATIONS ───────────────────────────────────────────────
  const SoulServeToast = {
    container: null,
    init() {
      if (!this.container) {
        this.container = document.createElement('div');
        this.container.className = 'ss-toast-container';
        document.body.appendChild(this.container);
      }
    },
    show(msg, type = 'info', duration = 3500) {
      this.init();
      const toast = document.createElement('div');
      toast.className = `ss-toast ss-toast-${type}`;
      
      const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
      };
      
      toast.innerHTML = `
        <span class="ss-toast-icon">${icons[type] || '✨'}</span>
        <div class="ss-toast-msg">${msg}</div>
        <button class="ss-toast-close" aria-label="Close">&times;</button>
        <div class="ss-toast-progress" style="animation-duration:${duration}ms"></div>
      `;

      toast.querySelector('.ss-toast-close').onclick = () => {
        toast.classList.add('ss-toast-leave');
        setTimeout(() => toast.remove(), 250);
      };

      this.container.appendChild(toast);

      setTimeout(() => {
        if (toast.parentElement) {
          toast.classList.add('ss-toast-leave');
          setTimeout(() => toast.remove(), 250);
        }
      }, duration);
    }
  };

  // ── 2. INTERACTIVE MODALS & CONFIRMATIONS ──────────────────────────────
  const SoulServeModal = {
    overlay: null,
    
    initOverlay() {
      if (!this.overlay) {
        this.overlay = document.createElement('div');
        this.overlay.className = 'ss-modal-overlay';
        this.overlay.innerHTML = `
          <div class="ss-modal-card">
            <button class="ss-modal-close" id="ssModalClose">&times;</button>
            <div id="ssModalBody"></div>
          </div>
        `;
        document.body.appendChild(this.overlay);

        this.overlay.querySelector('#ssModalClose').onclick = () => this.close();
        this.overlay.addEventListener('click', (e) => {
          if (e.target === this.overlay) this.close();
        });
      }
    },

    close() {
      if (this.overlay) {
        this.overlay.classList.remove('ss-active');
        const card = this.overlay.querySelector('.ss-modal-card');
        if (card) card.classList.remove('ss-pop');
      }
    },

    confirm({
      title = 'Are you sure?',
      text = '',
      icon = '❓',
      confirmText = 'Yes, Proceed',
      cancelText = 'Cancel',
      confirmClass = 'ss-btn-primary',
      onConfirm = null
    }) {
      this.initOverlay();
      const body = this.overlay.querySelector('#ssModalBody');
      body.innerHTML = `
        <div class="ss-modal-icon">${icon}</div>
        <h3 class="ss-modal-title">${title}</h3>
        <p class="ss-modal-desc">${text}</p>
        <div class="ss-modal-actions">
          <button type="button" class="ss-btn ss-btn-secondary" id="ssCancelBtn">${cancelText}</button>
          <button type="button" class="ss-btn ${confirmClass}" id="ssConfirmBtn">${confirmText}</button>
        </div>
      `;

      this.overlay.classList.add('ss-active');
      this.overlay.querySelector('.ss-modal-card').classList.add('ss-pop');

      return new Promise((resolve) => {
        this.overlay.querySelector('#ssCancelBtn').onclick = () => {
          this.close();
          resolve(false);
        };
        this.overlay.querySelector('#ssConfirmBtn').onclick = () => {
          const btn = this.overlay.querySelector('#ssConfirmBtn');
          btn.disabled = true;
          btn.innerHTML = `<span class="ss-spinner"></span> Processing...`;
          if (onConfirm) {
            Promise.resolve(onConfirm()).then((res) => {
              this.close();
              resolve(res !== false);
            }).catch(() => {
              this.close();
              resolve(false);
            });
          } else {
            this.close();
            resolve(true);
          }
        };
      });
    },

    celebrate({
      title = 'Action Successful! 🎉',
      message = 'Your request has been processed successfully.',
      donId = '',
      receiptUrl = '',
      trackUrl = '',
      btnText = 'Done'
    }) {
      this.initOverlay();
      const body = this.overlay.querySelector('#ssModalBody');
      body.innerHTML = `
        <div class="ss-modal-icon ss-bounce">🎉</div>
        <h3 class="ss-modal-title">${title}</h3>
        <p class="ss-modal-desc">${message}</p>
        ${donId ? `
          <div class="ss-modal-chip">
            <span style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700">Donation Tracking ID</span>
            <div style="font-size:16px;font-weight:900;color:var(--teal);letter-spacing:.5px">${donId}</div>
          </div>
        ` : ''}
        <div class="ss-modal-actions" style="flex-direction:column;gap:8px">
          ${receiptUrl ? `<a href="${receiptUrl}" target="_blank" class="ss-btn ss-btn-primary" style="text-decoration:none;text-align:center">🖨️ Download Official Receipt</a>` : ''}
          ${trackUrl ? `<a href="${trackUrl}" class="ss-btn ss-btn-outline" style="text-decoration:none;text-align:center">📍 Track Pickup Live</a>` : ''}
          <button type="button" class="ss-btn ss-btn-secondary" id="ssCelebrateDone">${btnText}</button>
        </div>
      `;

      this.overlay.classList.add('ss-active');
      this.overlay.querySelector('.ss-modal-card').classList.add('ss-pop');

      this.overlay.querySelector('#ssCelebrateDone').onclick = () => this.close();
    }
  };

  // ── 3. UNIVERSAL BUTTON LOADER (Instant tactile feedback) ────────────────
  function bindButtonLoaders() {
    document.addEventListener('submit', function(e) {
      const form = e.target;
      if (form.classList.contains('no-loader')) return;

      const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
      if (submitBtn && !submitBtn.disabled) {
        // Prevent double submit and add immediate visual feedback
        const originalText = submitBtn.innerHTML || submitBtn.value;
        submitBtn.dataset.originalContent = originalText;
        submitBtn.disabled = true;
        submitBtn.classList.add('ss-btn-loading');
        
        if (submitBtn.tagName.toLowerCase() === 'button') {
          submitBtn.innerHTML = `<span class="ss-spinner"></span> <span>Please wait...</span>`;
        } else {
          submitBtn.value = 'Please wait...';
        }

        // Safety timeout to restore if form submission was aborted
        setTimeout(() => {
          if (submitBtn && submitBtn.disabled && submitBtn.classList.contains('ss-btn-loading')) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('ss-btn-loading');
            if (submitBtn.dataset.originalContent) {
              submitBtn.innerHTML = submitBtn.dataset.originalContent;
            }
          }
        }, 12000);
      }
    }, true);

    // Also handle instant button clicks with data-loading
    document.addEventListener('click', function(e) {
      const btn = e.target.closest('[data-loading]');
      if (!btn || btn.disabled) return;
      btn.disabled = true;
      const orig = btn.innerHTML;
      btn.dataset.originalContent = orig;
      btn.innerHTML = `<span class="ss-spinner"></span> ${btn.dataset.loading || 'Loading...'}`;
    });
  }

  // ── 4. CHECK URL PARAMS FOR AUTO-MODALS ─────────────────────────────────
  function checkUrlPopups() {
    const params = new URLSearchParams(window.location.search);
    const success = params.get('success');
    const donId = params.get('don_id');
    const claimed = params.get('claimed');

    if (success && donId) {
      const catName = success.charAt(0).toUpperCase() + success.slice(1).replace('_', ' ');
      SoulServeModal.celebrate({
        title: `Donation Received! 💖`,
        message: `Thank you! Your <strong>${catName}</strong> donation has been registered. We are alerting verified volunteers in your area right now.`,
        donId: donId,
        receiptUrl: `../api/donation_receipt.php?id=${encodeURIComponent(donId)}&type=${encodeURIComponent(success)}`,
        trackUrl: `track.php?s=${encodeURIComponent(donId)}`
      });
      // Clean query string without full page reload
      const cleanUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
      window.history.replaceState({}, document.title, cleanUrl);
    } else if (claimed) {
      SoulServeModal.celebrate({
        title: `Task Claimed Successfully! 🚚`,
        message: `You have been assigned to this pickup. View details in your Active Pickups.`,
        donId: claimed,
        btnText: 'Open Active Pickups'
      });
    }
  }

  // Auto-init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      bindButtonLoaders();
      checkUrlPopups();
    });
  } else {
    bindButtonLoaders();
    checkUrlPopups();
  }

  // Expose globally
  window.SoulServeToast = SoulServeToast;
  window.SoulServeModal = SoulServeModal;
  window.SoulServe = {
    toast: (msg, type, dur) => SoulServeToast.show(msg, type, dur),
    confirm: (opts) => SoulServeModal.confirm(opts),
    celebrate: (opts) => SoulServeModal.celebrate(opts),
    closeModal: () => SoulServeModal.close()
  };

})(window);
