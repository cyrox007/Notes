# Лицензирование установки

Workspace Organizer 1.0 использует одну офлайн-проверяемую лицензию на каждую установку.

Необязательный управляемый сервером доступ к официальным пакетам обновлений описан в [ONLINE_UPDATE_ACCESS.md](ONLINE_UPDATE_ACCESS.md). Реестр поставщика хранит подписанную лицензию и право на обновления. Он не заменяет локальную runtime-проверку и не делает обычную работу приложения зависимой от доступности сети.

## Модель доверия

- У каждой установки есть стабильный `installation_id`, хранящийся в `system_settings`.
- Лицензия — это подписанный Ed25519 token, привязанный ровно к этому `installation_id`.
- Runtime-установки содержат **только публичные ключи проверки**.
- Приватный Ed25519 signing key нельзя коммитить в репозиторий, копировать в hosting bundle, записывать в `.env`, устанавливать на сервер клиента, прикладывать к CI artifact или помещать в support archive/application backup.
- Проверка выполняется офлайн. Обычные проверки лицензии не обращаются к licensing server.
- Неверная, отсутствующая, истёкшая или принадлежащая другой установке лицензия никогда не удаляет, не переписывает, не шифрует и иным образом не повреждает пользовательские данные.
- Runtime enforcement неактивен, пока публичный trust registry релиза пуст. После поставки доверенного публичного ключа некорректное состояние лицензии переводит систему в recovery-safe режим только для чтения: чтение, login/logout и восстановление лицензии остаются доступны, а изменение данных блокируется.

## Публичный реестр доверия

Production public keys находятся в data-only файле:

```text
config/license_trusted_keys.php
```

Реестр возвращает map из immutable key id в base64url-кодированный сырой 32-byte Ed25519 **public** key. `LicenseVerifier` по умолчанию загружает этот реестр; тесты могут напрямую внедрять временные public keys.

Не помещайте в этот файл генерацию ключей, пути к приватным ключам, tokens, customer secrets или signing credentials. Обычный клиентский релиз обязан содержать public registry, но не должен содержать `tools/vendor-license/` или какие-либо файлы приватных ключей.

Пустой реестр до production key ceremony является намеренным состоянием. Он оставляет enforcement отключённым вместо неявного доверия локально сгенерированному ключу.

## Формат token

```text
wo1.<key-id>.<base64url-json-payload>.<base64url-ed25519-signature>
```

Подпись покрывает точные ASCII bytes:

```text
wo1.<key-id>.<base64url-json-payload>
```

Обязательные поля payload:

```json
{
  "v": 1,
  "license_id": "lic-...",
  "installation_id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "issued_at": 1700000000,
  "expires_at": 1730000000,
  "edition": "standard"
}
```

`expires_at` может быть `null` для бессрочной лицензии. Дополнительные поля, которые сейчас понимает verifier: `not_before`, `customer`, `features` и `max_users`. Положительное целое `max_users` ограничивает количество активных аккаунтов установки; отсутствие поля означает отсутствие лимита для обратной совместимости.

## Ротация ключей

Token содержит `key-id`. `LicenseVerifier` принимает map доверенных публичных ключей, поэтому в течение окна ротации релиз может содержать одновременно старый и новый public key.

Порядок ротации:

1. Создайте новую пару в офлайн vendor signing environment.
2. Добавьте только новый public key в `config/license_trusted_keys.php`, сохранив старый public key.
3. Сначала выпустите сборку с двойным доверием.
4. Начните выдавать новые лицензии с новым key id.
5. Перевыпустите лицензии, зависящие от старого ключа, либо дождитесь их окончания.
6. Удалите старый public key только в одном из последующих релизов после закрытия окна зависимости.

Немедленное удаление public key делает лицензии, подписанные только им, непроверяемыми. Поэтому вывод ключа из обращения — это release-management операция, а не обычная очистка.

## Production-церемония ключа подписи

CLI helpers только для поставщика находятся в `tools/vendor-license/` исходного репозитория. Workflow hosting-package явно исключает этот каталог из клиентских release ZIP. Сами helpers не содержат production secret.

### 1. Создайте пару на контролируемой/офлайн машине подписи

Используйте абсолютный путь приватного ключа вне дерева репозитория:

