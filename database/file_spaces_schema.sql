-- Файловые пространства Files 1.2. Личные файлы из user_files не меняются.
-- Глобальные данные принадлежат пространству, а не автору.
CREATE TABLE IF NOT EXISTS file_spaces (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(80) NOT NULL,
    space_type ENUM('personal','global','team','project','module') NOT NULL,
    owner_user_id INT DEFAULT NULL,
    name VARCHAR(160) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_file_spaces_uid (uid),
    KEY idx_file_spaces_owner (owner_user_id,space_type),
    CONSTRAINT fk_file_spaces_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_space_nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(32) NOT NULL,
    space_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED DEFAULT NULL,
    node_type ENUM('folder','file') NOT NULL DEFAULT 'folder',
    name VARCHAR(255) NOT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    inherit_acl TINYINT(1) NOT NULL DEFAULT 1,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    mime_type VARCHAR(120) DEFAULT NULL,
    byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    storage_key VARCHAR(190) DEFAULT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_file_space_nodes_uid (uid),
    KEY idx_file_space_nodes_parent (space_id,parent_id,is_deleted,name),
    KEY idx_file_space_nodes_creator (space_id,created_by),
    CONSTRAINT fk_file_space_nodes_space FOREIGN KEY (space_id) REFERENCES file_spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_space_nodes_parent FOREIGN KEY (parent_id) REFERENCES file_space_nodes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_file_space_nodes_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_file_space_nodes_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- scope_key='space' для корня либо 'node:<число>' для папки.
-- Привилегии не привязаны к файловому пути.
CREATE TABLE IF NOT EXISTS file_space_acl (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    scope_key VARCHAR(80) NOT NULL,
    subject_type ENUM('everyone','role','user','group','author') NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    permission_key VARCHAR(48) NOT NULL,
    effect ENUM('allow','deny') NOT NULL,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_file_space_acl_rule (space_id,scope_key,subject_type,subject_id,permission_key),
    KEY idx_file_space_acl_scope (space_id,scope_key),
    CONSTRAINT fk_file_space_acl_space FOREIGN KEY (space_id) REFERENCES file_spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_space_acl_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_space_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    space_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED DEFAULT NULL,
    actor_user_id INT DEFAULT NULL,
    event_code VARCHAR(48) NOT NULL,
    details_json JSON DEFAULT NULL,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_file_space_events_history (space_id,node_id,occurred_at),
    CONSTRAINT fk_file_space_events_space FOREIGN KEY (space_id) REFERENCES file_spaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_space_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO file_spaces (uid,space_type,owner_user_id,name)
VALUES ('global','global',NULL,'Общие файлы')
ON DUPLICATE KEY UPDATE uid=VALUES(uid);

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
