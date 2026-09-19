# Выпуск лицензии для одной установки

Эта инструкция описывает штатный vendor-side процесс выпуска одной лицензии Workspace Organizer 1.0 для одной конкретной установки.

Лицензия является offline Ed25519 token, привязанным к стабильному `installation_id` конкретной установки. Клиентский сервер содержит только публичный ключ проверки. Production private key остаётся только у владельца продукта и никогда не передаётся клиенту.

## 1. Получить installation ID клиента

На установке клиента суперадминистратор открывает:

```text
/system/license
```

и копирует значение **Installation ID**.

Альтернативно на сервере установки:

```bash
php bin/control.php license status --json
```

Используйте значение поля `installation_id`.

Не меняйте существующий `installation_id` вручную. Уже выпущенная лицензия криптографически привязана к нему.

## 2. Подготовить vendor signing environment

Production license private key должен находиться вне репозитория и вне клиентской установки.

Production key id для 1.0:

```text
prod-license-2026-01
```

Пример безопасного пути:

```text
/secure/workspace-signing/prod-license-2026-01.license-secret
```

Пример для Windows vendor workstation:

```text
D:\secure\workspace-signing\prod-license-2026-01.license-secret
```

Никогда не копируйте содержимое private key в Git, CI variables/artifacts, issue, чат, лог или release ZIP.

## 3. Выпустить лицензию

Бессрочная лицензия:

```bash
php tools/vendor-license/issue.php \
  --private-key=/secure/workspace-signing/prod-license-2026-01.license-secret \
  --key-id=prod-license-2026-01 \
  --installation-id=<INSTALLATION_ID> \
  --license-id=lic-customer-001 \
  --edition=standard \
  --customer="Customer name" \
  --features=notes,tasks,files,messenger
```

Если `--expires-at` не передан, `expires_at=null` и лицензия не имеет встроенного срока окончания.

Для временной лицензии передайте UNIX timestamp:

```bash
--expires-at=<UNIX_TIMESTAMP>
```

При необходимости можно также задать дату начала действия:

```bash
--not-before=<UNIX_TIMESTAMP>
```

`license-id` должен быть уникальным идентификатором выданной лицензии в учёте владельца продукта. Не используйте private key material или installation secret в license id.

### Windows PowerShell

```powershell
$PHP = "D:\path\to\php.exe"
$SECURE = "D:\secure\workspace-signing"
$InstallationId = "<INSTALLATION_ID>"

$LicenseToken = & $PHP tools\vendor-license\issue.php `
  --private-key="$SECURE\prod-license-2026-01.license-secret" `
  --key-id=prod-license-2026-01 `
  --installation-id=$InstallationId `
  --license-id=lic-customer-001 `
  --edition=standard `
  --customer="Customer name" `
  --features=notes,tasks,files,messenger

$LicenseToken
```

Инструмент сам проверяет созданный token перед выводом. Production private key при этом не печатается и после операции очищается из рабочей памяти средствами Sodium.

## 4. Передать клиенту только token

Результат имеет формат:

```text
wo1.<key-id>.<payload>.<signature>
```

Клиент получает только эту строку. Private signing key клиенту не передаётся.

License token следует считать клиентским credential и не публиковать в открытых issue/log/chat.

## 5. Активировать на установке клиента

Через браузер:

```text
/system/license
```

Суперадминистратор вставляет token в поле активации.

Либо локально через CLI:

```bash
php bin/control.php license activate --stdin
```

и передаёт token через STDIN.

Для автоматизированной локальной операции можно хранить token во временном защищённом файле вне web root:

```bash
php bin/control.php license activate --token-file=/secure/customer-license.txt
```

После активации:

```bash
php bin/control.php license status --json
```

Ожидается:

```json
{
  "valid": true,
  "code": "valid"
}
```

## 6. Что именно проверяет установка

Workspace Organizer проверяет локально:

1. формат `wo1` token;
2. известный `key_id`;
3. Ed25519 signature встроенным public key;
4. корректность payload;
5. точное совпадение `installation_id`;
6. `not_before`, если задан;
7. `expires_at`, если задан.

Никакой production private key на клиентском сервере не нужен.

## 7. Если лицензия невалидна

При настроенном production trust root приложение переходит в read-only:

- существующие данные остаются доступны для просмотра;
- обычные изменения данных блокируются;
- login/logout и recovery лицензии остаются доступны;
- данные не удаляются и не перешифровываются из-за состояния лицензии.

Recovery остаётся доступным через Core `/system/license` и локальный `bin/control.php`, даже если Admin module отключён.

## 8. Перенос на другую установку

Лицензия привязана к `installation_id`.

Если клиент создаёт новую независимую установку с новым installation ID, старый token там не пройдёт проверку. Для нового installation ID выпускается новый token.

Не копируйте `installation_id` между независимыми установками только ради обхода привязки.

## 9. Ограничение offline-модели

Бессрочная offline-лицензия после выпуска проверяется полностью локально. Центральный vendor server не может мгновенно отозвать уже выданный бессрочный token без доставки нового trust/revocation policy в приложение.

Если бизнес-модель требует автоматического истечения или регулярного продления, используйте `expires_at` и выпускайте следующий подписанный token по мере продления.

## 10. Edition и features

Payload поддерживает `edition` и `features`. Они подписаны и не могут быть незаметно изменены клиентом.

В 1.0 общий license validity уже управляет writable/read-only состоянием установки. Не считайте одно лишь наличие `features` гарантией полного module-level entitlement enforcement: соответствующая runtime-проверка должна существовать в конкретном модуле/операции.

## 11. Production canary перед релизом

Для проверки release candidate используйте disposable installation ID:

```bash
php tools/vendor-license/issue.php \
  --private-key=/secure/workspace-signing/prod-license-2026-01.license-secret \
  --key-id=prod-license-2026-01 \
  --installation-id=11111111-2222-4333-8444-555555555555 \
  --license-id=lic-release-canary-001 \
  --edition=standard \
  --features=notes,tasks,files,messenger
```

Полученный canary token не коммитьте.

Связанные документы:

- `docs/PRODUCTION_TRUST_CEREMONY.md` — создание и хранение production trust roots;
- `docs/RELEASE_ACCEPTANCE.md` — финальные release gates.
