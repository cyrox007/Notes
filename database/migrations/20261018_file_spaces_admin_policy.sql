-- Группы доступа и управляемая политика хранения Files 1.2.
-- Состав групп не меняет роли пользователя во всей платформе.
CREATE TABLE IF NOT EXISTS file_access_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_file_access_groups_name (name),
    CONSTRAINT fk_file_access_groups_author FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_access_group_members (
    group_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    added_by INT DEFAULT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id,user_id),
    KEY idx_file_access_group_members_user (user_id,group_id),
    CONSTRAINT fk_file_access_group_members_group FOREIGN KEY (group_id)
        REFERENCES file_access_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_access_group_members_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_access_group_members_actor FOREIGN KEY (added_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Политика определяет срок удержания удалённых файлов, но не запускает очистку.
CREATE TABLE IF NOT EXISTS file_space_settings (
    space_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    trash_retention_days INT UNSIGNED NOT NULL DEFAULT 30,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_file_space_settings_space FOREIGN KEY (space_id)
        REFERENCES file_spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_space_settings_actor FOREIGN KEY (updated_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO file_space_settings (space_id)
SELECT id FROM file_spaces WHERE uid='global'
ON DUPLICATE KEY UPDATE space_id=VALUES(space_id);
