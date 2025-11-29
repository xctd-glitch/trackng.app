<?php
declare(strict_types=1);

// Portable path untuk shared hosting: dirname(__DIR__) = /home/username
require_once '/home/user/trackng.app/srp/src/bootstrap.php';

use SRP\Middleware\Session;
use SRP\Models\EnvConfig;

// Require authentication
Session::requireAuth();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Verify AJAX request
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

// Get action
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'get':
            // Get all environment configuration
            $config = EnvConfig::getAll();
            echo json_encode([
                'ok' => true,
                'config' => $config
            ]);
            break;

        case 'get_groups':
            // Get configuration groups for UI
            $groups = EnvConfig::getConfigGroups();
            echo json_encode([
                'ok' => true,
                'groups' => $groups
            ]);
            break;

        case 'update':
            // Update environment configuration
            $newConfig = $input['config'] ?? [];

            if (empty($newConfig)) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'No configuration provided'
                ]);
                break;
            }

            $success = EnvConfig::update($newConfig);

            if ($success) {
                echo json_encode([
                    'ok' => true,
                    'message' => 'Environment configuration updated successfully'
                ]);
            } else {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Failed to update environment configuration'
                ]);
            }
            break;

        case 'test_db':
            // Test database connection
            $host = $input['host'] ?? '';
            $database = $input['database'] ?? '';
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';

            $result = EnvConfig::testDatabaseConnection($host, $database, $username, $password);
            echo json_encode([
                'ok' => $result['success'],
                'message' => $result['message']
            ]);
            break;

        case 'test_srp':
            // Test SRP API connection
            $apiUrl = $input['api_url'] ?? '';
            $apiKey = $input['api_key'] ?? '';

            $result = EnvConfig::testSrpConnection($apiUrl, $apiKey);
            echo json_encode([
                'ok' => $result['success'],
                'message' => $result['message'],
                'response' => $result['response'] ?? null
            ]);
            break;

        case 'sync_from_env':
            // Sync configuration dari .env ke database (one-time migration)
            $success = EnvConfig::syncFromEnvToDatabase();
            echo json_encode([
                'ok' => $success,
                'message' => $success
                    ? 'Configuration synced from .env to database successfully'
                    : 'Failed to sync configuration from .env to database'
            ]);
            break;

        default:
            echo json_encode([
                'ok' => false,
                'error' => 'Invalid action'
            ]);
            break;
    }

} catch (\Throwable $e) {
    error_log('EnvConfig API error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Internal server error'
    ]);
}
