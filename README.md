# Smart Redirect Platform (SRP) v2.2.1

**Production-Ready Traffic Management System**

PHP 8.3+ | MySQL 5.7+ | Multi-Domain Architecture | Shared Hosting Compatible

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Quick Start](#quick-start)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Database](#database)
7. [API Reference](#api-reference)
8. [API Integration](#api-integration)
9. [Security](#security)
10. [Deployment](#deployment)
11. [Maintenance](#maintenance)
12. [Troubleshooting](#troubleshooting)

---

## Overview

Smart Redirect Platform (SRP) adalah sistem tracking dan redirect berbasis multi-domain architecture dengan pemisahan ketat antara:

- **Brand Domain** (`trackng.app`) → Admin panel, landing page, dokumentasi
- **Tracking Domain** (`qvtrk.com`) → Redirect service, Decision API, postback receiver

### Features

- Smart routing berdasarkan country & device
- Decision API dengan rate limiting
- Postback receiver untuk affiliate networks
- Real-time traffic logging
- Admin dashboard dengan statistics
- Environment configuration via UI
- Security audit logging
- Auto-cleanup untuk old data
- **SSRF protection** untuk postback URLs (DNS resolution + IP range validation)
- **GDPR-compliant IP hashing** dengan SHA-256 + salt
- **Enhanced UI accessibility** (WCAG 2.1 Level AA compliance)
- **Deep linking** dan state persistence untuk tab navigation
- **Keyboard navigation** dengan full ARIA support

### Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP | 8.3+ | 8.3+ |
| MySQL/MariaDB | 5.7+ | 8.0+ |
| Disk Space | 500 MB | 1 GB |
| Memory | 128 MB | 256 MB |

**Required PHP Extensions:**
- PDO + pdo_mysql
- cURL
- JSON
- mbstring
- OpenSSL

---

## Architecture

### Domain Separation

```
Brand Domain (trackng.app):
├── trackng.app           → Landing page
├── panel.trackng.app     → Admin panel
└── www.trackng.app       → Alias

Tracking Domain (qvtrk.com):
├── qvtrk.com             → Status page
├── t.qvtrk.com           → Redirect service
├── api.qvtrk.com         → Decision API
└── postback.qvtrk.com    → Postback receiver
```

### File Structure

```
/home/username/
├── public_html/              ← Brand domain webroot
│   ├── index.php             → Dashboard entry
│   ├── login.php             → Authentication
│   ├── data.php              → Dashboard API
│   ├── assets/               → CSS, JS, images
│   └── .htaccess             → Security rules
│
├── public_html_tracking/     ← Tracking domain webroot
│   ├── r.php                 → Redirect service
│   ├── decision.php          → Decision API
│   ├── postback-receiver.php → Postback handler
│   └── .htaccess             → CORS & security
│
├── srp/                      ← Application (OUTSIDE webroot)
│   ├── src/
│   │   ├── Config/           → Database, Environment
│   │   ├── Controllers/      → Business logic
│   │   ├── Models/           → Data access
│   │   ├── Middleware/       → Session, Security
│   │   ├── Utils/            → Helpers
│   │   └── Views/            → Templates
│   └── .env                  → Configuration
│
└── storage/                  ← Logs & cache
    ├── logs/
    └── cache/
```

---

## Quick Start

```bash
# 1. Upload files ke hosting
# 2. Create database & import schema
mysql -u user -p database < db.bak/schema.sql

# 3. Configure .env
cp srp/.env.example srp/.env
nano srp/.env

# 4. Update bootstrap paths di semua PHP files
# 5. Set permissions
chmod 600 srp/.env
chmod 755 storage/logs

# 6. Test endpoints
curl -I https://trackng.app
curl -I https://t.qvtrk.com/r.php?click_id=test
```

**Default Credentials:**
- Username: `admin`
- Password: `password123`
- **GANTI SEGERA SETELAH LOGIN!**

---

## Installation

### Step 1: DNS Configuration

**Brand Domain (trackng.app):**

| Type | Name | Value |
|------|------|-------|
| A | @ | SERVER_IP |
| A | panel | SERVER_IP |
| A | www | SERVER_IP |

**Tracking Domain (qvtrk.com):**

| Type | Name | Value |
|------|------|-------|
| A | @ | SERVER_IP |
| A | t | SERVER_IP |
| A | api | SERVER_IP |
| A | postback | SERVER_IP |

### Step 2: cPanel Setup

1. **Subdomains** → Create `panel.trackng.app` → `/public_html`
2. **Addon Domains** → Add `qvtrk.com` → `/public_html_tracking`
3. **Subdomains** → Create `t`, `api`, `postback` untuk `qvtrk.com` → `/public_html_tracking`

### Step 3: Upload Files

Via FTP (FileZilla):
```
Local                      → Remote
srp/                       → /home/username/srp/
public_html/*              → /home/username/public_html/
public_html_tracking/*     → /home/username/public_html_tracking/
```

### Step 4: Database Setup

1. cPanel → **MySQL Databases**
2. Create database: `username_srp`
3. Create user: `username_srpuser`
4. Add user to database → ALL PRIVILEGES
5. phpMyAdmin → Import `db.bak/schema.sql`

### Step 5: Configure Environment

Edit `srp/.env`:

```env
# Environment
SRP_ENV=production
APP_DEBUG=false

# Brand Domain
APP_URL="https://trackng.app"
APP_PANEL_URL="https://panel.trackng.app"

# Tracking Domain
TRACKING_PRIMARY_DOMAIN="qvtrk.com"
TRACKING_REDIRECT_URL="https://t.qvtrk.com"
TRACKING_DECISION_API="https://api.qvtrk.com"
TRACKING_POSTBACK_URL="https://postback.qvtrk.com"

# Database
DB_HOST=localhost
DB_NAME=username_srp
DB_USER=username_srpuser
DB_PASS=your_password

# API Keys (generate: openssl rand -hex 32)
API_KEY_INTERNAL=your_32_char_key_here
API_KEY_EXTERNAL=your_32_char_key_here

# Security & Privacy
IP_HASH_SALT=your_random_32_char_salt_here

# Paths
APP_ROOT=/home/username/srp
LOG_PATH=/home/username/storage/logs/app.log
```

### Step 6: Update Bootstrap Paths

Edit semua PHP entry points, ganti path:

```php
// Dari:
require_once __DIR__ . '/../srp/src/bootstrap.php';

// Menjadi:
require_once '/home/username/srp/src/bootstrap.php';
```

**Files yang perlu diupdate:**
- `public_html/*.php` (semua file)
- `public_html_tracking/*.php` (semua file)

### Step 7: Set Permissions

```bash
chmod 600 /home/username/srp/.env
chmod 755 /home/username/storage/logs
chmod 755 /home/username/public_html
chmod 755 /home/username/public_html_tracking
```

### Step 8: SSL Setup

cPanel → **SSL/TLS Status** → Run AutoSSL

---

## Configuration

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `SRP_ENV` | production/development | Yes |
| `APP_DEBUG` | Debug mode (false in production) | Yes |
| `APP_NAME` | Application name | Yes |
| `APP_URL` | Brand domain URL | Yes |
| `TRACKING_*` | Tracking domain URLs | Yes |
| `DB_*` | Database credentials | Yes |
| `API_KEY_INTERNAL` | Internal API key (32 chars) | Yes |
| `API_KEY_EXTERNAL` | External API key (32 chars) | Yes |
| `DECISION_API_KEY` | Decision API key for clients | Yes |
| `POSTBACK_SECRET` | Postback verification secret | Yes |
| `TRUST_CF_HEADERS` | Trust Cloudflare headers | Optional |
| `SESSION_LIFETIME` | Session timeout (seconds) | Optional |
| `CACHE_DRIVER` | Cache driver (file/redis) | Optional |
| `LOG_LEVEL` | Log level (error/debug) | Optional |
| `RATE_LIMIT_ENABLED` | Enable rate limiting | Optional |
| `HEALTH_CHECK_TOKEN` | Health check endpoint token | Optional |
| `IP_HASH_SALT` | Salt for IP address hashing (32+ chars) | **Required** |

### PHP Configuration

Production `php.ini` settings:

```ini
; Basic Settings
engine = On
short_open_tag = Off
precision = 14
output_buffering = 4096

; Resource Limits
max_execution_time = 30
max_input_time = 60
max_input_vars = 1000
memory_limit = 256M
post_max_size = 10M
upload_max_filesize = 10M

; Error Handling (Production)
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = storage/logs/php_error.log

; Security
expose_php = Off
allow_url_fopen = Off
allow_url_include = Off
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,eval

; Session
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Strict

; OPcache
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
```

---

## Database

### Schema

Single file: `db.bak/schema.sql` - Contains everything needed.

```bash
mysql -u username -p database_name < db.bak/schema.sql
```

### Tables Overview

**Core Tables:**
| Table | Description |
|-------|-------------|
| `users` | Admin users |
| `settings` | System configuration |
| `clicks` | Click tracking |
| `traffic_logs` | Decision API logs |
| `postback_logs` | Outgoing postbacks |
| `postback_received` | Incoming postbacks |

**Management Tables:**
| Table | Description |
|-------|-------------|
| `routing_rules` | Smart routing config |
| `api_keys` | API key management |
| `audit_log` | Admin actions |
| `env_config` | Environment sync |

**Security Tables:**
| Table | Description |
|-------|-------------|
| `postback_security_log` | Security audit |
| `rate_limit_tracking` | Rate limiting |
| `failed_login_attempts` | Login security |

### Maintenance

Auto-cleanup event runs daily:
- Security logs: 30 days retention
- Traffic logs: 7 days retention
- Rate limits: 1 day retention

Enable event scheduler:
```sql
SET GLOBAL event_scheduler = ON;
```

### Backup

```bash
# Full backup
mysqldump -u user -p database > backup_$(date +%Y%m%d).sql

# Compressed
mysqldump -u user -p database | gzip > backup_$(date +%Y%m%d).sql.gz
```

---

## API Reference

### Decision API

**Endpoint:** `POST https://api.qvtrk.com/decision.php`

**Headers:**
```
X-API-Key: your_api_key
Content-Type: application/json
```

**Request:**
```json
{
  "click_id": "abc123",
  "country_code": "US",
  "user_agent": "Mozilla/5.0...",
  "ip_address": "1.2.3.4",
  "user_lp": "landing1"
}
```

**Response:**
```json
{
  "ok": true,
  "decision": "A",
  "target": "https://example.com/offer?click_id=abc123"
}
```

### Redirect Service

**Endpoint:** `GET https://t.qvtrk.com/r.php`

**Parameters:**
| Param | Description |
|-------|-------------|
| `click_id` | Unique click identifier |
| `lp` | Landing page identifier |
| `country` | Override country code |
| `device` | Override device type |

**Example:**
```
https://t.qvtrk.com/r.php?click_id=abc123&lp=offer1
```

### Postback Receiver

**Endpoint:** `GET/POST https://postback.qvtrk.com/postback-receiver.php`

**Parameters:**
| Param | Description |
|-------|-------------|
| `status` | confirmed/pending/rejected |
| `country` | Country code |
| `payout` | Payout amount |
| `click_id` | Click identifier |
| `device` | Device type |

**Example:**
```
https://postback.qvtrk.com/postback-receiver.php?status=confirmed&country=US&payout=1.50&click_id=abc123
```

---

## API Integration

### PHP Client Library

SRP provides two ready-to-use PHP clients for easy integration:

#### 1. Full-Featured Client (`srp-decision-client.php`)

```php
// Include the client
require_once 'srp-decision-client.php';

// Initialize client
$client = new SrpDecisionClient(
    'your_api_key_here',
    'https://your-fallback-url.com'
);

// Process request automatically
$client->processRequest();
```

**Features:**
- Object-oriented design
- Error handling & logging
- Cloudflare header support
- Customizable timeout
- Helper methods for client data

#### 2. Simple Drop-in Script (`srp-decision-simple.php`)

For quick integration, just update the configuration:

```php
$config = [
    'api_key' => 'your_api_key_here',
    'fallback_url' => 'https://fallback-url.com',
    'api_url' => 'https://api.qvtrk.com/decision.php',
    'timeout' => 5,
    'trust_cloudflare' => true
];
```

Upload the file and it's ready to use.

### Integration Examples

#### Basic Integration

```php
// Get decision API response
$apiUrl = 'https://api.qvtrk.com/decision.php';
$apiKey = 'your_api_key_here';

$data = [
    'click_id' => $_GET['cid'] ?? uniqid(),
    'country_code' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
    'ip_address' => $_SERVER['REMOTE_ADDR']
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ]
]);

$response = curl_exec($ch);
$result = json_decode($response, true);

// Redirect based on decision
header('Location: ' . ($result['target'] ?? 'https://fallback.com'));
```

#### JavaScript/Node.js Integration

```javascript
const https = require('https');

async function getSrpDecision(clickId, countryCode, userAgent, ipAddress) {
    const data = JSON.stringify({
        click_id: clickId,
        country_code: countryCode,
        user_agent: userAgent,
        ip_address: ipAddress
    });

    const options = {
        hostname: 'api.qvtrk.com',
        port: 443,
        path: '/decision.php',
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-Key': 'your_api_key_here'
        }
    };

    // Make request and handle response
}
```

### Testing the API

#### Using cURL

```bash
curl -X POST https://api.qvtrk.com/decision.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_api_key" \
  -d '{
    "click_id": "test123",
    "country_code": "US",
    "user_agent": "Mozilla/5.0",
    "ip_address": "203.0.113.1"
  }'
```

#### Expected Response

```json
{
  "ok": true,
  "decision": "A",
  "target": "https://example.com/offer?click_id=test123"
}
```

### Best Practices

1. **Always implement fallback handling** - API might be temporarily unavailable
2. **Use proper timeout settings** - Recommended 5-10 seconds
3. **Store API keys securely** - Never hardcode in public repositories
4. **Use real client IP** - Not server IP, check Cloudflare headers
5. **Implement caching** - Reduce API calls for same user/conditions
6. **Monitor error rates** - Log failed requests for debugging

---

## Security

### Immediate Actions After Deployment

1. **Change Admin Password:**
```sql
-- Generate: php -r "echo password_hash('NewPassword', PASSWORD_BCRYPT);"
UPDATE users SET password_hash = '$2y$10$NEW_HASH' WHERE username = 'admin';
```

2. **Generate New API Keys:**
```bash
openssl rand -hex 32
```

3. **Generate IP Hash Salt:**
```bash
# Generate 32-character random salt for IP hashing (GDPR compliance)
openssl rand -hex 32
```

4. **Verify Permissions:**
```bash
chmod 600 srp/.env
chmod 700 storage/logs
```

### SSRF Protection

SRP melindungi postback URLs dari Server-Side Request Forgery (SSRF) attacks dengan:

1. **DNS Resolution Validation:**
   - Semua URLs di-resolve terlebih dahulu ke IP address
   - IP address divalidasi terhadap blocked ranges

2. **Blocked IP Ranges:**
   - Private networks (RFC1918): 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
   - Loopback: 127.0.0.0/8, ::1/128
   - Link-local: 169.254.0.0/16, fe80::/10
   - Cloud metadata services: 169.254.169.254/32
   - IPv6 ULA: fd00::/8

3. **URL Validation:**
   - Hanya HTTP dan HTTPS scheme yang diizinkan
   - URL harus valid dan parseable
   - Host tidak boleh kosong

**Implementasi:**
```php
// Settings.php - validateUrl() method
// Automatically validates all postback URLs before saving
```

### IP Privacy & GDPR Compliance

Untuk memenuhi regulasi privasi (GDPR, CCPA), SRP tidak menyimpan IP address dalam format plain text:

1. **IP Hashing:**
   - Semua IP address di-hash dengan SHA-256 sebelum logging
   - Menggunakan salt dari environment variable `IP_HASH_SALT`
   - Hash dipotong untuk efisiensi penyimpanan (16 karakter)

2. **Configuration:**
```env
# Generate dengan: openssl rand -hex 32
IP_HASH_SALT=change_this_to_random_32_character_salt
```

3. **Implementation Details:**
   - VPN check errors: Log hashed IP (16 chars)
   - Rate limiting: Menggunakan IP asli (tidak di-hash) untuk akurasi
   - Audit logs: IP di-hash untuk compliance

**Catatan:** Salt harus dijaga kerahasiaannya dan tidak boleh di-commit ke version control.

### Security Headers

**Brand Domain (.htaccess):**
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer`
- `Strict-Transport-Security` (HSTS)
- `Content-Security-Policy` with nonce

**Tracking Domain (.htaccess):**
- Minimal headers for performance
- CORS: `Access-Control-Allow-Origin: *`

### Cross-Domain Blocking

Brand domain blocks tracking endpoints:
```apache
<FilesMatch "(decision|r|postback-receiver)\.php$">
    Require all denied
</FilesMatch>
```

Tracking domain blocks admin endpoints:
```apache
<FilesMatch "(login|logout|dashboard|data)\.php$">
    Require all denied
</FilesMatch>
```

### Session Security

- HttpOnly cookies
- Secure flag (HTTPS only)
- SameSite=Strict
- Session fingerprinting
- 5-minute ID rotation
- 1-hour inactivity timeout

### Admin Dashboard UI Security & Accessibility

Dashboard UI telah ditingkatkan dengan:

1. **Deep Linking:**
   - URL hash support untuk direct tab access
   - Shareable links ke specific tabs (misal: `#statistics`)
   - Browser back/forward navigation support

2. **State Persistence:**
   - Tab preference disimpan di localStorage
   - User preference dipertahankan across sessions

3. **Accessibility (WCAG 2.1 Level AA):**
   - Full keyboard navigation dengan arrow keys
   - ARIA attributes: `role="tab"`, `aria-selected`, `aria-controls`
   - Proper focus management dengan `tabindex`
   - Screen reader support

4. **Performance:**
   - Lazy loading: Data dimuat hanya saat tab diaktifkan
   - Smooth scroll on tab change
   - Custom events untuk integration (`srp:tab-changed`)

5. **Browser Compatibility:**
   - History API untuk navigation
   - hashchange event listeners
   - LocalStorage untuk persistence

---

## Deployment

### Production Build

```bash
# Linux/Mac
chmod +x build-production.sh
./build-production.sh

# Windows
build-production.bat
```

Output: `srp_production_YYYYMMDD_HHMMSS.zip`

### Manual Deployment via FTP

1. Upload `srp/` → `/home/username/srp/`
2. Upload `public_html/*` → `/home/username/public_html/`
3. Upload `public_html_tracking/*` → `/home/username/public_html_tracking/`
4. Create & configure `.env`
5. Import database schema
6. Update bootstrap paths
7. Set permissions
8. Test endpoints

### Automated Deployment (SSH)

```bash
cd deployment
cp deploy-config.sh.example deploy-config.sh
nano deploy-config.sh  # Edit credentials
./deploy.sh production
```

### Post-Deployment Checklist

- [ ] Admin password changed
- [ ] API keys generated & updated
- [ ] SSL certificates installed
- [ ] All endpoints tested
- [ ] Error logs checked
- [ ] Cross-domain blocking verified
- [ ] Security headers present

---

## Maintenance

### Log Rotation

Cron job (daily):
```bash
0 0 * * * find /home/username/storage/logs -name "*.log" -mtime +7 -delete
```

### Database Backup

Cron job (daily at 2 AM):
```bash
0 2 * * * mysqldump -u user -p'PASS' database | gzip > ~/backups/srp_$(date +\%Y\%m\%d).sql.gz
```

### Cache Clearing

```bash
rm -rf /home/username/storage/cache/*
```

### PHP OPcache

Clear after uploading new PHP files:
```bash
# Via cPanel or restart PHP-FPM
killall -9 php-fpm
```

---

## Troubleshooting

### 500 Internal Server Error

**Causes:**
- PHP syntax error
- Wrong file permissions
- Missing PHP extensions
- .htaccess misconfiguration

**Solutions:**
```bash
# Check error logs
tail -f /home/username/logs/error.log

# Test PHP syntax
php -l /home/username/public_html/index.php

# Temporarily disable .htaccess
mv .htaccess .htaccess.bak
```

### Database Connection Failed

**Causes:**
- Wrong credentials in .env
- User lacks privileges
- MySQL service down

**Solutions:**
```bash
# Test connection
mysql -u user -p -h localhost

# Verify database exists
SHOW DATABASES LIKE 'username_srp';

# Re-grant privileges via cPanel
```

### API Key Invalid

**Causes:**
- Key mismatch between .env and database
- Hidden characters in key

**Solutions:**
```bash
# Check .env
cat srp/.env | grep API_KEY

# Check database
SELECT api_key FROM api_keys;
```

### CORS Errors

**Solutions:**
```bash
# Verify headers
curl -I -H "Origin: https://example.com" https://api.qvtrk.com/decision.php

# Check .htaccess
cat public_html_tracking/.htaccess | grep -i cors
```

### SSL Not Working

**Causes:**
- DNS not propagated
- AutoSSL not run
- Cloudflare SSL mode wrong

**Solutions:**
```bash
# Check DNS
nslookup trackng.app

# Re-run AutoSSL via cPanel
# Set Cloudflare SSL to "Full"
```

---

## Support

### Useful Commands

```bash
# Check PHP version
php -v

# Check disk usage
du -sh /home/username/*

# View logs
tail -f /home/username/storage/logs/error.log

# Test MySQL
mysql -u user -p -h localhost -e "SHOW TABLES;"
```

### Online Tools

- SSL Test: https://www.ssllabs.com/ssltest/
- DNS Checker: https://dnschecker.org
- Security Headers: https://securityheaders.com

### cPanel Locations

- File Manager: Home → Files
- MySQL: Home → Databases
- phpMyAdmin: Home → Databases
- Subdomains: Home → Domains
- SSL/TLS: Home → Security
- PHP Version: Home → Software

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 2.2.1 | 2025-11-29 | Code review fixes, UI accessibility enhancements, GDPR compliance |
| 2.2.0 | 2025-11-29 | Enhanced configuration, API client libraries, improved documentation |
| 2.1.1 | 2025-11-27 | Bug fixes, merged SQL, cleaned unused files |
| 2.1.0 | 2025-11-26 | Security tables, error handling improvements |
| 2.0.0 | 2025-01-23 | Initial production release |

### What's New in v2.2.1

**Security Enhancements:**
- **SSRF Protection**: DNS resolution + IP range validation untuk postback URLs
- **IP Hashing**: GDPR-compliant logging dengan SHA-256 + salt
- **Secure Random**: Menggunakan `random_int()` untuk cryptographically secure operations
- **Cache Optimization**: TTL ditingkatkan 3s → 60s untuk performance
- **Timezone Normalization**: UTC-based timestamps dengan `gmdate()`

**Performance Improvements:**
- **Query Optimization**: LIMIT clauses untuk bounded result sets
- **DDL Migration**: Table creation dipindah ke bootstrap (bukan hot path)
- **Error Sanitization**: Database details tidak di-expose ke client

**UI/UX Enhancements:**
- **Deep Linking**: URL hash support untuk tab navigation
- **State Persistence**: localStorage untuk tab preferences
- **Keyboard Navigation**: Arrow keys + full ARIA support
- **Accessibility**: WCAG 2.1 Level AA compliance
- **Lazy Loading**: Per-tab data loading untuk faster initial load
- **Browser Navigation**: Back/forward button support

**Developer Experience:**
- **New Environment Variable**: `IP_HASH_SALT` untuk IP privacy
- **Enhanced Documentation**: Security best practices, accessibility guide
- **Better Error Messages**: Generic client errors, detailed server logs

### What's New in v2.2.0

- **PHP Client Libraries**: Ready-to-use integration files (`srp-decision-client.php` and `srp-decision-simple.php`)
- **Enhanced Configuration**: Extended environment variables for better control
- **Improved Security**: Updated .htaccess rules, CSP headers, bot blocking
- **Better Documentation**: API integration guide with multiple language examples
- **Performance**: Optimized PHP.ini settings for production
- **Health Checks**: New monitoring endpoint with token authentication

---

**License:** Proprietary
**PHP Required:** >= 8.3
**MySQL Required:** >= 5.7
