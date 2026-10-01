# Серверный доступ к официальным обновлениям

Workspace Organizer использует внешний реестр лицензий и релизов для выдачи доступа к официальным update-артефактам.

Локальная лицензия проверяется офлайн; недоступность реестра не переводит действующую установку в read-only. Сеть нужна только для обнаружения и получения нового обновления.

## Штатный сценарий

1. оператор выпускает installation-bound лицензию `wo1...` и регистрирует её;
2. пользователь активирует лицензию в Workspace;
3. клиент локально проверяет Ed25519-подпись и Installation ID;
4. клиент по HTTPS использует лицензионный токен только для bootstrap доступа к обновлениям;
5. сервер повторно проверяет зарегистрированный токен, статус и сроки;
6. сервер выдаёт отдельный случайный installation credential;
7. credential сохраняется вне дерева приложения в `PRIVATE_STORAGE_PATH/update-access/update-access.json`;
8. дальнейшие запросы feed/manifest/signature/package используют этот credential.

Приватный signing key клиенту и серверу выдачи обновлений не передаётся.

## Настройки клиента

```dotenv
UPDATE_SERVER_URL=https://jsinteractive.ru/api/notes/v1/
UPDATE_FEED_URL=https://jsinteractive.ru/api/notes/v1/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_ACCESS_MODE=auto
UPDATE_CREDENTIALS_FILE=
```

Пустой `UPDATE_CREDENTIALS_FILE` — штатный вариант: безопасный путь выводится из `PRIVATE_STORAGE_PATH`.

`UPDATE_ACCESS_MODE=offline` полностью отключает сетевой bootstrap.

## Безопасность

Сервер проверяет право на каждом запросе feed, manifest, signature и ZIP.

Запрещают выдачу:

- revoked-лицензия;
- истёкшая лицензия;
- истёкший `updates_until`;
- ограничение максимальной версии;
- неверный Installation ID или токен.

После авторизации клиент всё равно обязан проверить Ed25519 manifest, SHA-256/размер ZIP, структуру архива и внешний staging.

## Реестр поставщика

Production-реестр обслуживается внешним control plane (`jsint-site`). Локальный `tools/license-server/` остаётся совместимым эталонным сервисом и инструментом изолированных тестов.

Сервер хранит:

- подписанный лицензионный токен;
- Installation ID;
- статус и сроки;
- update entitlement;
- хеш выданного installation credential;
- зарегистрированные неизменяемые артефакты релизов.

Пакеты не должны иметь параллельный публичный URL, обходящий проверку entitlement.

## Bootstrap endpoint

```text
POST /api/notes/v1/activate
```

Запрос содержит Installation ID, лицензионный токен, текущую версию, код версии и канал.

Готовый credential повторно используется и не ротируется при каждой проверке.

Старый `bin/update_activate.php` остаётся только для аварийной совместимости ранних 1.0.x и не является обычным пользовательским сценарием.

## Клиент

Обычный интерфейс: **Admin → Updates**.

Диагностические команды:

```bash
php bin/update_remote.php --check-only --json
php bin/update_remote.php --json
```

401/403 от control plane означает проблему права на получение обновления, а не разрешение ослабить криптографическую проверку.

## Эксплуатация сервера

Control plane должен работать только по HTTPS. Ответы с credential/артефактами не кешируются и не сжимаются промежуточным proxy без отдельного безопасного решения.

SQLite-реализация `tools/license-server/` хранится вне document root; исходники клиентского Workspace и web-installer на домене реестра не запускаются.

## Проверки

Контракты должны покрывать привязку к установке, отзыв, сроки, ограничение версии, прямой запрос ZIP, гонку revoke между feed и download, целостность артефакта и независимость локальной offline-лицензии.
