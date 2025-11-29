<?php
declare(strict_types=1);

namespace SRP\Controllers;

use SRP\Config\Environment;
use SRP\Models\Settings;
use SRP\Models\TrafficLog;
use SRP\Models\Validator;
use SRP\Models\PostbackLog;
use SRP\Utils\CorsHandler;
use SRP\Utils\RequestBody;
use SRP\Utils\HttpClient;

class DecisionController
{
    private const ALLOWED_ORIGINS = [
        'https://trackng.app',
        'https://www.trackng.app',
        'https://api.trackng.app',
        'https://qvtrk.com',
        'https://www.qvtrk.com',
        'https://t.qvtrk.com',
        'https://api.qvtrk.com',
        'https://postback.qvtrk.com',
    ];

    /**
     * Handle decision API request
     *
     * Request Flow:
     * 1. Validate API key
     * 2. Handle CORS preflight
     * 3. Validate HTTP method (POST only)
     * 4. Parse and sanitize input
     * 5. Detect device type
     * 6. Check for VPN
     * 7. Make routing decision (A or B)
     * 8. Log traffic
     * 9. Return JSON response
     *
     * Decision A Conditions (ALL must be true):
     * - System is ON
     * - Not in auto-mute period
     * - Device is WAP (mobile)
     * - Not using VPN
     * - Country is allowed
     * - Valid redirect URL exists
     *
     * Decision B (Fallback):
     * - Any condition fails → send to fallback URL
     */
    public static function handleDecision(): void
    {
        // Step 1: Validate API key
        if (!self::validateApiKey()) {
            CorsHandler::errorResponse('API key not configured', 500, self::ALLOWED_ORIGINS);
        }

        // Step 2: Handle CORS preflight requests
        if (CorsHandler::handle(self::ALLOWED_ORIGINS)) {
            exit; // OPTIONS request handled
        }

        // Step 3: Validate HTTP method
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            CorsHandler::errorResponse('Method not allowed', 405, self::ALLOWED_ORIGINS);
        }

        // Step 4: Parse and sanitize request body
        try {
            $input = self::parseAndSanitizeInput();
        } catch (\RuntimeException $e) {
            CorsHandler::errorResponse($e->getMessage(), 400, self::ALLOWED_ORIGINS);
        }

        // Extract sanitized values
        ['clickId' => $clickId, 'countryCode' => $countryCode, 'userAgent' => $userAgent,
         'ipAddress' => $ipAddress, 'userLp' => $userLp] = $input;

        // Step 5: Detect device type from user agent
        $device = self::detectDevice($userAgent);

        // Step 6: Check if IP is using VPN (with timeout protection)
        $isVpn = self::checkVpn($ipAddress);

        // Step 7: Make routing decision based on all factors
        $decision = self::makeRoutingDecision($countryCode, $device, $isVpn, $clickId, $ipAddress, $userLp);

