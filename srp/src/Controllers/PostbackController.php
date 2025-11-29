<?php

declare(strict_types=1);

namespace SRP\Controllers;

use SRP\Config\Database;
use SRP\Middleware\Session;
use SRP\Models\Settings;
use SRP\Models\PostbackLog;
use SRP\Models\Validator;
use SRP\Utils\Csrf;

/**
 * Postback Controller (Production-Ready)
 *
 * Handles postback configuration and testing
 *
 * Features:
 * - Session-based authentication
 * - CSRF protection via Csrf helper
 * - Input validation via Validator
 * - PDO untuk all database queries
 */
class PostbackController
{
    /**
     * Handle postback request (GET atau POST)
     */
    public static function handleRequest(): void
    {
        Session::start();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Handle GET requests (load postback logs)
        if ($method === 'GET') {
            self::getPostbackLogs();
            return;
        }

        // Handle POST requests
        if ($method === 'POST') {
            self::handlePostRequest();
            return;
        }

        self::respondError('Method not allowed', 405);
    }

    /**
     * Get postback logs (GET request)
     *
     * @return void
     */
    private static function getPostbackLogs(): void
    {
        // Check authentication
        if (!Session::isAuthenticated()) {
            self::respondError('Authentication required', 401);
        }

        // Check if requesting received postbacks
        $action = Validator::sanitizeString($_GET['action'] ?? 'logs', 20);

        if ($action === 'received') {
            self::getReceivedPostbacks();
            return;
        }

        if ($action === 'stats') {
            self::getDailyStats();
            return;
        }

        // Get sent postbacks (default)
        try {
            // Get limit from query string (default 20, max 100)
            $limit = Validator::sanitizeInt($_GET['limit'] ?? '20', 20, 1, 100);

            $logs = PostbackLog::getRecent($limit);
            self::respond(['ok' => true, 'logs' => $logs]);
        } catch (\Throwable $e) {
            // Log detailed error untuk debugging
            error_log('PostbackController::getPostbackLogs error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            // Check if it's a database table error
            $errorMessage = 'Failed to load postback logs';
            if (strpos($e->getMessage(), "doesn't exist") !== false ||
                strpos($e->getMessage(), "Base table or view not found") !== false ||
                strpos($e->getMessage(), "1146") !== false) {
                error_log('CRITICAL: Table postback_logs does not exist!');
                error_log('Error details: ' . $e->getMessage());
                $errorMessage = 'Database configuration error. Please contact system administrator.';
            }

            self::respondError($errorMessage, 500);
        }
    }

    /**
     * Get daily/weekly payout statistics
     *
     * Week start: Monday 07:00 UTC+7 (Monday 00:00 UTC)
     * Week boundary offset: -7 hours (25200 seconds)
     *
     * @return void
     */
    private static function getDailyStats(): void
    {
        try {
            // Get parameters
            $days = Validator::sanitizeInt($_GET['days'] ?? '30', 30, 1, 365);
            $view = Validator::sanitizeString($_GET['view'] ?? 'daily', 10);

            // Validate view
            if (!in_array($view, ['daily', 'weekly'], true)) {
                $view = 'daily';
            }

            // Build query based on view
            if ($view === 'weekly') {
                // Weekly aggregation: Week starts Monday 07:00 UTC+7
                // Offset: ts - 25200 (7 hours = 7*3600 seconds)
                // Formula: Get Monday of (timestamp - 7 hours)
                $stmt = Database::execute(
                    'SELECT
                        DATE_SUB(
                            DATE(FROM_UNIXTIME(ts - 25200)),
                            INTERVAL WEEKDAY(FROM_UNIXTIME(ts - 25200)) DAY
                        ) as date,
                        DATE_ADD(
                            DATE_SUB(
                                DATE(FROM_UNIXTIME(ts - 25200)),
                                INTERVAL WEEKDAY(FROM_UNIXTIME(ts - 25200)) DAY
                            ),
                            INTERVAL 6 DAY
                        ) as week_end,
                        COUNT(*) as total_postbacks,
                        SUM(payout) as total_payout,
                        AVG(payout) as avg_payout,
                        MIN(payout) as min_payout,
                        MAX(payout) as max_payout,
                        COUNT(DISTINCT traffic_type) as unique_traffic_types,
                        COUNT(DISTINCT country_code) as unique_countries,
                        COUNT(DISTINCT network) as unique_networks
                    FROM postback_received
                    WHERE ts >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))
                    GROUP BY DATE_SUB(
                        DATE(FROM_UNIXTIME(ts - 25200)),
                        INTERVAL WEEKDAY(FROM_UNIXTIME(ts - 25200)) DAY
                    )
                    ORDER BY date DESC
                    LIMIT 365',
                    [$days]
                );
            } else {
                // Daily aggregation (default)
                $stmt = Database::execute(
                    'SELECT
                        DATE(FROM_UNIXTIME(ts)) as date,
                        COUNT(*) as total_postbacks,
                        SUM(payout) as total_payout,
                        AVG(payout) as avg_payout,
                        MIN(payout) as min_payout,
                        MAX(payout) as max_payout,
                        COUNT(DISTINCT traffic_type) as unique_traffic_types,
                        COUNT(DISTINCT country_code) as unique_countries,
                        COUNT(DISTINCT network) as unique_networks
                    FROM postback_received
                    WHERE ts >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL ? DAY))
                    GROUP BY DATE(FROM_UNIXTIME(ts))
                    ORDER BY date DESC
                    LIMIT 365',
                    [$days]
                );
            }

            $stats = [];
            while ($row = $stmt->fetch()) {
                $record = [
                    'date' => $row['date'],
                    'total_postbacks' => (int) $row['total_postbacks'],
                    'total_payout' => (float) $row['total_payout'],
                    'avg_payout' => (float) $row['avg_payout'],
                    'min_payout' => (float) $row['min_payout'],
                    'max_payout' => (float) $row['max_payout'],
                    'unique_traffic_types' => (int) $row['unique_traffic_types'],
                    'unique_countries' => (int) $row['unique_countries'],
                    'unique_networks' => (int) ($row['unique_networks'] ?? 0)
                ];

                // Add week_end for weekly view
                if ($view === 'weekly' && isset($row['week_end'])) {
                    $record['week_end'] = $row['week_end'];
                }

                $stats[] = $record;
            }

            // Calculate totals
            $totalPostbacks = array_sum(array_column($stats, 'total_postbacks'));
            $totalPayout = array_sum(array_column($stats, 'total_payout'));
            $avgDailyPayout = count($stats) > 0 ? $totalPayout / count($stats) : 0;

            self::respond([
                'ok' => true,
                'view' => $view,
                'stats' => $stats,
                'summary' => [
                    'total_postbacks' => $totalPostbacks,
                    'total_payout' => round($totalPayout, 2),
                    'avg_daily_payout' => round($avgDailyPayout, 2),
                    'days_count' => count($stats),
                    'period_days' => $days
                ]
            ]);
        } catch (\Throwable $e) {
            error_log('Error loading daily stats: ' . $e->getMessage());
            self::respondError('Failed to load daily statistics', 500);
        }
    }

    /**
     * Get received postbacks (dari affiliate networks)
     *
     * @return void
     */
    private static function getReceivedPostbacks(): void
    {
        try {
            // Get limit from query string (default 50, max 200)
            $limit = Validator::sanitizeInt($_GET['limit'] ?? '50', 50, 1, 200);

            // Query dengan PDO - include network column
            $stmt = Database::execute(
                'SELECT
                    id,
                    ts,
                    status,
                    country_code,
                    traffic_type,
                    payout,
                    click_id,
                    network,
                    ip_address,
                    query_string
                FROM postback_received
                ORDER BY ts DESC
                LIMIT ?',
                [$limit]
            );

            $logs = [];
            while ($row = $stmt->fetch()) {
                $logs[] = $row;
            }

            self::respond(['ok' => true, 'logs' => $logs]);
        } catch (\Throwable $e) {
            error_log('Error loading received postbacks: ' . $e->getMessage());
            self::respondError('Failed to load received postbacks', 500);
        }
    }

    /**
     * Handle POST request
     *
     * @return void
     */
    private static function handlePostRequest(): void
    {
        // Check authentication
        if (!Session::isAuthenticated()) {
            self::respondError('Authentication required', 401);
        }

        // Validate CSRF token
        if (!Csrf::validate(throwOnFailure: false)) {
            self::respondError('CSRF token validation failed', 403);
        }

        // Parse JSON body
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            self::respondError('No data provided', 400);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::respondError('Invalid JSON payload', 400);
        }

        if (!is_array($data)) {
            self::respondError('Invalid JSON payload', 400);
        }

        // Check if this is a test postback request
        $action = Validator::sanitizeString($data['action'] ?? '', 20);

        if ($action === 'test') {
            self::handleTestPostback($data);
            return;
        }

        // Save postback configuration (default action)
        self::savePostbackConfig($data);
    }

    /**
     * Handle test postback request
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private static function handleTestPostback(array $data): void
    {
        try {
            $cfg = Settings::get();

            if (empty($cfg['postback_url'])) {
                self::respondError('Postback URL is not configured', 400);
            }

            // Sanitize test parameters
            $country = Validator::sanitizeString($data['country'] ?? 'US', 10);
            $country = strtoupper($country);

            // Validate country code
            if (!Validator::isValidCountryCode($country)) {
                $country = 'US'; // Fallback to US
            }

            $trafficType = Validator::sanitizeString($data['traffic_type'] ?? 'WAP', 50);
            $payout = (float) ($data['payout'] ?? $cfg['default_payout'] ?? 0.00);

            // Validate payout range
            if ($payout < 0 || $payout > 10000) {
                self::respondError('Invalid payout value', 400);
            }

            // Send test postback
            $success = PostbackLog::sendPostback($country, $trafficType, $payout, $cfg['postback_url']);

            if ($success) {
                self::respond(['ok' => true, 'message' => 'Test postback sent successfully']);
            }

            self::respondError('Test postback failed', 500);
        } catch (\Throwable $e) {
            error_log('Error in handleTestPostback: ' . $e->getMessage());
            self::respondError($e->getMessage(), 500);
        }
    }

    /**
     * Save postback configuration
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private static function savePostbackConfig(array $data): void
    {
        try {
            $url = Validator::sanitizeString($data['postback_url'] ?? '', 2048);
            $payout = (float) ($data['default_payout'] ?? 0.00);

            // Validate payout range
            if ($payout < 0 || $payout > 10000) {
                self::respondError('Invalid payout value (must be between 0 and 10000)', 400);
            }

            // Update settings (always enabled)
            Settings::updatePostback(true, $url, $payout);

            // Set flash message
            Session::setFlash('success', 'Postback configuration saved successfully.');

            self::respond(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            self::respondError($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('Error in savePostbackConfig: ' . $e->getMessage());
            self::respondError('Failed to save postback configuration', 500);
        }
    }

    /**
     * Standardized JSON response helper
     */
    private static function respond(array $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Standardized error response helper
     */
    private static function respondError(string $message, int $statusCode = 400): never
    {
        self::respond(['ok' => false, 'error' => $message], $statusCode);
    }
}
