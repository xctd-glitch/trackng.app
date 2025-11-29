/**
 * Smart Redirect Platform - Dashboard JavaScript
 * Extracted from dashboard.view.php for better maintainability
 * @version 2.2.0
 */

// Utility Functions for Performance Optimization

/**
 * Debounce function - delays function execution until after wait milliseconds
 * @param {Function} func Function to debounce
 * @param {number} wait Wait time in milliseconds
 * @param {boolean} immediate Execute immediately on first call
 * @returns {Function} Debounced function
 */
function debounce(func, wait, immediate) {
    let timeout;
    return function executedFunction() {
        const context = this;
        const args = arguments;

        const later = function() {
            timeout = null;
            if (!immediate) func.apply(context, args);
        };

        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);

        if (callNow) func.apply(context, args);
    };
}

/**
 * Throttle function - ensures function is called at most once per interval
 * @param {Function} func Function to throttle
 * @param {number} limit Time limit in milliseconds
 * @returns {Function} Throttled function
 */
function throttle(func, limit) {
    let inThrottle;
    let lastFunc;
    let lastTime;

    return function() {
        const context = this;
        const args = arguments;

        if (!inThrottle) {
            func.apply(context, args);
            lastTime = Date.now();
            inThrottle = true;
        } else {
            clearTimeout(lastFunc);
            lastFunc = setTimeout(function() {
                if ((Date.now() - lastTime) >= limit) {
                    func.apply(context, args);
                    lastTime = Date.now();
                }
            }, limit - (Date.now() - lastTime));
        }
    };
}

