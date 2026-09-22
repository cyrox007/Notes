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
D:\OSPanel\domains\notes.local
D:\OSPanel\update-runner\workspace-1.0.2
D:\OSPanel\private\notes\update-staging
D:\OSPanel\private\notes\update-state
D:\OSPanel\private\notes\update-backups
D:\OSPanel\private\notes\update-releases
```

### 3. Обновите exact 1.0.1 через доверенный внешний bootstrap

Запустите следующую команду из PowerShell с PHP binary/environment, выбранным OSPanel. Команда намеренно приведена одной строкой, чтобы не требовалось экранирование переноса строк PowerShell:

```powershell
php D:\OSPanel\update-runner\workspace-1.0.2\bin\update_bootstrap.php --app-root="D:\OSPanel\domains\notes.local" --manifest="D:\OSPanel\update-release\update.json" --signature="D:\OSPanel\update-release\update.sig" --package="D:\OSPanel\update-release\workspace-organizer-v1.0.2.zip" --transaction=update-1-0-1-to-1-0-2 --expected-source-version=1.0.1 --expected-source-version-code=10001 --stage-root="D:\OSPanel\private\notes\update-staging" --state-root="D:\OSPanel\private\notes\update-state" --backup-root="D:\OSPanel\private\notes\update-backups" --candidate-root="D:\OSPanel\private\notes\update-releases" --json
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
