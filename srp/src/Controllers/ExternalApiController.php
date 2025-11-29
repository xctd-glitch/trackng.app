<?php

/**
 * External API Controller
 * Handles API requests from external hosting/domains
 */
declare(strict_types=1);

namespace SRP\Controllers;

use SRP\Config\Environment;
use SRP\Config\Database;
use SRP\Models\Validator;
use SRP\Utils\CorsHandler;
use SRP\Utils\RequestBody;
use SRP\Utils\IpDetector;

/**
 * External API Controller (Production-Ready)
 *
 * Proxy/wrapper untuk Decision API yang bisa diakses dari external domains
 *
 * Features:
 * - CORS support untuk any origin (public API)
 * - API key authentication
 * - Rate limiting (120 req/min per IP) via PDO
 * - Input validation via Validator
 * - Accepts GET and POST requests
 */
class ExternalApiController
{
    private const ALLOWED_ORIGINS = ['*']; // Allow from any origin
    private const RATE_LIMIT_WINDOW = 60; // 1 minute
    private const RATE_LIMIT_MAX = 120; // Max 120 requests per minute per IP

    /**
     * Handle external API request
     *
     * Accepts both GET and POST requests
     *
     * GET Example:
     * /api-external.php?click_id=ABC123&country_code=US&user_agent=mobile&ip_address=1.2.3.4
     *
     * POST Example:
     * POST /api-external.php
     * Body: {"click_id":"ABC123","country_code":"US","user_agent":"mobile","ip_address":"1.2.3.4"}
     */
    public static function handle(): void
    {
        // Step 1: Handle CORS
        if (CorsHandler::handle(self::ALLOWED_ORIGINS)) {
            exit;
        }

        // Step 2: Validate API key
        if (!self::validateApiKey()) {
            self::errorResponse('Unauthorized: Invalid or missing API key', 401);
        }

        // Step 3: Check rate limit
        $clientIp = IpDetector::getClientIp();
        if (!self::checkRateLimit($clientIp)) {
            self::errorResponse('Rate limit exceeded. Maximum ' . self::RATE_LIMIT_MAX . ' requests per minute.', 429);
        }

        // Step 4: Get request method
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Step 5: Parse input based on method
        try {
            if ($method === 'POST') {
                $input = self::parsePostInput();
            } else {
                $input = self::parseGetInput();
            }
        } catch (\RuntimeException $e) {
            self::errorResponse($e->getMessage(), 400);
        }

        // Step 6: Call internal decision API
        $decision = self::callDecisionApi($input);

        // Step 7: Return response
        if ($decision['success']) {
            self::successResponse($decision['data']);
        } else {
            self::errorResponse($decision['error'], $decision['code']);
        }
    }

    /**
     * Validate API key from header or query string
     */
    private static function validateApiKey(): bool
    {
        $apiKey = Environment::get('SRP_API_KEY');

        if ($apiKey === '') {
            return false;
        }

        // Check header first (recommended)
        $providedKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');

        // Fallback to query string (less secure but convenient)
        if ($providedKey === '') {
            $providedKey = Validator::sanitizeString($_GET['api_key'] ?? '', 255);
        }

        return Validator::hashEquals($apiKey, $providedKey);
    }

    /**
     * Parse POST request input
     */
    private static function parsePostInput(): array
    {
        try {
            $input = RequestBody::parseJson(true, 10240);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Invalid JSON body');
        }

        return self::sanitizeInput($input);
    }

    /**
     * Parse GET request input from query string
     */
    private static function parseGetInput(): array
    {
        $input = [
            'click_id' => $_GET['click_id'] ?? $_GET['cid'] ?? '',
            'country_code' => $_GET['country_code'] ?? $_GET['country'] ?? $_GET['cc'] ?? '',
            'user_agent' => $_GET['user_agent'] ?? $_GET['device'] ?? $_GET['ua'] ?? '',
            'ip_address' => $_GET['ip_address'] ?? $_GET['ip'] ?? '',
            'user_lp' => $_GET['user_lp'] ?? $_GET['lp'] ?? $_GET['campaign'] ?? '',
        ];

        return self::sanitizeInput($input);
    }

