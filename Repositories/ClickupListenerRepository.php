<?php

namespace Leantime\Plugins\ClickupListener\Repositories;

use Illuminate\Support\Facades\Log;
use Leantime\Core\Db\Db;

class ClickupListenerRepository
{

    private Db $db;
    private static bool $schemaEnsured = false;
    private static ?array $ticketColumns = null;


    public function __construct()
    {
        // Get DB Instance
        $this->db = app(Db::class);
        if (!self::$schemaEnsured) {
            $this->ensureSchema();
            self::$schemaEnsured = true;
        }
    }

    public function setup(): void
    {
        $pdo = $this->db->pdo();
        // Create tables if not exists.
        $sql = "CREATE TABLE IF NOT EXISTS `clickup_config` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `webhook_id` varchar(255) DEFAULT NULL,
            `hook_secret` varchar(255) NOT NULL DEFAULT '',
            `project_id` int(11) NOT NULL,
            `task_tag` varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_clickup_webhook_id` (`webhook_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();

        $sql = "CREATE TABLE IF NOT EXISTS `clickup_task_map` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `config_id` int(11) NOT NULL,
            `clickup_task_id` varchar(255) NOT NULL,
            `parent_clickup_task_id` varchar(255) DEFAULT NULL,
            `ticket_id` int(11) NOT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_task` (`config_id`, `clickup_task_id`),
            KEY `idx_ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();

        $this->ensureConfigColumns();
        $this->ensureTaskMapColumns();
        $this->ensureCommentMapTable();
    }

    public function teardown(): void
    {
        $pdo = $this->db->pdo();
        // Drop tables.
        $sql = "DROP TABLE IF EXISTS `clickup_config`;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();

        $sql = "DROP TABLE IF EXISTS `clickup_task_map`;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();

        $sql = "DROP TABLE IF EXISTS `clickup_comment_map`;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();
    }

