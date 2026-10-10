-- Preserve explicit account state. The legacy bridge must not reactivate a
-- blocked user during an unrelated profile/password update.
DROP TRIGGER IF EXISTS `users_before_update_account_status_bridge`;
CREATE TRIGGER `users_before_update_account_status_bridge`
BEFORE UPDATE ON `users`
FOR EACH ROW
SET NEW.account_status = CASE
    WHEN NEW.is_active = 0 THEN 'inactive'
    WHEN NEW.account_status <> OLD.account_status THEN NEW.account_status
    WHEN NEW.role <> OLD.role AND NEW.role = 999 THEN 'blocked'
    WHEN NEW.role <> OLD.role AND NEW.role = 899 THEN 'inactive'
    WHEN (OLD.role IN (899,999) AND NEW.role NOT IN (899,999))
         OR NEW.is_active <> OLD.is_active THEN 'active'
    ELSE NEW.account_status
END;
