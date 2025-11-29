<?php
declare(strict_types=1);

/**
 * Enhanced Postback Receiver Endpoint
 *
 * Receives postback notifications from external networks (iMonetizeIt, etc.)
 * Supports multiple parameter formats and logs all requests
 *
 * Supported Networks:
 * - iMonetizeIt (standard params)
 * - Generic networks (flexible params)
 * - Custom integrations
 *
 * URL Examples:
 * ?country=US&device=WAP&payout=0.50&status=confirmed
 * ?c=US&d=WAP&p=0.50&s=confirmed
 * ?geo=US&traffic_type=mobile&revenue=0.50&click_id=ABC123
 */

// Portable path untuk shared hosting: dirname(__DIR__) = /home/username
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Config\Database;

// Enable error reporting for debugging (disable in production)
// error_reporting(E_ALL);
// ini_set('display_errors', '1');

/**
 * Get parameter with multiple possible keys
 */
function getParam(array $source, array $keys, $default = null) {
    foreach ($keys as $key) {
        if (isset($source[$key]) && $source[$key] !== '') {
            return $source[$key];
        }
    }
    return $default;
}

/**
 * Sanitize and validate input (untuk database storage)
 *
 * PENTING: Tidak menggunakan htmlspecialchars di sini karena data masuk ke DB.
 * Escape HTML dilakukan saat OUTPUT ke browser, bukan saat input ke database.
 */
function sanitizeInput(string $value, int $maxLength = 255): string {
    $value = trim($value);
    // Remove control characters kecuali whitespace normal
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

// Get request parameters (support multiple naming conventions)
$params = array_merge($_GET, $_POST);

// Country: support multiple param names
$country = getParam($params, ['country', 'c', 'geo', 'country_code', 'cc'], '');
$country = strtoupper(sanitizeInput($country, 10));

// Traffic Type / Device: support multiple param names
$trafficType = getParam($params, ['device', 'd', 'traffic_type', 'type', 't', 'dt'], '');
$trafficType = strtoupper(sanitizeInput($trafficType, 50));

// Payout / Revenue: support multiple param names
$payoutStr = getParam($params, ['payout', 'p', 'revenue', 'amount', 'rev', 'a'], '0.00');
$payout = (float) preg_replace('/[^0-9.]/', '', $payoutStr);

// Status: support multiple param names
$status = getParam($params, ['status', 's', 'state', 'st'], 'confirmed');
$status = sanitizeInput($status, 50);

// Click ID: support multiple param names
$clickId = getParam($params, ['click_id', 'cid', 'clickid', 'transaction_id', 'txid'], null);
if ($clickId !== null) {
    $clickId = sanitizeInput($clickId, 100);
}

// Network / Source: identify where this postback came from
$network = getParam($params, ['network', 'source', 'src', 'net'], 'unknown');
$network = sanitizeInput($network, 50);

// Additional metadata
$timestamp = time();
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$queryString = $_SERVER['QUERY_STRING'] ?? '';

// Get raw POST body if present
$rawBody = file_get_contents('php://input');
if ($rawBody && strlen($rawBody) > 0 && strlen($rawBody) < 1000) {
    $queryString .= ' | BODY: ' . $rawBody;
}

// Validation: at least one meaningful parameter should be present
$hasData = ($country !== '' || $trafficType !== '' || $payout > 0 || $clickId !== null);

if (!$hasData) {
    // No meaningful data - return success but log as invalid
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => 'Postback acknowledged (no data)',
        'timestamp' => $timestamp,
        'warning' => 'No parameters detected'
    ], JSON_THROW_ON_ERROR);
    exit;
}

// Log the postback to database
try {
    $conn = Database::getConnection();

    // Prepare insert statement
    $stmt = $conn->prepare(
        'INSERT INTO postback_received
        (ts, status, country_code, traffic_type, payout, click_id, network, ip_address, query_string)
        VALUES (:ts, :status, :country_code, :traffic_type, :payout, :click_id, :network, :ip_address, :query_string)'
    );

    // Build detailed query string for logging
    $logString = sprintf(
        'METHOD=%s | %s',
        $requestMethod,
        $queryString
    );
    $logString = substr($logString, 0, 500); // Truncate to fit column

    // Execute with parameters
    $success = $stmt->execute([
        ':ts' => $timestamp,
        ':status' => $status,
        ':country_code' => $country,
        ':traffic_type' => $trafficType,
        ':payout' => $payout,
        ':click_id' => $clickId,
        ':network' => $network,
        ':ip_address' => $ipAddress,
        ':query_string' => $logString
    ]);

    $insertId = $conn->lastInsertId();

    // Return success response
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => 'Postback received successfully',
        'timestamp' => $timestamp,
        'id' => $insertId,
        'data' => [
            'country' => $country ?: null,
            'device' => $trafficType ?: null,
            'payout' => $payout,
            'status' => $status,
            'network' => $network
        ]
    ], JSON_THROW_ON_ERROR);

} catch (\Exception $e) {
    // Log error to file (don't log sensitive exception details)
    error_log(sprintf(
        '[POSTBACK ERROR] Database operation failed | IP: %s',
        $ipAddress
    ));

    // Still return success to prevent network retries
    // (we logged the error for debugging)
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => 'Postback acknowledged',
        'timestamp' => $timestamp,
        'note' => 'Logged for review'
    ], JSON_THROW_ON_ERROR);
}

exit;
