<?php

declare(strict_types=1);

namespace SRP\Config;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Database Connection Manager (PDO-based, Production-Ready)
 *
 * Fitur keamanan:
 * - Prepared statements wajib untuk semua query
 * - PDO::ATTR_EMULATE_PREPARES = false (native prepared statements)
 * - PDO::MYSQL_ATTR_MULTI_STATEMENTS = false (tolak multi-statement)
 * - Connection pooling dengan singleton pattern
 * - Query performance tracking
 */
class Database
{
    private static ?PDO $connection = null;
    private static bool $bootstrapped = false;
    private static int $queryCount = 0;
    private static float $totalQueryTime = 0.0;

    /**
     * Get PDO connection dengan konfigurasi aman
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            // Return existing connection without ping
            // PDO will automatically reconnect if needed
            return self::$connection;
        }

        // Read konfigurasi dari environment
        $host = $_ENV['DB_HOST'] ?? $_ENV['SRP_DB_HOST'] ?? '127.0.0.1';
        $user = $_ENV['DB_USER'] ?? $_ENV['SRP_DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? $_ENV['SRP_DB_PASS'] ?? '';
        $name = $_ENV['DB_NAME'] ?? $_ENV['SRP_DB_NAME'] ?? 'srp';
        $port = (int)($_ENV['DB_PORT'] ?? $_ENV['SRP_DB_PORT'] ?? 3306);
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$connection = new PDO($dsn, $user, $pass, [
                // Error mode: exceptions
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                // Default fetch mode: associative array
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Gunakan native prepared statements (KEAMANAN KRITIS)
                PDO::ATTR_EMULATE_PREPARES => false,

                // Tolak multi-statement queries (KEAMANAN KRITIS)
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,

                // Persistent connection untuk performance
                PDO::ATTR_PERSISTENT => false,

                // Timeout
                PDO::ATTR_TIMEOUT => 5,

                // Convert numeric strings to numbers
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);

            // Set session variables untuk optimasi
            self::$connection->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
            self::$connection->exec("SET SESSION wait_timeout=300");

            // Initialize schema jika belum
            if (!self::$bootstrapped) {
                self::initializeSchema();
                self::$bootstrapped = true;
            }
        } catch (PDOException $e) {
            // Log error untuk debugging (JANGAN expose ke user)
            error_log('Database connection failed: ' . $e->getMessage());
            error_log("Connection params: host={$host}, user={$user}, db={$name}");

            // Jika ini AJAX request, return JSON error
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                      strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) &&
                          strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

            http_response_code(500);

            if ($isAjax || $acceptsJson) {
                // Return JSON error untuk AJAX requests
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'error' => 'Database connection error. Please contact administrator.',
                    'code' => 'DB_CONNECTION_ERROR'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                // Untuk non-AJAX, tampilkan error page sederhana
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html>
<html>
<head>
    <title>System Error</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
        .error-box {
            background: #f8d7da;
            color: #721c24;
            padding: 20px;
            border-radius: 4px;
            max-width: 500px;
            margin: 0 auto;
            border: 1px solid #f5c6cb;
        }
        h1 { color: #721c24; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>System Error</h1>
        <p>We are experiencing technical difficulties. Please try again later.</p>
        <p>Error Code: DB_CONNECTION_ERROR</p>
    </div>
</body>
</html>';
                exit;
            }
        }

        return self::$connection;
    }

    /**
     * Initialize database schema dengan prepared statements
     *
     * @return void
     * @throws PDOException
     */
    private static function initializeSchema(): void
    {
        $pdo = self::$connection;

        // Table: settings
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  redirect_url JSON DEFAULT NULL,
  system_on TINYINT(1) NOT NULL DEFAULT 0,
  country_filter_mode ENUM('all','whitelist','blacklist') NOT NULL DEFAULT 'all',
  country_filter_list TEXT NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  total_decision_a BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_decision_b BIGINT UNSIGNED NOT NULL DEFAULT 0,
  stats_reset_at INT UNSIGNED NOT NULL DEFAULT 0,
  postback_enabled TINYINT(1) NOT NULL DEFAULT 0,
  postback_url VARCHAR(2048) NOT NULL DEFAULT '',
  default_payout DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        // Migrasi: tambahkan kolom jika belum ada (ALTER TABLE aman tanpa binding)
        $columns = ['total_decision_a', 'total_decision_b', 'stats_reset_at',
                    'postback_enabled', 'postback_url', 'default_payout'];

        foreach ($columns as $col) {
            try {
                $pdo->exec("ALTER TABLE settings ADD COLUMN IF NOT EXISTS {$col} " .
                          self::getColumnDefinition($col));
            } catch (PDOException $e) {
                // Kolom sudah ada, skip
            }
        }

        // Table: logs
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  ua VARCHAR(500) NOT NULL,
  click_id VARCHAR(100) NULL,
  country_code VARCHAR(10) NULL,
  user_lp VARCHAR(100) NULL,
  decision ENUM('A','B') NOT NULL,
  INDEX idx_logs_ts (ts),
  INDEX idx_logs_decision (decision),
  INDEX idx_logs_country (country_code),
  INDEX idx_logs_ts_decision (ts, decision),
  INDEX idx_logs_ts_country (ts, country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        // Table: postback_logs
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS postback_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts INT UNSIGNED NOT NULL,
  country_code VARCHAR(10) NOT NULL,
  traffic_type VARCHAR(50) NOT NULL,
  payout DECIMAL(10,2) NOT NULL,
  postback_url TEXT NOT NULL,
  response_code INT NULL,
  response_body TEXT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_postback_ts (ts),
  INDEX idx_postback_success (success),
  INDEX idx_postback_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        // Table: postback_received
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS postback_received (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts INT UNSIGNED NOT NULL,
  status VARCHAR(50) NOT NULL,
  country_code VARCHAR(10) NOT NULL,
  traffic_type VARCHAR(50) NOT NULL,
  payout DECIMAL(10,2) NOT NULL,
  click_id VARCHAR(100) NULL,
  ip_address VARCHAR(45) NOT NULL,
  query_string TEXT NOT NULL,
  INDEX idx_postback_received_ts (ts),
  INDEX idx_postback_received_country (country_code),
  INDEX idx_postback_received_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        // Table: env_config
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS env_config (
  config_key VARCHAR(100) PRIMARY KEY,
  config_value TEXT NULL,
  config_type VARCHAR(50) NOT NULL DEFAULT 'string',
  is_editable TINYINT(1) NOT NULL DEFAULT 1,
  description TEXT NULL,
  created_at INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  INDEX idx_env_config_type (config_type),
  INDEX idx_env_config_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        // Migrasi: tambahkan kolom postback_received jika belum ada
        try {
            $pdo->exec("ALTER TABLE postback_received ADD COLUMN IF NOT EXISTS traffic_type VARCHAR(50) NOT NULL DEFAULT 'Unknown' AFTER country_code");
        } catch (PDOException $e) {
            // Kolom sudah ada, skip
        }

        // Table: api_rate_limit (untuk External API rate limiting)
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS api_rate_limit (
  ip_address VARCHAR(45) NOT NULL,
  requests INT UNSIGNED NOT NULL DEFAULT 1,
  window_start INT UNSIGNED NOT NULL,
  PRIMARY KEY (ip_address),
  INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        // Insert default settings (GUNAKAN PREPARED STATEMENT)
        $stmt = $pdo->prepare(
            "INSERT INTO settings
            (id, redirect_url, system_on, country_filter_mode, country_filter_list, updated_at)
             VALUES (1, JSON_ARRAY(), 0, 'all', '', UNIX_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id = id"
        );
        $stmt->execute();
    }

    /**
     * Get column definition untuk ALTER TABLE
     *
     * @param string $columnName
     * @return string
     */
    private static function getColumnDefinition(string $columnName): string
    {
        $definitions = [
            'total_decision_a' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'total_decision_b' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'stats_reset_at' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'postback_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'postback_url' => 'VARCHAR(2048) NOT NULL DEFAULT \'\'',
            'default_payout' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        ];

        return $definitions[$columnName] ?? 'TEXT';
    }

    /**
     * Close database connection
     *
     * @return void
     */
    public static function close(): void
    {
        self::$connection = null;
    }

    /**
     * Track query execution untuk performance monitoring
     *
     * @param float $duration
     * @return void
     */
    public static function trackQuery(float $duration): void
    {
        self::$queryCount++;
        self::$totalQueryTime += $duration;
    }

    /**
     * Get query statistics
     *
     * @return array{count: int, total_time: float, avg_time: float}
     */
    public static function getQueryStats(): array
    {
        return [
            'count' => self::$queryCount,
            'total_time' => round(self::$totalQueryTime, 4),
            'avg_time' => self::$queryCount > 0
                ? round(self::$totalQueryTime / self::$queryCount, 4)
                : 0.0
        ];
    }

    /**
     * Reset query statistics
     *
     * @return void
     */
    public static function resetQueryStats(): void
    {
        self::$queryCount = 0;
        self::$totalQueryTime = 0.0;
    }

    /**
     * Begin transaction
     *
     * @return bool
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     *
     * @return bool
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Rollback transaction
     *
     * @return bool
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Execute prepared statement dengan parameter binding
     *
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return PDOStatement
     * @throws PDOException
     */
    public static function execute(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);

        $stmt = self::getConnection()->prepare($sql);

        // Bind parameters dengan type yang tepat untuk LIMIT/OFFSET
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                // Determine parameter type
                $type = PDO::PARAM_STR;
                if (is_int($value)) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $type = PDO::PARAM_NULL;
                }

                // Bind parameter (1-indexed for positional, or :key for named)
                if (is_int($key)) {
                    $stmt->bindValue($key + 1, $value, $type);
                } else {
                    $stmt->bindValue($key, $value, $type);
                }
            }
            $stmt->execute();
        } else {
            $stmt->execute($params);
        }

        self::trackQuery(microtime(true) - $start);

        return $stmt;
    }

    /**
     * Fetch single row
     *
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|false
     */
    public static function fetchRow(string $sql, array $params = []): array|false
    {
        $stmt = self::execute($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Fetch all rows
     *
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::execute($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Get last insert ID
     *
     * @return string
     */
    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }
}
