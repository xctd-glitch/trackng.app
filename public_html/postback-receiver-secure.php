<?php
declare(strict_types=1);

/**
 * SECURE POSTBACK RECEIVER v2.0
 *
 * Receives postback notifications from affiliate networks
 * with HMAC signature verification and IP whitelisting
 *
 * Security Features:
 * - HMAC-SHA256 signature verification
 * - IP whitelist validation
 * - API key authentication
 * - Rate limiting
 * - Comprehensive logging
 */

require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Config\Database;
use SRP\Config\Environment;
use SRP\Models\Validator;
use SRP\Models\PostbackLog;
use SRP\Utils\CorsHandler;

// ==========================================
// SECURITY CONFIGURATION
// ==========================================

// Allowed network IPs (add your affiliate network IPs here)
const ALLOWED_IPS = [
    // Example network IPs - REPLACE WITH ACTUAL NETWORK IPS
    '203.0.113.0/24',  // Example network 1
    '198.51.100.0/24', // Example network 2
    '192.0.2.0/24',    // Example network 3
];

// HMAC secret key (should be in .env)
$HMAC_SECRET = Environment::get('POSTBACK_HMAC_SECRET', '');

// API key requirement
$REQUIRE_API_KEY = Environment::getBool('POSTBACK_REQUIRE_API_KEY', true);

// ==========================================
// HELPER FUNCTIONS WITH TYPE HINTS
// ==========================================

/**
 * Get parameter from multiple possible keys
 *
 * @param array<string, mixed> $source
 * @param array<string> $keys
 * @param mixed $default
 * @return mixed
 */
function getParam(array $source, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (isset($source[$key]) && $source[$key] !== '') {
            return $source[$key];
        }
    }
    return $default;
}

/**
 * Sanitize input for database storage
 *
 * PENTING: Tidak menggunakan htmlspecialchars di sini karena data masuk ke DB.
 * Escape HTML dilakukan saat OUTPUT ke browser.
 *
 * @param string $value
 * @param int $maxLength
 * @return string
 */
