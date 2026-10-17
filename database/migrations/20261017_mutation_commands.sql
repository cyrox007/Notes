-- Общий журнал идемпотентных изменяющих команд 1.2.
-- Содержимое прикладных сущностей здесь не хранится.
CREATE TABLE IF NOT EXISTS `mutation_commands` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `actor_key` VARCHAR(96) NOT NULL,
    `command_id` VARCHAR(96) NOT NULL,
    `scope` VARCHAR(64) NOT NULL,
    `action` VARCHAR(96) NOT NULL,
    `entity_type` VARCHAR(64) NOT NULL,
    `entity_uid` VARCHAR(128) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `operation_token` CHAR(32) NOT NULL,
    `status` ENUM('processing','applied','conflict') NOT NULL DEFAULT 'processing',
    `response_json` LONGTEXT DEFAULT NULL,
    `result_version` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `completed_at` DATETIME(6) DEFAULT NULL,
    UNIQUE KEY `uq_mutation_commands_actor_command` (`actor_key`,`command_id`),
    KEY `idx_mutation_commands_entity` (`scope`,`entity_type`,`entity_uid`,`created_at`),
    KEY `idx_mutation_commands_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