        // Step 8: Log traffic for analytics
        try {
            TrafficLog::create([
                'ip'       => $ipAddress,
                'ua'       => $userAgent,
                'cid'      => $clickId,
                'cc'       => $countryCode,
                'lp'       => $userLp,
                'decision' => $decision['type'],
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the request
            error_log('Failed to log traffic: ' . $e->getMessage());
        }

        // Step 9: Send JSON response with decision
        CorsHandler::jsonResponse([
            'ok'       => true,
            'decision' => $decision['type'],
            'target'   => $decision['target'],
        ], 200, self::ALLOWED_ORIGINS);
    }

    /**
     * Validate API key from request header
     */
    private static function validateApiKey(): bool
    {
        $apiKey = Environment::get('SRP_API_KEY');
        if ($apiKey === '') {
            return false;
        }

        $providedKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
        if ($providedKey === '') {
            CorsHandler::errorResponse('Missing API key', 401, self::ALLOWED_ORIGINS);
        }

        if (!hash_equals($apiKey, $providedKey)) {
            CorsHandler::errorResponse('Invalid API key', 401, self::ALLOWED_ORIGINS);
        }

        return true;
    }

    /**
     * Parse and sanitize request input
     */
    private static function parseAndSanitizeInput(): array
    {
        try {
            $input = RequestBody::parseJson(true, 10240);
        } catch (\RuntimeException $e) {
            // Handle empty body - set empty input
            $input = [];
        }

        // Sanitize and normalize inputs
        $clickId = Validator::sanitizeString($input['click_id'] ?? '', 100);
        $countryCode = Validator::sanitizeString($input['country_code'] ?? 'XX', 10);
        $userAgent = Validator::sanitizeString($input['user_agent'] ?? '', 500);
        $ipAddress = Validator::sanitizeString($input['ip_address'] ?? '', 45);
        $userLp = Validator::sanitizeString($input['user_lp'] ?? '', 100);

        // Validate required fields (ip_address is critical for logging)
        if ($ipAddress === '') {
            throw new \RuntimeException('Missing required field: ip_address');
        }

        // Clean special characters
        $clickId = preg_replace('/[^a-zA-Z0-9_-]/', '', $clickId);
        $countryCode = preg_replace('/[^a-zA-Z]/', '', $countryCode);
        $userLp = preg_replace('/[^a-zA-Z0-9_-]/', '', $userLp);

        // Normalize country code to uppercase (standard format)
        $countryCode = strtoupper($countryCode);

        // Keep clickId and userLp in original case for better readability
        // They will be lowercased only when needed for URL building

        // Validate IP address format
        if (!Validator::isValidIp($ipAddress)) {
            throw new \RuntimeException('Invalid IP address format');
        }

        return [
            'clickId' => $clickId,
            'countryCode' => $countryCode,
            'userAgent' => $userAgent,
            'ipAddress' => $ipAddress,
            'userLp' => $userLp,
        ];
    }

    /**
     * Build fallback URL with query parameters
     */
    private static function buildFallbackUrl(
        string $clickId,
        string $countryCode,
        string $device,
        string $ipAddress,
        string $userLp
    ): string {
        return '/_meetups/?' . http_build_query([
            'click_id'     => strtolower($clickId),
            'country_code' => strtolower($countryCode),
            'user_agent'   => strtolower($device),
            'ip_address'   => $ipAddress,
            'user_lp'      => strtolower($userLp),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Make routing decision based on settings and input
     */
    private static function makeRoutingDecision(
        string $countryCode,
        string $device,
        bool $isVpn,
        string $clickId,
        string $ipAddress,
        string $userLp
    ): array {
        // Check and reset stats if needed (do this FIRST to ensure accurate stats)
        Settings::checkAndResetStatsIfNeeded();

        $config = Settings::get();
        $decision = 'B';

        // Validate country code before using it
        $countryAllowed = true;
        if ($countryCode !== '' && $countryCode !== 'XX') {
            if (!Validator::isValidCountryCode($countryCode)) {
                $countryCode = 'XX';
            } else {
                $countryAllowed = Validator::isCountryAllowed($countryCode);
            }
        }

        // Build fallback URL after country code validation
        $fallback = self::buildFallbackUrl($clickId, $countryCode, $device, $ipAddress, $userLp);

        // Check auto-mute status
        $isMuted = self::isSystemMuted($config);

        // Get valid redirect URLs
        $validUrls = self::getValidRedirectUrls($config);

        // Decision logic: Send to Decision A if all conditions are met
        if (
            !empty($config['system_on']) &&
            !$isMuted &&
            $device === 'WAP' &&
            !$isVpn &&
            $countryAllowed &&
            count($validUrls) > 0
        ) {
            $decision = 'A';
            // Use cryptographically secure random for URL selection
            $randomIndex = random_int(0, count($validUrls) - 1);
            $target = rtrim((string)$validUrls[$randomIndex], '/');

            // Update stats and trigger postback
            self::handleDecisionA($config, $countryCode, $device);

            return ['type' => $decision, 'target' => $target];
        }

        // Decision B: Increment counter only if system is ON and not muted
        if (!empty($config['system_on']) && !$isMuted) {
            Settings::incrementDecisionB();
        }

        return ['type' => $decision, 'target' => $fallback];
    }

    /**
     * Check if system is currently muted (auto-pause)
     *
     * Auto-mute pattern: 5-minute cycle
     * - Minute 0-1 (2 minutes): System ACTIVE - Sends traffic to Decision A
     * - Minute 2-4 (3 minutes): System MUTED - All traffic goes to Decision B
     *
     * Example timeline:
     * 00:00-00:01 → Active (2 min)
     * 00:02-00:04 → Muted (3 min)
     * 00:05-00:06 → Active (2 min)
     * 00:07-00:09 → Muted (3 min)
     * And so on...
     *
     * @param array $config System configuration
     * @return bool True if system is currently in muted period
     */
    private static function isSystemMuted(array $config): bool
    {
        // If system is OFF, no muting applies
        if (empty($config['system_on'])) {
            return false;
        }

        // Get current minute and calculate position in 5-minute cycle (UTC normalized)
        $currentMinute = (int)(gmdate('U') / 60);
        $cyclePosition = $currentMinute % 5;

        // Mute during positions 2, 3, 4 (60% of the time)
        // Active during positions 0, 1 (40% of the time)
        return $cyclePosition >= 2;
    }

    /**
     * Get list of valid redirect URLs from config
     */
    private static function getValidRedirectUrls(array $config): array
    {
        $validUrls = [];

        if (isset($config['redirect_url']) && is_array($config['redirect_url'])) {
            foreach ($config['redirect_url'] as $url) {
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $validUrls[] = $url;
                }
            }
        }

        return $validUrls;
    }

    /**
     * Handle Decision A: increment stats and trigger postback
     */
    private static function handleDecisionA(array $config, string $countryCode, string $device): void
    {
        Settings::incrementDecisionA();

        // Trigger postback if URL configured (always enabled)
        if (!empty($config['postback_url'])) {
            $payout = (float)($config['default_payout'] ?? 0.00);
            PostbackLog::sendPostback($countryCode, $device, $payout, $config['postback_url']);
        }
    }

    /**
     * Detect device type from User Agent string
     *
     * Returns device classification:
     * - WAP: Mobile devices (phones)
     * - TABLET: Tablet devices
     * - WEB: Desktop/laptop browsers
     * - BOT: Search engine bots and crawlers
     *
     * @param string $ua User agent string
     * @return string Device type
     */
    private static function detectDevice(string $ua): string
    {
        // Handle empty user agent
        if ($ua === '') {
            return 'WEB';
        }

        $uaLower = strtolower($ua);

        // Check for explicit device type from client
        if ($uaLower === 'wap' || $uaLower === 'mobile') {
            return 'WAP';
        } elseif ($uaLower === 'web' || $uaLower === 'desktop') {
            return 'WEB';
        } elseif ($uaLower === 'tablet') {
            return 'TABLET';
        }

        // Check for bots first (before mobile check to avoid false positives)
        if (preg_match('~bot|crawl|spider|facebook|whatsapp|telegram~i', $ua)) {
            return 'BOT';
        }

        // Check for tablet devices
        if (preg_match('/tablet|ipad/i', $ua)) {
            return 'TABLET';
        }

        // Check for mobile devices
        if (preg_match('/mobile|android|iphone|ipod|blackberry|iemobile|opera mini|windows phone/i', $ua)) {
            return 'WAP';
        }

        // Default to desktop
        return 'WEB';
    }

    /**
     * Check if IP address is a VPN using external service
     *
     * Uses blackbox.ipinfo.app VPN detection service
     * Response: 'Y' = VPN detected, 'N' = Not a VPN
     *
     * Note: This check uses a 2-second timeout with circuit breaker.
     * If the service is unavailable or times out, it fails close (returns true)
     * treating the traffic as VPN for security (better safe than sorry).
     *
     * @param string $ipAddress IP address to check
     * @return bool True if VPN detected or check failed, False if confirmed not VPN
     */
    private static function checkVpn(string $ipAddress): bool
    {
        // Skip check for invalid IPs
        if (!Validator::isValidIp($ipAddress)) {
            return false;
        }

        // Skip check for private/local IPs
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $vpnCheckUrl = "https://blackbox.ipinfo.app/lookup/" . urlencode($ipAddress);

        // Use HttpClient with circuit breaker and 2-second timeout
        // Increased from 1 second to improve reliability
        $response = HttpClient::getSimple($vpnCheckUrl, [], 2);

        if ($response['success'] && $response['body'] !== '') {
            $result = trim($response['body']);
            return $result === 'Y';
        }

        // Fail close: If VPN check service is down or times out,
        // treat as VPN (return true) for security
        // Log the failure for monitoring (hash IP for privacy)
        $ipHash = hash('sha256', $ipAddress . ($_ENV['IP_HASH_SALT'] ?? 'default_salt'));
        error_log('VPN check service failed for IP hash: ' . substr($ipHash, 0, 16));
        return true;
    }
}