    /**
     * Sanitize and validate input parameters
     */
    private static function sanitizeInput(array $input): array
    {
        $clickId = Validator::sanitizeString($input['click_id'] ?? '', 100);
        $countryCode = Validator::sanitizeString($input['country_code'] ?? 'XX', 10);
        $userAgent = Validator::sanitizeString($input['user_agent'] ?? '', 500);
        $ipAddress = Validator::sanitizeString($input['ip_address'] ?? '', 45);
        $userLp = Validator::sanitizeString($input['user_lp'] ?? '', 100);

        // If IP not provided, use client IP
        if ($ipAddress === '') {
            $ipAddress = IpDetector::getClientIp();
        }

        // Validate IP address
        if (!Validator::isValidIp($ipAddress)) {
            throw new \RuntimeException('Invalid IP address format');
        }

        // Clean special characters
        $clickId = preg_replace('/[^a-zA-Z0-9_-]/', '', $clickId);
        $countryCode = strtoupper(preg_replace('/[^a-zA-Z]/', '', $countryCode));
        $userLp = preg_replace('/[^a-zA-Z0-9_-]/', '', $userLp);

        return [
            'click_id' => $clickId,
            'country_code' => $countryCode,
            'user_agent' => $userAgent,
            'ip_address' => $ipAddress,
            'user_lp' => $userLp,
        ];
    }

    /**
     * Call internal decision API using DecisionController
     *
     * @param array<string, string> $params
     * @return array{success: bool, data?: array, error?: string, code?: int}
     */
    private static function callDecisionApi(array $params): array
    {
        try {
            // Build internal API URL
            $apiUrl = Environment::get('SRP_API_URL');

            if ($apiUrl === '') {
                // Fallback: construct from current request
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $apiUrl = $protocol . '://' . $host . '/decision.php';
            }

            $apiKey = Environment::get('SRP_API_KEY');

            if ($apiKey === '') {
                throw new \RuntimeException('API key not configured');
            }

            // Use cURL untuk call internal API
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($params, JSON_THROW_ON_ERROR),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error !== '') {
                throw new \RuntimeException('Decision API error: ' . $error);
            }

            if ($httpCode !== 200) {
                throw new \RuntimeException('Decision API returned status ' . $httpCode);
            }

            if (!is_string($response) || $response === '') {
                throw new \RuntimeException('Empty response from decision API');
            }

            $data = json_decode($response, true);

            if (!is_array($data) || !isset($data['ok'])) {
                throw new \RuntimeException('Invalid response from decision API');
            }

            return [
                'success' => true,
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            error_log('External API error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 500,
            ];
        }
    }


    /**
     * Check rate limit for client IP (PDO-based)
     *
     * Uses sliding window rate limiting
     */
    private static function checkRateLimit(string $ip): bool
    {
        try {
            $tableName = 'api_rate_limit';

            $currentTime = time();
            $windowStart = $currentTime - self::RATE_LIMIT_WINDOW;

            // Clean up old entries (older than window)
            Database::execute(
                "DELETE FROM `{$tableName}` WHERE window_start < ?",
                [$windowStart]
            );

            // Check current rate
            $stmt = Database::execute(
                "SELECT requests FROM `{$tableName}`
                 WHERE ip_address = ? AND window_start >= ?",
                [$ip, $windowStart]
            );

            $row = $stmt->fetch();

            if ($row) {
                $requests = (int) $row['requests'];

                if ($requests >= self::RATE_LIMIT_MAX) {
                    return false; // Rate limit exceeded
                }

                // Increment counter
                Database::execute(
                    "UPDATE `{$tableName}`
                     SET requests = requests + 1
                     WHERE ip_address = ?",
                    [$ip]
                );
            } else {
                // Insert new entry
                Database::execute(
                    "INSERT INTO `{$tableName}` (ip_address, requests, window_start)
                     VALUES (?, 1, ?)
                     ON DUPLICATE KEY UPDATE requests = 1, window_start = ?",
                    [$ip, $currentTime, $currentTime]
                );
            }

            return true;
        } catch (\Throwable $e) {
            error_log('Rate limit check failed: ' . $e->getMessage());
            // Fail open - allow request if rate limiting fails
            return true;
        }
    }

    /**
     * Send success response
     *
     * @param array<string, mixed> $data
     */
    private static function successResponse(array $data): void
    {
        CorsHandler::jsonResponse($data, 200, self::ALLOWED_ORIGINS);
    }

    /**
     * Send error response
     */
    private static function errorResponse(string $message, int $code = 400): void
    {
        CorsHandler::errorResponse($message, $code, self::ALLOWED_ORIGINS);
    }
}