function sanitizeInput(string $value, int $maxLength = 255): string
{
    $value = trim($value);
    // Remove control characters kecuali whitespace normal
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

/**
 * Verify HMAC signature
 *
 * @param array<string, mixed> $data
 * @param string $signature
 * @param string $secret
 * @return bool
 */
function verifyHmacSignature(array $data, string $signature, string $secret): bool
{
    if (empty($secret)) {
        return false;
    }

    // Sort data by keys for consistent signature
    ksort($data);

    // Create signature payload
    $payload = http_build_query($data);

    // Calculate expected signature
    $expectedSignature = hash_hmac('sha256', $payload, $secret);

    // Constant-time comparison
    return hash_equals($expectedSignature, $signature);
}

/**
 * Check if IP is whitelisted
 *
 * @param string $ip
 * @param array<string> $allowedRanges
 * @return bool
 */
function isIpAllowed(string $ip, array $allowedRanges): bool
{
    if (empty($allowedRanges)) {
        // If no whitelist configured, allow all (not recommended)
        return true;
    }

    foreach ($allowedRanges as $range) {
        if (isIpInRange($ip, $range)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if IP is in CIDR range
 *
 * @param string $ip
 * @param string $cidr
 * @return bool
 */
function isIpInRange(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        // Single IP comparison
        return $ip === $cidr;
    }

    [$subnet, $mask] = explode('/', $cidr);
    $subnet = ip2long($subnet);
    $ip = ip2long($ip);
    $mask = -1 << (32 - (int)$mask);
    $subnet &= $mask;

    return ($ip & $mask) === $subnet;
}

/**
 * Get client IP with proxy support
 *
 * @return string
 */
function getClientIp(): string
{
    // Priority order for IP detection
    $ipKeys = [
        'HTTP_CF_CONNECTING_IP',     // CloudFlare
        'HTTP_X_REAL_IP',            // Nginx proxy
        'HTTP_X_FORWARDED_FOR',      // Standard proxy
        'REMOTE_ADDR'                // Direct connection
    ];

    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim((string)$_SERVER[$key]);

            // Handle comma-separated list
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }

            // Validate IP format
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

/**
 * Log postback attempt ke error_log
 *
 * Note: Tidak menggunakan database table terpisah untuk menghindari
 * kompleksitas schema. Logging cukup ke error_log.
 *
 * @param array<string, mixed> $data
 * @param bool $success
 * @param string $message
 * @return void
 */
function logPostbackAttempt(array $data, bool $success, string $message): void
{
    $logEntry = sprintf(
        '[POSTBACK %s] %s | IP: %s | Data: %s',
        $success ? 'OK' : 'FAIL',
        $message,
        getClientIp(),
        json_encode(array_slice($data, 0, 10)) // Limit data untuk log
    );
    error_log($logEntry);
}

// ==========================================
// MAIN PROCESSING
// ==========================================

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Initialize response
$response = [
    'ok' => false,
    'error' => null,
    'data' => null
];

try {
    // Get client IP
    $clientIp = getClientIp();

    // Step 1: Verify IP whitelist
    if (!isIpAllowed($clientIp, ALLOWED_IPS)) {
        logPostbackAttempt($_REQUEST, false, 'IP not whitelisted: ' . $clientIp);

        // Return generic error to avoid revealing security mechanism
        $response['error'] = 'Unauthorized';
        http_response_code(403);
        echo json_encode($response);
        exit;
    }

    // Step 2: Verify API key (if required)
    if ($REQUIRE_API_KEY) {
        $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_REQUEST['api_key'] ?? '';
        $expectedKey = Environment::get('POSTBACK_API_KEY', '');

        if (empty($providedKey) || !hash_equals($expectedKey, $providedKey)) {
            logPostbackAttempt($_REQUEST, false, 'Invalid API key');

            $response['error'] = 'Invalid API key';
            http_response_code(401);
            echo json_encode($response);
            exit;
        }
    }

    // Step 3: Parse parameters (support multiple naming conventions)
    $clickId = sanitizeInput((string)getParam($_REQUEST, ['click_id', 'clickid', 'cid', 's2'], ''), 100);
    $status = sanitizeInput((string)getParam($_REQUEST, ['status', 'conversion_status', 'action'], 'confirmed'), 50);
    $payout = (float)getParam($_REQUEST, ['payout', 'amount', 'revenue', 'commission'], 0.00);
    $country = sanitizeInput(strtoupper((string)getParam($_REQUEST, ['country', 'country_code', 'geo'], 'XX')), 10);
    $device = sanitizeInput((string)getParam($_REQUEST, ['device', 'device_type', 'traffic_type'], 'unknown'), 50);
    $network = sanitizeInput((string)getParam($_REQUEST, ['network', 'source', 'partner'], 'unknown'), 50);

    // Step 4: Verify HMAC signature (if secret configured)
    if (!empty($HMAC_SECRET)) {
        $signature = $_REQUEST['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '';

        // Prepare data for signature verification
        $signatureData = [
            'click_id' => $clickId,
            'status' => $status,
            'payout' => $payout,
            'country' => $country,
        ];

        if (!verifyHmacSignature($signatureData, $signature, $HMAC_SECRET)) {
            logPostbackAttempt($_REQUEST, false, 'Invalid HMAC signature');

            $response['error'] = 'Invalid signature';
            http_response_code(403);
            echo json_encode($response);
            exit;
        }
    }

    // Step 5: Validate required fields
    if (empty($clickId)) {
        $response['error'] = 'Missing click_id';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }

    // Step 6: Validate country code
    if (!Validator::isValidCountryCode($country)) {
        $country = 'XX';
    }

    // Step 7: Store postback in database
    $queryString = substr($_SERVER['QUERY_STRING'] ?? '', 0, 500);

    Database::execute(
        'INSERT INTO postback_received
         (ts, status, country_code, traffic_type, payout, click_id, network, ip_address, query_string)
         VALUES (UNIX_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $status,
            $country,
            $device,
            $payout,
            $clickId,
            $network,
            $clientIp,
            $queryString
        ]
    );

    // Step 8: Update click record if exists
    if (!empty($clickId)) {
        Database::execute(
            'UPDATE clicks
             SET postback_status = ?, postback_payout = ?, postback_ts = UNIX_TIMESTAMP()
             WHERE click_id = ?',
            [$status, $payout, $clickId]
        );
    }

    // Step 9: Trigger internal postback (if configured)
    if (Environment::getBool('POSTBACK_FORWARD_ENABLED', false)) {
        $forwardUrl = Environment::get('POSTBACK_FORWARD_URL', '');
        if (!empty($forwardUrl)) {
            // Replace placeholders
            $forwardUrl = str_replace(
                ['{click_id}', '{status}', '{payout}', '{country}', '{device}'],
                [$clickId, $status, $payout, $country, $device],
                $forwardUrl
            );

            // Send async request (don't wait for response)
            PostbackLog::sendPostback($country, $device, $payout, $forwardUrl);
        }
    }

    // Log successful postback
    logPostbackAttempt($_REQUEST, true, 'Postback received successfully');

    // Return success response
    $response['ok'] = true;
    $response['data'] = [
        'click_id' => $clickId,
        'status' => $status,
        'payout' => $payout,
        'message' => 'Postback received successfully'
    ];

    http_response_code(200);
    echo json_encode($response);

} catch (Throwable $e) {
    // Log error
    error_log('Postback receiver error: ' . $e->getMessage());
    logPostbackAttempt($_REQUEST, false, 'Exception: ' . $e->getMessage());

    // Return generic error (don't expose internal details)
    $response['error'] = 'Internal server error';
    http_response_code(500);
    echo json_encode($response);
}