-- Неизменяемые версии состава защищённых диалогов и границы доступа к истории.
-- При изменении участников версия комнаты растёт атомарно с изменением членства.
-- Участник, вступивший заново, не получает пакеты и историю до нового вступления.
ALTER TABLE `dialogs`
    ADD COLUMN `e2ee_membership_version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `encryption_mode`;

ALTER TABLE `user_to_dialogs`
    ADD COLUMN `e2ee_join_version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `joined_at`;

ALTER TABLE `messenger_e2ee_mailbox`
    ADD COLUMN `membership_version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `dialog_id`;

ALTER TABLE `messenger_e2ee_events`
    ADD COLUMN `membership_version` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `dialog_id`;
