-- Непереключаемый серверный режим диалога Messenger.
-- Существующие переписки остаются server_encrypted: старый текст не становится E2EE.
-- Защищённый диалог создаётся только отдельно, до появления обычных сообщений.
ALTER TABLE `dialogs`
    ADD COLUMN `encryption_mode` ENUM('server_encrypted','e2ee')
    NOT NULL DEFAULT 'server_encrypted' AFTER `type`;

-- На уровне БД не допускаем ошибочную запись обычного сообщения
-- или незашифрованного вложения в E2EE-диалог. В триггере одно выражение,
-- поэтому он совместим с простым SQL-импортом на обычном хостинге.
DROP TRIGGER IF EXISTS `messenger_reject_plain_e2ee_message`;
CREATE TRIGGER `messenger_reject_plain_e2ee_message`
BEFORE INSERT ON `messages`
FOR EACH ROW
SET NEW.dialog_id = IF(
    (SELECT d.encryption_mode FROM `dialogs` d WHERE d.id = NEW.dialog_id) = 'e2ee',
    NULL, NEW.dialog_id
);

DROP TRIGGER IF EXISTS `messenger_reject_plain_e2ee_attachment`;
CREATE TRIGGER `messenger_reject_plain_e2ee_attachment`
BEFORE INSERT ON `messenger_attachments`
FOR EACH ROW
SET NEW.dialog_id = IF(
    (SELECT d.encryption_mode FROM `dialogs` d WHERE d.id = NEW.dialog_id) = 'e2ee',
    NULL, NEW.dialog_id
);

-- Запрещаем тихое изменение режима существующего диалога.
DROP TRIGGER IF EXISTS `messenger_immutable_encryption_mode`;
CREATE TRIGGER `messenger_immutable_encryption_mode`
BEFORE UPDATE ON `dialogs`
FOR EACH ROW
SET NEW.encryption_mode = IF(
    NEW.encryption_mode <> OLD.encryption_mode, NULL, NEW.encryption_mode
);
