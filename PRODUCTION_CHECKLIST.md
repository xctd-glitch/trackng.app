# SRP Production Deployment Checklist v2.2.1

## Pre-Deployment Security Fixes Applied

### 1. Critical Security Issues Fixed ✅
- [x] Removed IP spoofing vulnerability in `SrpClient.php`
- [x] Changed VPN check from fail-open to fail-close for better security
- [x] Disabled debug mode in production (`LandingController.php`)
- [x] Optimized database connection to avoid unnecessary ping queries
- [x] Enhanced session directory security with permission checks
- [x] **NEW v2.2.1**: SSRF protection for postback URLs (DNS + IP range validation)
- [x] **NEW v2.2.1**: GDPR-compliant IP hashing with SHA-256 + salt
- [x] **NEW v2.2.1**: Secure random using `random_int()` instead of `array_rand()`
- [x] **NEW v2.2.1**: UTC timezone normalization with `gmdate()`

### 2. Code Quality Improvements ✅
- [x] Added proper type declarations
- [x] Improved error handling
- [x] Enhanced input validation
- [x] Consistent code style across all files
- [x] **NEW v2.2.1**: Query optimization with LIMIT clauses
- [x] **NEW v2.2.1**: DDL migration to bootstrap (removed from hot path)
- [x] **NEW v2.2.1**: Error message sanitization (no SQL details exposed)

### 3. UI/UX Improvements ✅
- [x] **NEW v2.2.1**: Deep linking via URL hash for tab navigation
- [x] **NEW v2.2.1**: State persistence with localStorage
- [x] **NEW v2.2.1**: Keyboard navigation with arrow keys
- [x] **NEW v2.2.1**: WCAG 2.1 Level AA accessibility compliance
- [x] **NEW v2.2.1**: Lazy loading for per-tab data
- [x] **NEW v2.2.1**: Browser back/forward navigation support

## Production Deployment Steps

### 1. Environment Configuration
```bash
# Generate new API keys (32 characters)
openssl rand -hex 32

# Update .env file with production values
cp srp/.env.example srp/.env
```

Required .env changes:
- [ ] `SRP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_NAME="Smart Redirect Platform"`
- [ ] `SRP_API_KEY=<new_32_char_key>`
- [ ] `API_KEY_INTERNAL=<new_32_char_key>`
- [ ] `API_KEY_EXTERNAL=<new_32_char_key>`
- [ ] `DECISION_API_KEY=<new_32_char_key>`
- [ ] `POSTBACK_SECRET=<new_secret_string>`
- [ ] `SRP_ADMIN_USER=<new_admin_username>`
- [ ] `SRP_ADMIN_PASSWORD_HASH=<bcrypt_hash>`
- [ ] **`IP_HASH_SALT=<new_32_char_salt>` (NEW in v2.2.1 - REQUIRED)**
- [ ] Database credentials
- [ ] Domain URLs (brand and tracking)
- [ ] `HEALTH_CHECK_TOKEN=<new_token>`

### 2. Generate Admin Password Hash
```php
<?php
// Generate bcrypt hash for admin password
$password = 'your_secure_password_here';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
echo "Password Hash: " . $hash . "\n";
```

### 2a. Generate IP Hash Salt (NEW in v2.2.1)
```bash
# Generate 32-character random salt for GDPR-compliant IP hashing
openssl rand -hex 32

# Add to .env:
# IP_HASH_SALT=<generated_salt_here>
```

**Why This Is Required:**
- GDPR and privacy regulations require IP address protection
- IP addresses are hashed (SHA-256 + salt) before logging
- Salt must be kept secret (same security level as API keys)
- Without this, VPN check errors will fail to log properly

### 3. Database Setup
```bash
# Import schema
mysql -u username -p database_name < database/schema.sql

# Verify tables created
mysql -u username -p database_name -e "SHOW TABLES;"
```

### 4. File Upload Structure
```
/home/username/
├── public_html/              # Brand domain files
├── public_html_tracking/     # Tracking domain files
├── srp/                      # Application (outside webroot)
└── storage/                  # Logs and cache
```

### 5. Update Bootstrap Paths
Replace relative paths with absolute paths in all PHP entry files:
```php
// From:
require_once __DIR__ . '/../srp/src/bootstrap.php';

// To:
require_once '/home/username/srp/src/bootstrap.php';
```

Files to update:
- [ ] All files in `public_html/*.php`
- [ ] All files in `public_html_tracking/*.php`