    /**
     * Return all saved configurations.
     *
     * @return array
     */
    public function getAll(): array
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, webhook_id, hook_secret, project_id, task_tag FROM clickup_config ORDER BY id DESC";
        $st = $pdo->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $rows ?: [];
    }

    /**
     * Find configuration by webhook id
     *
     * @param string $webhookId
     * @return array|null
     */
    public function getByWebhookId(string $webhookId): ?array
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, webhook_id, hook_secret, project_id, task_tag FROM clickup_config WHERE webhook_id = :webhook_id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':webhook_id' => $webhookId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    /**
     * Find configuration by id
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, webhook_id, hook_secret, project_id, task_tag FROM clickup_config WHERE id = :id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    /**
     * Find configuration by hook_secret
     *
     * @param string $secret
     * @return array|null
     */
    public function getByHookSecret(string $secret): ?array
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, webhook_id, hook_secret, project_id, task_tag FROM clickup_config WHERE hook_secret = :secret LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':secret' => $secret]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    /**
     * Delete configuration by id
     *
     * @param int $id
     * @return bool
     */
    public function deleteById(int $id): bool
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "DELETE FROM clickup_config WHERE id = :id";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([':id' => $id]);
        $st->closeCursor();

        return (bool)$ok;
    }

    /**
     * Update target project and tag for a configuration by id
     */
    public function updateProjectAndTag(int $id, int $projectId, string $taskTag): bool
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "UPDATE clickup_config SET project_id = :project_id, task_tag = :task_tag WHERE id = :id";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([':project_id' => $projectId, ':task_tag' => $taskTag, ':id' => $id]);
        $st->closeCursor();

        return (bool)$ok;
    }

    /**
     * Insert or replace a configuration.
     * If a config with the same webhook_id exists it will be removed first and a new row will be inserted.
     *
     * @param array $data
     * @return int|null inserted id or null on failure
     */
    public function save(array $data): ?int
    {
        $pdo = $this->db->pdo();
        // Normalize values
        $webhookId = $data['webhook_id'] ?? null;
        $hookSecret = (string)($data['hook_secret'] ?? '');
        $projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
        $taskTag = (string)($data['task_tag'] ?? '');

        try {
            // Begin transaction for atomic replace
            $pdo->beginTransaction();

            // If exists delete existing to "replace" with a fresh row
            if ($webhookId !== null && $webhookId !== '') {
                $existing = $this->getByWebhookId((string)$webhookId);
                if ($existing !== null) {
                    $del = $pdo->prepare("DELETE FROM clickup_config WHERE webhook_id = :webhook_id");
                    $del->execute([':webhook_id' => $webhookId]);
                    $del->closeCursor();
                }
            }

            // Insert new row
            // @phpstan-ignore-next-line - table may not be visible to static analyzer
            $sql = "INSERT INTO clickup_config (webhook_id, hook_secret, project_id, task_tag)
                    VALUES (:webhook_id, :hook_secret, :project_id, :task_tag)";

            $st = $pdo->prepare($sql);
            $ok = $st->execute([
                ':webhook_id' => $webhookId,
                ':hook_secret' => $hookSecret,
                ':project_id' => $projectId,
                ':task_tag' => $taskTag,
            ]);

            if (! $ok) {
                $st->closeCursor();
                $pdo->rollBack();
                return null;
            }

            $lastId = (int)$pdo->lastInsertId();
            $st->closeCursor();

            $pdo->commit();

            return $lastId > 0 ? $lastId : null;
        } catch (\Throwable $e) {
            Log::error($e);
            try { $pdo->rollBack(); } catch (\Throwable $e1) {
                Log::error($e1);
            }
            return null;
        }
    }

    public function projectExists(int $projectId): bool
    {
        $pdo = $this->db->pdo();
        $sql = "SELECT id FROM zp_projects WHERE id = :id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $projectId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $row !== false;
    }

    public function findTicketIdByHeadlineAndTags(int $projectId, string $headline, array $tags): ?int
    {
        $headline = trim($headline);
        if ($projectId <= 0 || $headline === '') {
            return null;
        }

        $tags = array_values(array_filter(array_map('trim', $tags), static function ($tag) {
            return $tag !== '';
        }));
        if (empty($tags)) {
            return null;
        }

        $pdo = $this->db->pdo();
        $conditions = [];
        $params = [
            ':project_id' => $projectId,
            ':headline' => $headline,
        ];

        foreach ($tags as $index => $tag) {
            $key = ':tag'.$index;
            $conditions[] = "FIND_IN_SET($key, zp_tickets.tags)";
            $params[$key] = $tag;
        }

        $sql = "SELECT id FROM zp_tickets
                WHERE projectId = :project_id
                  AND headline = :headline
                  AND status <> -1
                  AND ".implode(' AND ', $conditions)."
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        if (!$row || empty($row['id'])) {
            return null;
        }

        return (int)$row['id'];
    }

    public function getTaskMapByClickupId(int $configId, string $clickupTaskId): ?array
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, config_id, clickup_task_id, parent_clickup_task_id, ticket_id FROM clickup_task_map WHERE config_id = :config_id AND clickup_task_id = :clickup_task_id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':config_id' => $configId, ':clickup_task_id' => $clickupTaskId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    public function getTaskMapsByParentClickupId(int $configId, string $parentClickupTaskId): array
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, config_id, clickup_task_id, parent_clickup_task_id, ticket_id FROM clickup_task_map WHERE config_id = :config_id AND parent_clickup_task_id = :parent_clickup_task_id";
        $st = $pdo->prepare($sql);
        $st->execute([':config_id' => $configId, ':parent_clickup_task_id' => $parentClickupTaskId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $rows ?: [];
    }

    public function saveTaskMap(int $configId, string $clickupTaskId, int $ticketId, ?string $parentClickupTaskId = null): bool
    {
        $pdo = $this->db->pdo();
        $now = date('Y-m-d H:i:s');
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "INSERT INTO clickup_task_map (config_id, clickup_task_id, parent_clickup_task_id, ticket_id, created_at, updated_at)
                VALUES (:config_id, :clickup_task_id, :parent_clickup_task_id, :ticket_id, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE ticket_id = VALUES(ticket_id), parent_clickup_task_id = VALUES(parent_clickup_task_id), updated_at = VALUES(updated_at)";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([
            ':config_id' => $configId,
            ':clickup_task_id' => $clickupTaskId,
            ':parent_clickup_task_id' => $parentClickupTaskId,
            ':ticket_id' => $ticketId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $st->closeCursor();

        return (bool)$ok;
    }

    public function updateTaskMapParent(int $configId, string $clickupTaskId, ?string $parentClickupTaskId): bool
    {
        $pdo = $this->db->pdo();
        $now = date('Y-m-d H:i:s');
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "UPDATE clickup_task_map SET parent_clickup_task_id = :parent_clickup_task_id, updated_at = :updated_at WHERE config_id = :config_id AND clickup_task_id = :clickup_task_id LIMIT 1";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([
            ':parent_clickup_task_id' => $parentClickupTaskId,
            ':updated_at' => $now,
            ':config_id' => $configId,
            ':clickup_task_id' => $clickupTaskId,
        ]);
        $st->closeCursor();

        return (bool)$ok;
    }

    public function getCommentMapByClickupId(int $configId, string $clickupCommentId): ?array
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "SELECT id, config_id, clickup_comment_id, clickup_task_id, ticket_id, comment_id FROM clickup_comment_map WHERE config_id = :config_id AND clickup_comment_id = :clickup_comment_id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':config_id' => $configId, ':clickup_comment_id' => $clickupCommentId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();

        return $row ?: null;
    }

    public function saveCommentMap(int $configId, string $clickupCommentId, string $clickupTaskId, int $ticketId, int $commentId): bool
    {
        $pdo = $this->db->pdo();
        $now = date('Y-m-d H:i:s');
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "INSERT INTO clickup_comment_map (config_id, clickup_comment_id, clickup_task_id, ticket_id, comment_id, created_at)
                VALUES (:config_id, :clickup_comment_id, :clickup_task_id, :ticket_id, :comment_id, :created_at)
                ON DUPLICATE KEY UPDATE ticket_id = VALUES(ticket_id), comment_id = VALUES(comment_id)";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([
            ':config_id' => $configId,
            ':clickup_comment_id' => $clickupCommentId,
            ':clickup_task_id' => $clickupTaskId,
            ':ticket_id' => $ticketId,
            ':comment_id' => $commentId,
            ':created_at' => $now,
        ]);
        $st->closeCursor();

        return (bool)$ok;
    }

    public function getTicketColumns(): array
    {
        if (self::$ticketColumns !== null) {
            return self::$ticketColumns;
        }

        if (!$this->tableExists('zp_tickets')) {
            self::$ticketColumns = [];
            return self::$ticketColumns;
        }

        $pdo = $this->db->pdo();
        $columns = [];
        $st = $pdo->prepare('SHOW COLUMNS FROM `zp_tickets`');
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        foreach ($rows as $row) {
            if (!empty($row['Field'])) {
                $columns[$row['Field']] = true;
            }
        }

        self::$ticketColumns = $columns;
        return self::$ticketColumns;
    }

    public function deleteCommentMap(int $configId, string $clickupCommentId): bool
    {
        $pdo = $this->db->pdo();
        // @phpstan-ignore-next-line - table may not be visible to static analyzer
        $sql = "DELETE FROM clickup_comment_map WHERE config_id = :config_id AND clickup_comment_id = :clickup_comment_id";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([':config_id' => $configId, ':clickup_comment_id' => $clickupCommentId]);
        $st->closeCursor();

        return (bool)$ok;
    }

    private function ensureSchema(): void
    {
        if ($this->tableExists('clickup_config')) {
            $this->ensureConfigColumns();
        }
        if ($this->tableExists('clickup_task_map')) {
            $this->ensureTaskMapColumns();
        }
        $this->ensureCommentMapTable();
    }

    private function tableExists(string $table): bool
    {
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SHOW TABLES LIKE :table');
        $st->execute([':table' => $table]);
        $exists = $st->fetch(\PDO::FETCH_NUM) !== false;
        $st->closeCursor();

        return $exists;
    }

    private function ensureConfigColumns(): void
    {
        if (!$this->tableExists('clickup_config')) {
            return;
        }
        $pdo = $this->db->pdo();
        $columns = [];
        $st = $pdo->prepare("SHOW COLUMNS FROM `clickup_config`");
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        foreach ($rows as $row) {
            if (!empty($row['Field'])) {
                $columns[$row['Field']] = true;
            }
        }

        $toAdd = [
            'webhook_id' => "ALTER TABLE `clickup_config` ADD COLUMN `webhook_id` varchar(255) DEFAULT NULL",
            'hook_secret' => "ALTER TABLE `clickup_config` ADD COLUMN `hook_secret` varchar(255) NOT NULL DEFAULT ''",
            'project_id' => "ALTER TABLE `clickup_config` ADD COLUMN `project_id` int(11) NOT NULL DEFAULT 0",
            'task_tag' => "ALTER TABLE `clickup_config` ADD COLUMN `task_tag` varchar(255) NOT NULL DEFAULT ''",
        ];

        foreach ($toAdd as $name => $sql) {
            if (!isset($columns[$name])) {
                try {
                    $pdo->exec($sql);
                } catch (\Throwable $e) {
                    Log::warning('ClickupListener: could not add column '.$name.': '.$e->getMessage());
                }
            }
        }
    }

    private function ensureTaskMapColumns(): void
    {
        if (!$this->tableExists('clickup_task_map')) {
            return;
        }
        $pdo = $this->db->pdo();
        $columns = [];
        $st = $pdo->prepare("SHOW COLUMNS FROM `clickup_task_map`");
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        foreach ($rows as $row) {
            if (!empty($row['Field'])) {
                $columns[$row['Field']] = true;
            }
        }

        if (!isset($columns['parent_clickup_task_id'])) {
            try {
                $pdo->exec("ALTER TABLE `clickup_task_map` ADD COLUMN `parent_clickup_task_id` varchar(255) DEFAULT NULL");
            } catch (\Throwable $e) {
                Log::warning('ClickupListener: could not add column parent_clickup_task_id: '.$e->getMessage());
            }
        }
    }

    private function ensureCommentMapTable(): void
    {
        $pdo = $this->db->pdo();
        $sql = "CREATE TABLE IF NOT EXISTS `clickup_comment_map` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `config_id` int(11) NOT NULL,
            `clickup_comment_id` varchar(255) NOT NULL,
            `clickup_task_id` varchar(255) NOT NULL,
            `ticket_id` int(11) NOT NULL,
            `comment_id` int(11) NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_config_comment` (`config_id`, `clickup_comment_id`),
            KEY `idx_ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $stmn = $pdo->prepare($sql);
        $stmn->execute();
        $stmn->closeCursor();
    }
}
