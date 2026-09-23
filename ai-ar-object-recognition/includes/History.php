<?php

declare(strict_types=1);

/**
 * Scan history management using MySQL.
 */

namespace App;

use PDO;
use PDOException;

class History
{
    private ?PDO $pdo = null;

    /**
     * Initialize database connection.
     */
    public function __construct()
    {
        if (!AppConfig::get('enable_history', true)) {
            return;
        }

        $dbConfig = AppConfig::get('database', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $dbConfig['host'] ?? '127.0.0.1',
            $dbConfig['port'] ?? '3306',
            $dbConfig['dbname'] ?? 'ai_ar_recognition',
            $dbConfig['charset'] ?? 'utf8mb4',
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $dbConfig['username'] ?? 'root',
                $dbConfig['password'] ?? '',
                $dbConfig['options'] ?? []
            );
        } catch (PDOException $e) {
            error_log('[History] Database connection failed: '
                . $this->sanitiseError($e->getMessage()));
            $this->pdo = null;
        }
    }

    /**
     * Save a scan result to history.
     *
     * @param array<string, mixed> $result
     * @return bool
     */
    public function save(array $result): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO scan_history
                 (object_label, product_name, manufacturer, specification, description, confidence, provider)
                 VALUES (:object_label, :product_name, :manufacturer, :specification, :description, :confidence, :provider)'
            );

            $stmt->execute([
                ':object_label'  => $result['objectLabel']  ?? '',
                ':product_name'  => $result['productName']  ?? '',
                ':manufacturer'  => $result['manufacturer'] ?? '',
                ':specification' => $result['specification'] ?? null,
                ':description'   => $result['description']   ?? null,
                ':confidence'    => round(($result['confidence'] ?? 0) * 100, 1),
                ':provider'      => $result['provider']      ?? 'unknown',
            ]);

            // Clean up old records if exceeding limit
            $this->cleanOldRecords();

            return true;
        } catch (PDOException $e) {
            error_log('[History] Save error: ' . $this->sanitiseError($e->getMessage()));
            return false;
        }
    }

    /**
     * Get paginated scan history.
     *
     * @param int $page Page number (1-based).
     * @param int $perPage Results per page.
     * @return array{records: array<array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function getHistory(int $page = 1, int $perPage = 20): array
    {
        if ($this->pdo === null) {
            return ['records' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }

        try {
            // Get total count
            $countStmt = $this->pdo->query('SELECT COUNT(*) FROM scan_history');
            $total = (int) $countStmt->fetchColumn();

            // Get records
            $offset = ($page - 1) * $perPage;
            $stmt = $this->pdo->prepare(
                'SELECT id, object_label, product_name, manufacturer, specification,
                        description, confidence, provider, created_at
                 FROM scan_history
                 ORDER BY created_at DESC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $records = $stmt->fetchAll();

            return [
                'records' => $records,
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
            ];
        } catch (PDOException $e) {
            error_log('[History] Get error: ' . $this->sanitiseError($e->getMessage()));
            return ['records' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }
    }

    /**
     * Search history by keyword.
     *
     * @param string $keyword
     * @param int $page
     * @param int $perPage
     * @return array{records: array<array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function search(string $keyword, int $page = 1, int $perPage = 20): array
    {
        if ($this->pdo === null) {
            return ['records' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }

        try {
            $like = '%' . $keyword . '%';

            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM scan_history
                 WHERE object_label LIKE :kw
                    OR product_name LIKE :kw
                    OR manufacturer LIKE :kw'
            );
            $countStmt->execute([':kw' => $like]);
            $total = (int) $countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $stmt = $this->pdo->prepare(
                'SELECT id, object_label, product_name, manufacturer, specification,
                        description, confidence, provider, created_at
                 FROM scan_history
                 WHERE object_label LIKE :kw
                    OR product_name LIKE :kw
                    OR manufacturer LIKE :kw
                 ORDER BY created_at DESC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':kw', $like, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $records = $stmt->fetchAll();

            return [
                'records' => $records,
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
            ];
        } catch (PDOException $e) {
            error_log('[History] Search error: ' . $this->sanitiseError($e->getMessage()));
            return ['records' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }
    }

    /**
     * Get a single record by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        if ($this->pdo === null) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, object_label, product_name, manufacturer, specification,
                        description, confidence, provider, created_at
                 FROM scan_history
                 WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            $record = $stmt->fetch();

            return $record ?: null;
        } catch (PDOException $e) {
            error_log('[History] Get by ID error: ' . $this->sanitiseError($e->getMessage()));
            return null;
        }
    }

    /**
     * Clear all history.
     */
    public function clear(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $this->pdo->exec('TRUNCATE TABLE scan_history');
            return true;
        } catch (PDOException $e) {
            error_log('[History] Clear error: ' . $this->sanitiseError($e->getMessage()));
            return false;
        }
    }

    /**
     * Delete a single record.
     */
    public function delete(int $id): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('DELETE FROM scan_history WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('[History] Delete error: ' . $this->sanitiseError($e->getMessage()));
            return false;
        }
    }

    /**
     * Remove records beyond max_history_records limit.
     */
    private function cleanOldRecords(): void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            $maxRecords = (int) AppConfig::get('max_history_records', 500);
            $stmt = $this->pdo->prepare(
                'DELETE FROM scan_history WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM scan_history ORDER BY created_at DESC LIMIT :limit
                    ) AS sub
                )'
            );
            $stmt->bindValue(':limit', $maxRecords, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log('[History] Clean error: ' . $this->sanitiseError($e->getMessage()));
        }
    }

    /**
     * Sanitise error messages.
     */
    private function sanitiseError(string $message): string
    {
        return preg_replace('/[a-zA-Z0-9]{30,}/', '***', $message) ?? $message;
    }
}
