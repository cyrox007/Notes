# Удалённая доставка подписанного обновления

Удалённая доставка получает и проверяет релиз, но не получает самостоятельного права менять live-установку.

Цепочка:

```text
HTTPS feed
  -> manifest + detached signature
  -> проверка совместимости и подписи
  -> точный ZIP по filename/size/SHA-256
  -> аудит структуры архива
  -> неизменяемый внешний staging
  -> остановка
```

После staging отдельно выполняются maintenance, backup, release candidate, apply и rollback.

## Конфигурация

```dotenv
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
```

Для entitlement-controlled выдачи также используется [ONLINE_UPDATE_ACCESS.md](ONLINE_UPDATE_ACCESS.md).

## Feed

Feed указывает только на подписанные metadata текущего канала. Он не является trust root: клиент доверяет только локально установленным публичным update keys и проверенной подписи manifest.

## Проверка без скачивания ZIP

```bash
php bin/update_remote.php --check-only --json
```

Команда получает feed/manifest/signature и проверяет:

- TLS;
- канал;
- версию и version code;
- совместимость source/target;
- подпись;
- entitlement.

## Загрузка

```bash
php bin/update_remote.php --json
```

ZIP сохраняется во внешний staging и проверяется по размеру, SHA-256 и структуре архива.

Нельзя распаковывать удалённый ZIP непосредственно поверх live tree.

## Сетевые правила

- только HTTPS;
- проверка сертификата обязательна;
- redirect не должен обходить host/TLS policy;
- credential передаётся только разрешённому control plane;
- временный сетевой сбой не меняет локальную лицензию;
- повреждённый/неподписанный ответ закрыто прекращает обновление.

## Дальнейший lifecycle

После staging используются:

- [UPDATE_RELEASE_CANDIDATES.md](UPDATE_RELEASE_CANDIDATES.md)
- [UPDATER_LIVE_APPLY.md](UPDATER_LIVE_APPLY.md)
