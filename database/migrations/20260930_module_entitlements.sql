-- Workspace Organizer 1.0.13 — лицензионное состояние модулей.
-- configured_state продолжает хранить намерение администратора установки;
-- effective_state отдельно отражает отсутствие разрешения в действующей лицензии.

ALTER TABLE `module_lifecycle`
    MODIFY COLUMN `effective_state` ENUM(
        'discovered',
        'installed',
        'enabled',
        'disabled',
        'unlicensed',
        'incompatible',
        'degraded',
        'quarantined',
        'uninstalled'
    ) NOT NULL;