### 6. Set Proper Permissions
```bash
# Secure .env file
chmod 600 /home/username/srp/.env

# Set directory permissions
chmod 755 /home/username/public_html
chmod 755 /home/username/public_html_tracking
chmod 700 /home/username/srp
chmod 700 /home/username/storage/logs
```

### 7. Configure Web Server

#### Apache .htaccess (already included)
- Security headers configured
- Cross-domain blocking implemented
- CORS rules for API endpoints

#### SSL/HTTPS
- [ ] Enable AutoSSL via cPanel
- [ ] Verify all domains have SSL certificates
- [ ] Test HTTPS redirect

### 8. Test All Endpoints
```bash
# Run endpoint tests
php test-all-endpoints.php production

# Test Decision API with new client
php srp-decision-simple.php

# Test health check endpoint
curl -H "X-Health-Token: your_health_token" https://api.qvtrk.com/health

# Test all domains
curl -I https://trackng.app
curl -I https://panel.trackng.app
curl -I https://qvtrk.com
curl -I https://api.qvtrk.com
```

### 9. Security Verification

#### Basic Security
- [ ] Verify CSRF protection on all forms
- [ ] Check CSP headers are working
- [ ] Confirm session security settings
- [ ] Test rate limiting on API endpoints
- [ ] Verify cross-domain blocking

#### NEW v2.2.1: SSRF Protection Testing
- [ ] Test SSRF protection with private IP postback URL:
  ```bash
  # Should be REJECTED
  Try adding postback URL: http://192.168.1.1/test
  Try adding postback URL: http://10.0.0.1/test
  Try adding postback URL: http://127.0.0.1/test
  Try adding postback URL: http://169.254.169.254/latest/meta-data/
  ```
- [ ] Test SSRF protection with valid public URL:
  ```bash
  # Should be ACCEPTED
  Try adding postback URL: https://example.com/postback
  ```
- [ ] Verify error message for blocked URLs is clear

#### NEW v2.2.1: IP Hashing Verification
- [ ] Check error logs for hashed IPs (only 16-char hash should appear):
  ```bash
  tail -f storage/logs/error.log
  # Look for: "VPN check service failed for IP hash: <16-char-hash>"
  # Should NOT see: Full IP addresses like "203.0.113.1"
  ```
- [ ] Verify IP_HASH_SALT is set in .env
- [ ] Confirm salt is NOT in version control (.gitignore)

#### NEW v2.2.1: Performance Verification
- [ ] Verify cache TTL is 60 seconds (not 3 seconds):
  ```bash
  grep "CACHE_TTL = 60" srp/src/Models/Settings.php
  ```
- [ ] Confirm queries have LIMIT clauses:
  ```bash
  grep "LIMIT 365" srp/src/Controllers/PostbackController.php
  ```
- [ ] Verify api_rate_limit table exists:
  ```bash
  mysql -u user -p database -e "SHOW TABLES LIKE 'api_rate_limit';"
  ```

### 10. Monitoring Setup
- [ ] Set up log rotation for `storage/logs/`
- [ ] Configure error monitoring
- [ ] Set up uptime monitoring for both domains
- [ ] Enable MySQL event scheduler for auto-cleanup

### 11. Backup Strategy
```bash
# Database backup script
#!/bin/bash
BACKUP_DIR="/home/username/backups"
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u user -p'password' database > "$BACKUP_DIR/srp_$DATE.sql"
gzip "$BACKUP_DIR/srp_$DATE.sql"
find "$BACKUP_DIR" -name "srp_*.sql.gz" -mtime +30 -delete
```

### 12. API Client Deployment
For external hosts using the Decision API:
- [ ] Distribute `srp-decision-client.php` or `srp-decision-simple.php`
- [ ] Provide API key securely (not via email)
- [ ] Test integration with sample request
- [ ] Monitor initial traffic for issues
- [ ] Check rate limiting is working

### 13. Post-Deployment Verification

#### Core Functionality
- [ ] All endpoints responding correctly
- [ ] Admin login working
- [ ] Decision API returning correct responses
- [ ] API clients connecting successfully
- [ ] Postback system functioning
- [ ] No error messages exposed to users
- [ ] Performance acceptable (< 200ms response times)
- [ ] Bot blocking working (check logs)
- [ ] Health check endpoint accessible