// Dashboard State Manager
document.addEventListener('alpine:init', () => {
    Alpine.data('dash', () => ({
        // Navigation State
        activeTab: 'overview',

        // Configuration State
        cfg: {
            system_on: false,
            redirect_url: [],
            country_filter_mode: 'all',
            country_filter_list: '',
            updated_at: 0,
            postback_url: '',
            default_payout: 0
        },

        // Environment Configuration
        envConfig: {
            // Database
            DB_HOST: 'localhost',
            DB_NAME: '',
            DB_USER: '',
            DB_PASS: '',
            DB_PORT: '3306',
            DB_CHARSET: 'utf8mb4',

            // Domain Configuration
            APP_NAME: 'Smart Redirect Platform',
            APP_URL: '',
            APP_PANEL_URL: '',
            BRAND_DOMAIN: '',
            TRACKING_PRIMARY_DOMAIN: '',
            TRACKING_DOMAIN: '',
            TRACKING_REDIRECT_URL: '',
            TRACKING_DECISION_API: '',
            TRACKING_POSTBACK_URL: '',

            // API Keys
            API_KEY_INTERNAL: '',
            API_KEY_EXTERNAL: '',
            SRP_API_URL: 'https://api.qvtrk.com/decision.php',
            SRP_API_KEY: '',

            // Application Settings
            APP_ENV: 'production',
            SRP_ENV: 'production',
            APP_DEBUG: 'false',
            APP_TIMEZONE: 'UTC',
            MAINTENANCE_MODE: 'false',
            MAINTENANCE_MESSAGE: 'System under maintenance. Please try again later.',

            // Session & Security
            SESSION_LIFETIME: '7200',
            SESSION_NAME: 'SRP_SESSION',
            SESSION_SECRET: '',
            RATE_LIMIT_ATTEMPTS: '5',
            RATE_LIMIT_WINDOW: '900',
            SECURE_COOKIES: 'true',
            HTTP_ONLY: 'true',
            SAME_SITE: 'Strict',
            TRUST_CF_HEADERS: 'true',

            // Feature Flags
            BRAND_ENABLE_LANDING_PAGE: 'true',
            BRAND_ENABLE_DOCUMENTATION: 'true',
            BRAND_ENABLE_API_DOCS: 'true',
            TRACKING_ENABLE_VPN_CHECK: 'true',
            TRACKING_ENABLE_GEO_FILTER: 'true',
            TRACKING_ENABLE_DEVICE_FILTER: 'true',
            TRACKING_ENABLE_AUTO_MUTE: 'true',
            RATE_LIMIT_TRACKING_ENABLED: 'true',

            // Postback Configuration
            POSTBACK_TIMEOUT: '5',
            POSTBACK_MAX_RETRIES: '3',
            POSTBACK_RETRY_DELAY: '60',
            POSTBACK_HMAC_SECRET: '',
            POSTBACK_REQUIRE_API_KEY: 'true',
            POSTBACK_API_KEY: '',
            POSTBACK_FORWARD_ENABLED: 'false',
            POSTBACK_FORWARD_URL: '',
            DEFAULT_PAYOUT: '0.00',

            // Path Configuration
            APP_ROOT: '',
            LOG_PATH: '',

            // External Services
            VPN_CHECK_URL: 'https://blackbox.ipinfo.app/lookup/',
            VPN_CHECK_TIMEOUT: '2'
        },

        // UI State
        showApiKey: false,
        showInternalKey: false,
        showExternalKey: false,
        showSrpKey: false,
        isSavingEnv: false,
        isSyncingEnv: false,
        flash: '',
        flashType: 'success',
        isSavingCfg: false,
        selectedAuditFilter: 'all',

        // Data State
        logs: [],
        muteStatus: {
            isMuted: false,
            timeRemaining: 'Normal',
            duration: null,
            autoMuteHistory: []
        },

        // Postback State
        postbackLogs: [],
        receivedPostbacks: [],
        testPostbackLoading: false,
        postbackUrl: '',
        postbackLoadErrors: 0,
        maxPostbackErrors: 3,
        forwardUrl: '',
        postbackInterval: null,

        // Statistics State
        totalDecisionA: 0,
        totalDecisionB: 0,
        uniqueCountries: 0,
        dailyStats: [],
        statsSummary: {
            total: 0,
            confirmed: 0,
            revenue: 0
        },
        statsPeriod: 7,
        statsView: 'overview',
        statsLoading: false,

        // Refresh State
        autoRefreshEnabled: true,
        autoRefreshInterval: 10,
        refreshTimeLeft: 10,
        refreshIntervalId: null,
        refreshCountdownId: null,

        // Debounced/Throttled Methods
        debouncedSave: null,
        debouncedSaveEnv: null,
        throttledRefresh: null,
        debouncedSearch: null,

        // Initialization
        init() {
            // Clean up any existing intervals first
            this.cleanup();

            // Create debounced/throttled versions of methods
            this.debouncedSave = debounce(() => this._save(), 500);
            this.debouncedSaveEnv = debounce(() => this._saveEnvConfig(), 500);
            this.throttledRefresh = throttle(() => this._refresh(), 2000);
            this.debouncedSearch = debounce((term) => this._performSearch(term), 300);

            // Initial data load
            this.refresh();
            this.loadEnvConfig();

            // Set up refresh intervals with proper cleanup tracking
            this.setupIntervals();

            // Update mute status every second
            this.muteInterval = setInterval(() => this.updateMuteStatus(), 1000);

            // Check auto-refresh preference
            const savedAutoRefresh = localStorage.getItem('srp_autoRefresh');
            if (savedAutoRefresh !== null) {
                this.autoRefreshEnabled = savedAutoRefresh === 'true';
            }

            const savedInterval = localStorage.getItem('srp_refreshInterval');
            if (savedInterval) {
                this.autoRefreshInterval = parseInt(savedInterval) || 10;
                this.refreshTimeLeft = this.autoRefreshInterval;
            }

            if (this.autoRefreshEnabled) {
                this.startAutoRefresh();
            }

            // Set up window unload handler for cleanup
            window.addEventListener('beforeunload', () => this.cleanup());

            // Also cleanup when Alpine component is destroyed
            this.$watch('$destroy', () => this.cleanup());
        },

        // Cleanup method to prevent memory leaks
        cleanup() {
            // Clear all intervals
            if (this.refreshIntervalId) {
                clearInterval(this.refreshIntervalId);
                this.refreshIntervalId = null;
            }
            if (this.refreshCountdownId) {
                clearInterval(this.refreshCountdownId);
                this.refreshCountdownId = null;
            }
            if (this.postbackInterval) {
                clearInterval(this.postbackInterval);
                this.postbackInterval = null;
            }
            if (this.muteInterval) {
                clearInterval(this.muteInterval);
                this.muteInterval = null;
            }
            if (this.statsInterval) {
                clearInterval(this.statsInterval);
                this.statsInterval = null;
            }
            if (this.receivedInterval) {
                clearInterval(this.receivedInterval);
                this.receivedInterval = null;
            }
        },

        // Set up background intervals
        setupIntervals() {
            // Load postback logs every 10 seconds
            this.postbackInterval = setInterval(() => {
                if (this.postbackLoadErrors < this.maxPostbackErrors) {
                    this.loadPostbackLogs();
                }
            }, 10000);

            // Load received postbacks every 10 seconds
            this.receivedInterval = setInterval(() => this.loadReceivedPostbacks(), 10000);

            // Load daily stats every 60 seconds
            this.statsInterval = setInterval(() => this.loadDailyStats(), 60000);
        },

        // CSRF Token Helper
        csrf() {
            const el = document.querySelector('meta[name="csrf-token"]');
            return el && el.content ? el.content : '';
        },

        // Safe JSON Parser
        async safeJsonParse(response) {
            try {
                const text = await response.text();
                if (!text) return null;
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                return null;
            }
        },

        // Flash Message Handler
        setFlash(message, type = 'success') {
            this.flash = message;
            this.flashType = type;
            setTimeout(() => {
                this.flash = '';
            }, 5000);
        },

        // Refresh wrapper - uses throttled version
        refresh() {
            if (this.throttledRefresh) {
                return this.throttledRefresh();
            }
            return this._refresh();
        },

        // Main Refresh Method (internal)
        async _refresh() {
            try {
                const r = await fetch('data.php', {
                    headers: {
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await this.safeJsonParse(r);

                if (data && !data.error) {
                    // Update configuration
                    if (data.cfg) {
                        // Parse redirect URLs if they're a string
                        if (typeof data.cfg.redirect_url === 'string' && data.cfg.redirect_url) {
                            try {
                                data.cfg.redirect_url = JSON.parse(data.cfg.redirect_url);
                            } catch (e) {
                                data.cfg.redirect_url = [data.cfg.redirect_url];
                            }
                        }
                        // Ensure redirect_url is an array
                        if (!Array.isArray(data.cfg.redirect_url)) {
                            data.cfg.redirect_url = [];
                        }
                        // Parse country filter list if string
                        if (typeof data.cfg.country_filter_list === 'string' && data.cfg.country_filter_list) {
                            try {
                                const parsed = JSON.parse(data.cfg.country_filter_list);
                                data.cfg.country_filter_list = Array.isArray(parsed) ? parsed.join(', ') : '';
                            } catch (e) {
                                // Keep as string if not JSON
                            }
                        }
                        this.cfg = data.cfg;
                    }

                    // Update logs
                    if (Array.isArray(data.logs)) {
                        this.logs = data.logs;
                    }

                    // Calculate statistics
                    this.calculateStats();

                    // Update unique countries
                    this.uniqueCountries = this.getUniqueCountries().length;

                    // Reset auto-refresh countdown
                    this.refreshTimeLeft = this.autoRefreshInterval;
                } else if (data && data.error) {
                    this.setFlash('Failed to refresh: ' + data.error, 'error');
                }
            } catch (e) {
                console.error('Refresh error:', e);
                this.setFlash('Failed to refresh data', 'error');
            }
        },

        // Save wrapper - uses debounced version
        save() {
            if (this.debouncedSave) {
                return this.debouncedSave();
            }
            return this._save();
        },

        // Save Configuration (internal)
        async _save() {
            this.isSavingCfg = true;

            try {
                const payload = {
                    system_on: this.cfg.system_on,
                    redirect_url: Array.isArray(this.cfg.redirect_url) ? this.cfg.redirect_url : [],
                    country_filter_mode: this.cfg.country_filter_mode,
                    country_filter_list: this.cfg.country_filter_list || ''
                };

                const r = await fetch('data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    if (data.cfg) {
                        this.cfg = data.cfg;
                    }
                    this.setFlash('Configuration saved successfully');
                } else {
                    this.setFlash(data.error || 'Failed to save configuration', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to save: ' + e.message, 'error');
            } finally {
                this.isSavingCfg = false;
            }
        },

        // Clear Logs
        async clearLogs() {
            if (!confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
                return;
            }

            try {
                const r = await fetch('data.php', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.logs = [];
                    this.totalDecisionA = 0;
                    this.totalDecisionB = 0;
                    this.uniqueCountries = 0;
                    this.setFlash('Logs cleared successfully');
                } else {
                    this.setFlash(data.error || 'Failed to clear logs', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to clear logs', 'error');
            }
        },

        // Redirect URL Management
        addRedirectUrl() {
            if (!Array.isArray(this.cfg.redirect_url)) {
                this.cfg.redirect_url = [];
            }
            this.cfg.redirect_url.push('');
        },

        removeRedirectUrl(index) {
            if (Array.isArray(this.cfg.redirect_url)) {
                this.cfg.redirect_url.splice(index, 1);
            }
        },

        updateRedirectUrl(index, value) {
            if (Array.isArray(this.cfg.redirect_url)) {
                this.cfg.redirect_url[index] = value;
            }
        },

        // Environment Config Methods
        async loadEnvConfig() {
            try {
                const r = await fetch('env-config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ action: 'get' })
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok && data.config) {
                    this.envConfig = { ...this.envConfig, ...data.config };
                }
            } catch (e) {
                console.error('Failed to load env config:', e);
            }
        },

        // Save Env Config wrapper - uses debounced version
        saveEnvConfig() {
            if (this.debouncedSaveEnv) {
                return this.debouncedSaveEnv();
            }
            return this._saveEnvConfig();
        },

        async _saveEnvConfig() {
            this.isSavingEnv = true;

            try {
                const r = await fetch('env-config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'update',
                        config: this.envConfig
                    })
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.setFlash('Environment configuration saved successfully');
                    // Reload to apply changes
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    this.setFlash(data.error || 'Failed to save configuration', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to save: ' + e.message, 'error');
            } finally {
                this.isSavingEnv = false;
            }
        },

        async syncEnvToFile() {
            if (!confirm('This will update the .env file with current database values. Continue?')) {
                return;
            }

            this.isSyncingEnv = true;

            try {
                const r = await fetch('env-config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ action: 'sync' })
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.setFlash('.env file updated successfully');
                } else {
                    this.setFlash(data.error || 'Failed to sync configuration', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to sync: ' + e.message, 'error');
            } finally {
                this.isSyncingEnv = false;
            }
        },

        // Search Methods
        searchTerm: '',
        filteredLogs: [],

        search(term) {
            this.searchTerm = term;
            if (this.debouncedSearch) {
                this.debouncedSearch(term);
            } else {
                this._performSearch(term);
            }
        },

        _performSearch(term) {
            if (!term || term.length < 2) {
                this.filteredLogs = [];
                return;
            }

            const searchLower = term.toLowerCase();
            this.filteredLogs = this.logs.filter(log => {
                return (log.click_id && log.click_id.toLowerCase().includes(searchLower)) ||
                       (log.country_code && log.country_code.toLowerCase().includes(searchLower)) ||
                       (log.ip && log.ip.toLowerCase().includes(searchLower)) ||
                       (log.user_lp && log.user_lp.toLowerCase().includes(searchLower));
            });
        },

        clearSearch() {
            this.searchTerm = '';
            this.filteredLogs = [];
        },

        get displayLogs() {
            return this.searchTerm ? this.filteredLogs : this.logs;
        },

        // Auto-refresh Methods
        toggleAutoRefresh() {
            this.autoRefreshEnabled = !this.autoRefreshEnabled;
            localStorage.setItem('srp_autoRefresh', this.autoRefreshEnabled);

            if (this.autoRefreshEnabled) {
                this.startAutoRefresh();
                this.setFlash('Auto-refresh enabled');
            } else {
                this.stopAutoRefresh();
                this.setFlash('Auto-refresh disabled');
            }
        },

        updateRefreshInterval() {
            localStorage.setItem('srp_refreshInterval', this.autoRefreshInterval);
            this.refreshTimeLeft = this.autoRefreshInterval;

            if (this.autoRefreshEnabled) {
                this.stopAutoRefresh();
                this.startAutoRefresh();
            }
        },

        startAutoRefresh() {
            this.stopAutoRefresh();

            this.refreshIntervalId = setInterval(() => {
                this.refresh();
                this.refreshTimeLeft = this.autoRefreshInterval;
            }, this.autoRefreshInterval * 1000);

            this.refreshCountdownId = setInterval(() => {
                this.refreshTimeLeft--;
                if (this.refreshTimeLeft <= 0) {
                    this.refreshTimeLeft = this.autoRefreshInterval;
                }
            }, 1000);
        },

        stopAutoRefresh() {
            if (this.refreshIntervalId) {
                clearInterval(this.refreshIntervalId);
                this.refreshIntervalId = null;
            }
            if (this.refreshCountdownId) {
                clearInterval(this.refreshCountdownId);
                this.refreshCountdownId = null;
            }
        },

        // Statistics Calculation
        calculateStats() {
            this.totalDecisionA = 0;
            this.totalDecisionB = 0;

            this.logs.forEach(log => {
                if (log.decision === 'A') {
                    this.totalDecisionA++;
                } else if (log.decision === 'B') {
                    this.totalDecisionB++;
                }
            });
        },

        // Country Statistics
        getCountryStats() {
            const stats = {};
            this.logs.forEach(log => {
                const country = log.country_code || 'XX';
                if (!stats[country]) {
                    stats[country] = {
                        country: country,
                        total: 0,
                        decisionA: 0,
                        decisionB: 0
                    };
                }
                stats[country].total++;
                if (log.decision === 'A') {
                    stats[country].decisionA++;
                } else {
                    stats[country].decisionB++;
                }
            });

            return Object.values(stats).sort((a, b) => b.total - a.total);
        },

        getUniqueCountries() {
            const countries = new Set();
            this.logs.forEach(log => {
                if (log.country_code) {
                    countries.add(log.country_code);
                }
            });
            return Array.from(countries).sort();
        },

        // Mute Status Update
        updateMuteStatus() {
            if (!this.cfg || !this.cfg.system_on) {
                this.muteStatus = {
                    isMuted: false,
                    timeRemaining: 'System OFF',
                    duration: null,
                    autoMuteHistory: []
                };
                return;
            }

            const lastReset = this.cfg.updated_at || 0;
            const now = Date.now() / 1000;
            const elapsed = now - lastReset;
            const cycleLength = 1800; // 30 minutes

            if (elapsed < cycleLength) {
                const remaining = Math.floor(cycleLength - elapsed);
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                this.muteStatus = {
                    isMuted: false,
                    timeRemaining: `Next cycle: ${minutes}:${seconds.toString().padStart(2, '0')}`,
                    duration: null,
                    autoMuteHistory: this.muteStatus.autoMuteHistory || []
                };
            } else {
                this.muteStatus = {
                    isMuted: true,
                    timeRemaining: 'Cycle ended - Waiting for reset',
                    duration: elapsed,
                    autoMuteHistory: this.muteStatus.autoMuteHistory || []
                };
            }
        },

        // Postback Methods
        async loadPostbackLogs() {
            if (this.postbackLoadErrors >= this.maxPostbackErrors) {
                return;
            }

            try {
                const r = await fetch('postback-config.php?action=logs', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    // Load postback URL
                    if (data.postback_url !== undefined) {
                        this.postbackUrl = data.postback_url || '';
                    }

                    // Load forward URL
                    if (data.forward_url !== undefined) {
                        this.forwardUrl = data.forward_url || '';
                    }

                    // Only update if changed to reduce DOM updates
                    const newLogs = Array.isArray(data.logs) ? data.logs : [];
                    if (JSON.stringify(this.postbackLogs) !== JSON.stringify(newLogs)) {
                        this.postbackLogs = newLogs;
                    }

                    this.postbackLoadErrors = 0;
                } else {
                    this.postbackLoadErrors++;
                }
            } catch (e) {
                this.postbackLoadErrors++;
                console.error('Failed to load postback logs:', e);
            }
        },

        async testPostback() {
            this.testPostbackLoading = true;

            try {
                const r = await fetch('postback-config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'test'
                    })
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.setFlash('Test postback sent successfully. Check the logs below.');
                    // Reload logs after a short delay
                    setTimeout(() => this.loadPostbackLogs(), 1000);
                } else {
                    this.setFlash(data.error || 'Failed to send test postback', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to send test postback', 'error');
            } finally {
                this.testPostbackLoading = false;
            }
        },

        async savePostbackUrl() {
            try {
                const r = await fetch('postback-config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'update_url',
                        url: this.postbackUrl
                    })
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.setFlash('Postback URL saved successfully');
                    this.cfg.postback_url = this.postbackUrl;
                } else {
                    this.setFlash(data.error || 'Failed to save postback URL', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to save postback URL', 'error');
            }
        },

        async saveForwardUrl() {
            try {
                const r = await fetch('postback-config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'update_forward_url',
                        url: this.forwardUrl
                    })
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.setFlash('Forward URL saved successfully');
                } else {
                    this.setFlash(data.error || 'Failed to save forward URL', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to save forward URL', 'error');
            }
        },

        async clearPostbackLogs() {
            if (!confirm('Clear all postback logs?')) {
                return;
            }

            try {
                const r = await fetch('postback-config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrf(),
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'clear_logs'
                    })
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.postbackLogs = [];
                    this.setFlash('Postback logs cleared successfully');
                } else {
                    this.setFlash(data.error || 'Failed to clear logs', 'error');
                }
            } catch (e) {
                this.setFlash('Failed to clear logs', 'error');
            }
        },

        async loadReceivedPostbacks() {
            try {
                const r = await fetch('postback-config.php?action=received', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    // Only update if changed to reduce DOM updates
                    const newPostbacks = Array.isArray(data.logs) ? data.logs : [];
                    if (JSON.stringify(this.receivedPostbacks) !== JSON.stringify(newPostbacks)) {
                        this.receivedPostbacks = newPostbacks;
                    }
                }
            } catch (e) {
                // Silent fail for background refresh
                console.error('Failed to load received postbacks:', e);
            }
        },

        async loadDailyStats() {
            if (this.statsLoading) {
                return;
            }

            this.statsLoading = true;

            try {
                const r = await fetch(`postback-config.php?action=stats&days=${this.statsPeriod}&view=${this.statsView}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await this.safeJsonParse(r);

                if (data && data.ok) {
                    this.dailyStats = Array.isArray(data.stats) ? data.stats : [];
                    if (data.summary) {
                        this.statsSummary = data.summary;
                    }
                }
            } catch (e) {
                // Silent fail for background refresh
                console.error('Failed to load daily stats:', e);
            } finally {
                this.statsLoading = false;
            }
        },

        async changeStatsPeriod(days) {
            this.statsPeriod = days;
            await this.loadDailyStats();
        },

        async changeStatsView(view) {
            this.statsView = view;
            await this.loadDailyStats();
        },

        // Utility Methods
        getStatsResetInfo() {
            const lastReset = this.cfg.updated_at || 0;
            const nextReset = lastReset + 1800; // 30 minutes
            const now = Date.now() / 1000;

            if (now < nextReset) {
                const remaining = nextReset - now;
                const minutes = Math.floor(remaining / 60);
                const seconds = Math.floor(remaining % 60);
                return `Reset in ${minutes}m ${seconds}s`;
            } else {
                return 'Ready to reset';
            }
        },

        formatNumber(num) {
            return new Intl.NumberFormat().format(num || 0);
        },

        copyToClipboard(text, message = 'Copied to clipboard') {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    this.setFlash(message);
                }).catch(err => {
                    this.fallbackCopy(text, message);
                });
            } else {
                this.fallbackCopy(text, message);
            }
        },

        fallbackCopy(text, message) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                this.setFlash(message);
            } catch (err) {
                this.setFlash('Failed to copy to clipboard', 'error');
            }

            document.body.removeChild(textarea);
        },

        // Add destructor for cleanup when component is destroyed
        destroy() {
            this.cleanup();
        }
    }));
});

// Add beforeunload handler to cleanup intervals
window.addEventListener('beforeunload', () => {
    // Get Alpine component instance and cleanup
    const dashComponent = document.querySelector('[x-data]')?._x_dataStack?.[0];
    if (dashComponent && dashComponent.cleanup) {
        dashComponent.cleanup();
    }
});