```bash
php tools/vendor-license/keygen.php \
  --key-id=prod-2026-01 \
  --private-out=/secure/offline/workspace-prod-2026-01.license-secret
```

Команда:

- отказывается записывать private key внутрь дерева репозитория;
- не перезаписывает существующий private key;
- создаёт private key с mode `0600` на Unix-like системах;
- выводит только public key/registry entry и никогда не выводит private key;
- обнуляет secret buffers внутри процесса перед завершением.

Резервную копию private key храните только в защищённом офлайн/secret storage поставщика в соответствии с политикой восстановления организации.

### 2. Добавьте в release registry только публичный ключ

Скопируйте выведенную registry entry в `config/license_trusted_keys.php`, например:

```php
return [
    'prod-2026-01' => '<base64url-public-key>',
];
```

Закоммитьте и проверьте изменение, содержащее только public key, затем запустите licensing CI и полный release CI. Проверьте собранный клиентский ZIP: он должен содержать public registry, но не `tools/vendor-license/`, `*.license-secret` или иной signing material.

### 3. Выпустите лицензию офлайн

Скопируйте точный Installation ID клиента из `/admin/license` и запустите на офлайн signing machine:

```bash
php tools/vendor-license/issue.php \
  --private-key=/secure/offline/workspace-prod-2026-01.license-secret \
  --key-id=prod-2026-01 \
  --installation-id=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx \
  --license-id=lic-customer-001 \
  --edition=team \
  --expires-at=1798761599 \
  --customer='Customer name' \
  --max-users=20 \
  --features=workspace.notes,workspace.tasks,workspace.files,workspace.messenger,workspace.profile,workspace.admin
```

Если `--expires-at` не указан, лицензия бессрочная. Необязательный `--not-before` — Unix timestamp. Issuer выводит в stdout только конечный token `wo1...`, выводит public key из внешнего private key, сам проверяет созданный token через `LicenseVerifier` и обнуляет загруженный secret перед завершением.

Не направляйте stdout issuer в общие CI logs или ticketing systems: license token не является signing secret, но это всё равно привязанный к конкретному клиенту entitlement material.

## Ограничение количества пользователей

Если валидный подписанный payload содержит `max_users`, Workspace Organizer применяет этот лимит к аккаунтам с `users.is_active = 1`.

- заблокированные аккаунты продолжают занимать место, потому что остаются активными identity;
- деактивированные аккаунты освобождают место;
- создание пользователя администратором и публичная/invite регистрация используют одну центральную provisioning boundary;
- реактивация деактивированного аккаунта занимает место;
- активация лицензии отклоняется, если новый подписанный лимит ниже текущего количества активных пользователей;
- row lock license-token сериализует операции, меняющие количество мест, чтобы параллельные регистрации не могли намеренно или случайно превысить лимит.

Лицензии, выпущенные до появления этого поля, остаются валидными и безлимитными. Ограничение пользователей — только entitlement boundary: превышение лимита никогда автоматически не удаляет и не отключает существующие клиентские аккаунты.

## Runtime enforcement и восстановление

После появления хотя бы одного доверенного production public key неверная/отсутствующая/истёкшая лицензия переводит приложение в recovery-safe режим только для чтения:

- GET/HEAD/OPTIONS и обычные read views остаются доступны с учётом RBAC/ACL;
- login и logout остаются доступны;
- активация/удаление лицензии в `/admin/license` остаётся доступна для восстановления;
- обычные HTTP mutations отклоняются;
- Messenger может reconnect/read/search, но mutations сообщений/reactions/receipts/media/dialogs/groups запрещены;
- открытые WebSocket connections повторно проверяют лицензию перед каждым mutating action, поэтому expiry нельзя обойти, просто оставив socket открытым.

Ошибка проверки лицензии никогда не выполняет разрушительных операций с данными.

## Администрирование

`/admin/license` показывает стабильный Installation ID и текущее состояние проверки. Пользователи с `admin.settings.manage` могут просматривать состояние; активация/удаление дополнительно требует реальной роли `superadmin`. Активация проверяет signature, binding установки и time window **до** замены сохранённого token.

Удаление token очищает только `workspace_license_token`; пользовательские данные и identifier установки остаются без изменений.