#### NEW v2.2.1: UI/UX Verification
- [ ] Deep linking works - test URL: `https://panel.trackng.app#statistics`
  - Should open Statistics tab directly
  - URL hash should update when switching tabs
- [ ] State persistence works:
  - Switch to a non-default tab (e.g., Logs)
  - Refresh page
  - Should remain on Logs tab
- [ ] Keyboard navigation works:
  - Press Tab to focus on tab buttons
  - Use Left/Right arrow keys to navigate
  - Should cycle through tabs with keyboard only
- [ ] Browser navigation works:
  - Switch tabs multiple times
  - Press browser Back button
  - Should navigate through tab history
- [ ] Lazy loading works:
  - Open DevTools Network tab
  - Switch to Logs tab
  - Should see API request for logs data (only when tab activated)

#### NEW v2.2.1: Accessibility Testing (WCAG 2.1 Level AA)
- [ ] Screen reader testing (if available):
  - Tab buttons should announce: "Tab, Statistics, selected" or "Tab, Logs"
  - ARIA attributes present: `role="tab"`, `aria-selected`, `aria-controls`
- [ ] Keyboard-only navigation:
  - All interactive elements reachable via Tab key
  - No keyboard traps
  - Focus indicators visible
- [ ] Focus management:
  - Active tab should have `tabindex="0"`
  - Inactive tabs should have `tabindex="-1"`
  - Focus ring visible on keyboard navigation
- [ ] Color contrast:
  - Active tab color has sufficient contrast
  - Hover states visible
  - No information conveyed by color alone

#### NEW v2.2.1: Security Feature Testing
- [ ] SSRF protection active (see section 9 above)
- [ ] IP hashing working (check logs)
- [ ] Secure random being used (no predictable patterns in redirects)
- [ ] UTC timestamps consistent (no DST drift)
- [ ] Error messages sanitized (no SQL details to client)

## Important Security Notes

1. **Never commit .env file to version control**
2. **Change default admin credentials immediately**
3. **Monitor logs regularly for suspicious activity**
4. **Keep PHP and MySQL versions updated**
5. **Regular security audits recommended**
6. **NEW v2.2.1**: Keep `IP_HASH_SALT` secret (same level as API keys)
7. **NEW v2.2.1**: Test SSRF protection before going live
8. **NEW v2.2.1**: Verify IP hashing is working (check logs for hashed IPs only)
9. **NEW v2.2.1**: Test accessibility features with keyboard navigation
10. **NEW v2.2.1**: Confirm cache TTL is 60 seconds for optimal performance

## Rollback Plan

If issues occur:
1. Restore previous code version
2. Restore database from backup
3. Clear all caches
4. Check error logs for issues
5. Test basic functionality before going live

## Support Contacts

- Hosting Support: [Your hosting provider]
- System Admin: [Contact info]
- Developer: [Contact info]

---

Last Updated: November 29, 2025
Version: 2.2.1

## What's New in v2.2.1

**Critical Security Enhancements:**
- SSRF protection for postback URLs (DNS resolution + IP range blocking)
- GDPR-compliant IP hashing (SHA-256 + salt)
- Secure random using `random_int()` instead of `array_rand()`
- UTC timezone normalization to prevent DST drift
- Error message sanitization (no SQL details exposed)

**Performance Improvements:**
- Cache TTL optimized: 3s → 60s
- Query optimization with LIMIT clauses for bounded results
- DDL migration to bootstrap (removed from hot path)
- api_rate_limit table now created at initialization

**UI/UX Enhancements:**
- Deep linking via URL hash (#statistics, #logs, etc.)
- State persistence with localStorage
- Full keyboard navigation with arrow keys
- WCAG 2.1 Level AA accessibility compliance
- Lazy loading for per-tab data
- Browser back/forward navigation support
- Custom events (srp:tab-changed) for extensibility

**Required Configuration Changes:**
- NEW environment variable: `IP_HASH_SALT` (generate with `openssl rand -hex 32`)
- This is REQUIRED for v2.2.1 - deployment will fail without it

**Testing Requirements:**
- Test SSRF protection with private IP URLs
- Verify IP hashing in error logs
- Test keyboard navigation and accessibility
- Confirm deep linking and state persistence work
- Verify lazy loading behavior

## What's New in v2.2.0

- Enhanced API security with multiple keys
- PHP client libraries for easy integration
- Improved .htaccess security rules
- Bot blocking and rate limiting
- Health check monitoring endpoint
- Extended environment configuration
- Better PHP.ini production settings