<?php
declare(strict_types=1);

/**
 * Optional MySQL scan-history storage.
 *
 * The core scanner works without a database. When config `db.enabled` is
 * true this class saves/reads scan_history rows via PDO.
 *
 * Database settings may come from config/config.php or from the
 * DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables (env wins,
 * which is how most cPanel + PDO setups pass credentials securely).
 */
final class Database
{
    private function __construct()
    {
    }

    /**
     * @return bool whether scan history is enabled AND reachable.
     */
    public static function isEnabled(): bool
    {
        $config = AppConfig::load();
        if (!(bool) $config->get('db.enabled', false)) {
            return false;
        }
        return self::pdo() !== null;
    }

    /**
     * Save one scan result. Never throws; returns false on failure so the
     * recognition flow can continue without a database.
     *
     * @param array<string, mixed> $row ProductResult::toHistoryRow()
     */
    public static function saveScan(array $row): bool
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            self::ensureTable($pdo);
            $sql = 'INSERT INTO scan_history
                        (object_label, product_name, manufacturer, specification,
                         description, confidence, provider, created_at)
                     VALUES
                        (:object_label, :product_name, :manufacturer, :specification,
                         :description, :confidence, :provider, :created_at)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':object_label' => mb_substr((string) ($row['object_label'] ?? ''), 100),
                ':product_name' => mb_substr((string) ($row['product_name'] ?? ''), 200),
                ':manufacturer' => mb_substr((string) ($row['manufacturer'] ?? ''), 100),
                ':specification' => mb_substr((string) ($row['specification'] ?? ''), 500),
                ':description' => mb_substr((string) ($row['description'] ?? ''), 1000),
                ':confidence' => round((float) ($row['confidence'] ?? 0), 2),
                ':provider' => mb_substr((string) ($row['provider'] ?? ''), 30),
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[db] saveScan failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Page of history rows, newest first, with an optional text filter.
     *
     * @param int $page      1-based page number
     * @param int $perPage   rows per page (clamped 1-100)
     * @param string $search
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function history(int $page, int $perPage, string $search): array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return ['rows' => [], 'total' => 0];
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $search = trim($search);

        $whereSql = '';
        $params = [];
        if ($search !== '') {
            $whereSql = 'WHERE object_label LIKE :q1
                        OR product_name LIKE :q2
                        OR manufacturer LIKE :q3';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $params = [':q1' => $like, ':q2' => $like, ':q3' => $like];
        }

        try {
            self::ensureTable($pdo);

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM scan_history ' . $whereSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $sql = 'SELECT id, object_label, product_name, manufacturer, specification,
                           description, confidence, provider, created_at
                    FROM scan_history ' . $whereSql . '
                    ORDER BY id DESC
                    LIMIT :limit OFFSET :offset';
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
        } catch (Throwable $e) {
            error_log('[db] history failed: ' . $e->getMessage());
            return ['rows' => [], 'total' => 0];
        }
    }

    /**
     * Look up a single row. @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return null;
        }
        try {
            self::ensureTable($pdo);
            $stmt = $pdo->prepare(
                'SELECT id, object_label, product_name, manufacturer, specification,
                        description, confidence, provider, created_at
                 FROM scan_history WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            error_log('[db] find failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Delete all rows. */
    public static function clear(): bool
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return false;
        }
        try {
            self::ensureTable($pdo);
            $pdo->exec('DELETE FROM scan_history');
            return true;
        } catch (Throwable $e) {
            error_log('[db] clear failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Create the table if it does not exist yet (idempotent). */
    private static function ensureTable(PDO $pdo): void
    {
        $stmt = $pdo->query('SHOW TABLES LIKE \'scan_history\'');
        $exists = $stmt ? (bool) $stmt->fetchColumn() : false;
        if (!$exists) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS scan_history (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    object_label VARCHAR(100) NOT NULL DEFAULT \'\',
                    product_name VARCHAR(200) NOT NULL DEFAULT \'\',
                    manufacturer VARCHAR(100) NOT NULL DEFAULT \'\',
                    specification VARCHAR(500) NOT NULL DEFAULT \'\',
                    description VARCHAR(1000) NOT NULL DEFAULT \'\',
                    confidence DECIMAL(4,2) NOT NULL DEFAULT 0.00,
                    provider VARCHAR(30) NOT NULL DEFAULT \'\',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }

    /**
     * Build a PDO connection (or null when unavailable/broken).
     * Never throws and never logs credentials.
     */
    private static function pdo(): ?PDO
    {
        $db = AppConfig::load()->get('db', []);
        $host = self::envOr('DB_HOST', (string) $db['host']);
        $name = self::envOr('DB_NAME', (string) $db['name']);
        $user = self::envOr('DB_USER', (string) $db['user']);
        $pass = self::envOr('DB_PASS', (string) $db['pass']);

        if ($host === '' || $name === '') {
            error_log('[db] db host/name missing - scan history disabled.');
            return null;
        }

        try {
            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        } catch (Throwable $e) {
            // Deliberately NOT logging the exception message: PDO can embed
            // the password in "SQLSTATE[...]: ... [username] ..." strings.
            error_log('[db] connection failed (see PHP error log for details).');
            return null;
        }
    }

    /** @param string $name env var name; @param string $fallback */
    private static function envOr(string $name, string $fallback): string
    {
        $value = function_exists('getenv') ? (string) getenv($name) : '';
        if ($value === '' && isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
            $value = (string) $_SERVER[$name];
        }
        return $value !== '' ? $value : $fallback;
    }
}
