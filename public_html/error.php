<?php

/**
 * Custom Error Page Handler
 *
 * Handles all HTTP errors (400, 401, 403, 404, 500, etc.)
 * Called by Apache ErrorDocument directives
 */

declare(strict_types=1);

// Try to load bootstrap, but don't fail if it doesn't work
$bootstrapLoaded = false;
$bootstrapPaths = [
    __DIR__ . '/../srp/src/bootstrap.php',
    dirname(__DIR__) . '/srp/src/bootstrap.php',
];

foreach ($bootstrapPaths as $path) {
    if (file_exists($path)) {
        try {
            require_once $path;
            $bootstrapLoaded = true;
            break;
        } catch (Throwable $e) {
            // Bootstrap failed, continue without it
            error_log('Error page bootstrap failed: ' . $e->getMessage());
        }
    }
}

// Get error code from various sources
$code = 500; // Default

// From Apache ErrorDocument
if (isset($_SERVER['REDIRECT_STATUS'])) {
    $code = (int) $_SERVER['REDIRECT_STATUS'];
}

// From query parameter (manual redirect)
if (isset($_GET['code'])) {
    $code = (int) $_GET['code'];
}

// Validate code
if ($code < 400 || $code > 599) {
    $code = 500;
}

// Set HTTP response code
http_response_code($code);

// Get custom message if provided
$customMessage = '';
if (isset($_GET['message'])) {
    $customMessage = substr(strip_tags($_GET['message']), 0, 200);
}

// Get the original requested URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$originalUri = $_SERVER['REDIRECT_URL'] ?? $requestUri;

// Check if this is an AJAX/API request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$acceptsJson = isset($_SERVER['HTTP_ACCEPT']) &&
              strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

// Return JSON for API requests
if ($isAjax || $acceptsJson) {
    header('Content-Type: application/json; charset=utf-8');

    $errorMessages = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    echo json_encode([
        'ok' => false,
        'error' => $customMessage ?: ($errorMessages[$code] ?? 'Unknown Error'),
        'code' => $code,
        'path' => $originalUri,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Determine back URL
$backUrl = '/';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!empty($referer)) {
    $refererHost = parse_url($referer, PHP_URL_HOST);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    // Only use referer if it's from the same domain
    if ($refererHost === $currentHost) {
        $backUrl = $referer;
    }
}

// Check environment
$isProduction = true;
if ($bootstrapLoaded && class_exists('SRP\Config\Environment')) {
    $isProduction = (\SRP\Config\Environment::get('SRP_ENV', 'production') === 'production');
}

// Prepare view variables
$title = 'Error';
$message = $customMessage;
$details = '';
$showBack = true;

// Show details in development mode
if (!$isProduction && !empty($originalUri)) {
    $details = "Requested: {$originalUri}";
}

// Try to use the custom view
$viewPath = __DIR__ . '/../srp/src/Views/error.view.php';
if (!file_exists($viewPath)) {
    $viewPath = dirname(__DIR__) . '/srp/src/Views/error.view.php';
}

if (file_exists($viewPath)) {
    // Get CSP nonce if available
    $nonce = '';
    if ($bootstrapLoaded && class_exists('SRP\Middleware\Session')) {
        try {
            $nonce = \SRP\Middleware\Session::getCspNonce() ?? '';
        } catch (Throwable $e) {
            // Ignore
        }
    }

    include $viewPath;
    exit;
}

// Fallback: Simple HTML error page if view not found
$errorConfig = [
    400 => ['title' => 'Bad Request', 'color' => '#e67e22'],
    401 => ['title' => 'Unauthorized', 'color' => '#9b59b6'],
    403 => ['title' => 'Forbidden', 'color' => '#e74c3c'],
    404 => ['title' => 'Page Not Found', 'color' => '#3498db'],
    405 => ['title' => 'Method Not Allowed', 'color' => '#e67e22'],
    500 => ['title' => 'Internal Server Error', 'color' => '#e74c3c'],
    502 => ['title' => 'Bad Gateway', 'color' => '#9b59b6'],
    503 => ['title' => 'Service Unavailable', 'color' => '#f39c12'],
    504 => ['title' => 'Gateway Timeout', 'color' => '#e67e22'],
];

$config = $errorConfig[$code] ?? ['title' => 'Error', 'color' => '#e74c3c'];
$e = fn(string $str): string => htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Error <?= $code ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #fff;
        }
        .container {
            text-align: center;
            max-width: 500px;
        }
        .code {
            font-size: 100px;
            font-weight: 800;
            color: <?= $config['color'] ?>;
            line-height: 1;
        }
        h1 {
            font-size: 28px;
            margin: 20px 0;
        }
        p {
            color: rgba(255,255,255,0.7);
            margin-bottom: 30px;
        }
        a {
            display: inline-block;
            padding: 12px 24px;
            background: <?= $config['color'] ?>;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            margin: 5px;
        }
        a:hover { filter: brightness(1.1); }
    </style>
</head>
<body>
    <div class="container">
        <div class="code"><?= $code ?></div>
        <h1><?= $e($config['title']) ?></h1>
        <p><?= $e($customMessage ?: 'Something went wrong. Please try again.') ?></p>
        <a href="/">Go Home</a>
        <a href="javascript:history.back()">Go Back</a>
    </div>
</body>
</html>
