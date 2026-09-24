# Релизная приёмка Windows / OSPanel для 1.0.2

Этот чек-лист дополняет автоматический CI на `windows-latest`. Успешный GitHub-hosted Windows подтверждает совместимость PHP/filesystem/updater paths в Windows, но **не заменяет** финальную приёмку OSPanel 5.2.2 на реальном целевом стеке.

## Автоматический Windows gate

Workflow `.github/workflows/windows-hosting-compat.yml` работает на PHP 8.1 и PHP 8.3 и проверяет:

- границы updater paths Windows: drive-letter, slash/backslash, UNC и case-insensitive paths;
- staging подписанных manifest/package;
- remote signed update delivery через in-memory transport;
- извлечение external release candidate и повторную проверку;
- retention recovery-artifacts updater;
- native view/module runtime contracts;
- release-package/deployment surface contracts;
- наличие `update_doctor.php`, `update_run.php` и `update_retention.php`.

Этот gate не использует production signing secret.

## Финальная приёмка OSPanel 5.2.2

Выполняйте её только на disposable-копии установки и базы данных либо после создания проверенного backup. Не используйте production dataset для намеренного rollback drill.

### 1. Baseline

Начните с exact published package `v1.0.1`.

Зафиксируйте:

```powershell
php -r "require 'core/Version.php'; echo Core\Version::VERSION, PHP_EOL;"
php -r "require 'core/Version.php'; echo Core\Version::VERSION_CODE, PHP_EOL;"
php bin/healthcheck.php --json
```

Ожидаемая source identity:

```text
1.0.1
10001
```

Убедитесь, что приложение работает через OSPanel hostname, а база данных/private storage содержат disposable test data, которые можно проверить после обновления.

### 2. Подготовьте финальные artifacts 1.0.2

Используйте только финальные release artifacts:

- `workspace-organizer-v1.0.2.zip`;
- его опубликованный SHA-256;
- `update.json`;
- `update.sig`.

Проверьте checksum ZIP до извлечения временного runner.

Временная директория runner 1.0.2 и все state directories updater должны находиться вне live application tree 1.0.1. Пример:

```text
C:\OSPanel\home\notes.local
C:\OSPanel\update-runner\workspace-1.0.2
C:\OSPanel\private\notes\update-staging
C:\OSPanel\private\notes\update-state
C:\OSPanel\private\notes\update-backups
C:\OSPanel\private\notes\update-releases
```

### 3. Обновите exact 1.0.1 через доверенный внешний bootstrap

Запустите следующую команду из PowerShell с PHP binary/environment, выбранным OSPanel. Команда намеренно приведена одной строкой, чтобы не требовалось экранирование переноса строк PowerShell:

```powershell
php C:\OSPanel\update-runner\workspace-1.0.2\bin\update_bootstrap.php --app-root="C:\OSPanel\home\notes.local" --manifest="C:\OSPanel\update-release\update.json" --signature="C:\OSPanel\update-release\update.sig" --package="C:\OSPanel\update-release\workspace-organizer-v1.0.2.zip" --transaction=update-1-0-1-to-1-0-2 --expected-source-version=1.0.1 --expected-source-version-code=10001 --stage-root="C:\OSPanel\private\notes\update-staging" --state-root="C:\OSPanel\private\notes\update-state" --backup-root="C:\OSPanel\private\notes\update-backups" --candidate-root="C:\OSPanel\private\notes\update-releases" --json
```

JSON-результат должен сообщить `committed`.

### 4. Проверки после обновления

Из live application directory:

```powershell
php -r "require 'core/Version.php'; echo Core\Version::VERSION, PHP_EOL;"
php -r "require 'core/Version.php'; echo Core\Version::VERSION_CODE, PHP_EOL;"
php bin/healthcheck.php --json
php bin/migrate.php --status
php bin/update_doctor.php --json
php bin/update_retention.php --json
```

Ожидаемая identity:

```text
1.0.2
10002
```

Также проверьте в браузере:

- login по-прежнему работает;
- ранее созданные данные Notes/Tasks/Files/Profile присутствуют;
- Admin -> Updates открывается без PHP/HTTP errors;
- приложение работает под настроенным OSPanel hostname/base path;
- после commit не остаётся активного maintenance marker updater;
- с двумя пользователями Messenger и запущенным WebSocket оба клиента показывают «WebSocket · в сети», а сообщение доставляется без reload;
- остановите native WS process и убедитесь, что оба клиента автоматически переходят в «Long Poll · резервный канал», при этом durable messages продолжают синхронизироваться;
- снова запустите native WS process и убедитесь, что клиенты автоматически возвращаются в «WebSocket · в сети» без page reload.

### 4A. Автоматическая настройка доступа к обновлениям

После перехода на `1.0.2` пользователь не должен создавать `activation.txt`,
выбирать путь для `update-access.json` или запускать `bin/update_activate.php`.

На тестовой установке:

1. убедитесь, что обычная лицензия Workspace действительна;
2. оставьте `UPDATE_CREDENTIALS_FILE` пустым либо сохраните историческое значение
   из `1.0.0`, указывающее внутрь дерева приложения;
3. откройте **Админ → Обновления** и нажмите проверку обновлений;
4. убедитесь, что интерфейс не требует ручной настройки credential path;
5. убедитесь, что создан файл
   `C:\OSPanel\private\notes\update-access\update-access.json` при
   `PRIVATE_STORAGE_PATH=C:\OSPanel\private\notes`;
6. повторите проверку и подтвердите, что готовый credential переиспользуется без
   дополнительного кода или действий пользователя;
7. не выводите содержимое credential-файла в терминал, логи или отчёт.

Если control plane временно недоступен, локальная лицензия должна продолжить
работать; повторная проверка обновлений должна выполнить bootstrap после
восстановления связи.

После публикации `1.0.2` этот же пользовательский путь необходимо проверить на
реальном небольшом обновлении `1.0.2 -> 1.0.3`.
### 5. Доказательство rollback

Автоматический Linux release drill принудительно выполняет post-switch mutation базы данных, затем имитирует failed healthcheck и подтверждает автоматический rollback кода и базы данных до exact 1.0.1.

Для финального OSPanel evidence повторяйте destructive rollback testing только на disposable clone приложения и clone базы данных. Никогда намеренно не внедряйте failed candidate в основную локальную или production-копию.

## Запись приёмки

Зафиксируйте:

- версию OSPanel;
- выбранную версию PHP;
- source commit `v1.0.1`;
- финальный release commit 1.0.2;
- SHA-256 ZIP;
- update signing key ID, сообщённый verification;
- JSON-результат bootstrap;
- результат post-upgrade healthcheck;
- результат browser smoke-check, включая восстановление WebSocket → Long Poll → WebSocket.

Только после успешного завершения этой ручной проверки в release notes можно указывать, что приёмка OSPanel 5.2.2 пройдена.
