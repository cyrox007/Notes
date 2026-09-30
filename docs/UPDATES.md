# Подписанные обновления Workspace Organizer

Актуально для линии **1.0.13**.

Подпись обновлений и подпись лицензий — независимые trust domains. License key не разрешает изменение кода, update key не выпускает лицензии.

Исторические переходы ранних версий описаны в [UPDATER_HISTORY.md](UPDATER_HISTORY.md).

## Артефакты релиза

Подписанное обновление состоит из:

1. hosting ZIP;
2. JSON manifest;
3. detached Ed25519 signature manifest.

Клиент содержит только публичные update keys:

```text
config/update_trusted_keys.php
```

Private signing key находится только во внешнем доверенном signing environment.

## Manifest

Manifest фиксирует как минимум:

- source/target compatibility;
- версию и version code;
- имя ZIP;
- размер;
- SHA-256;
- release/source identity;
- канал;
- необходимые системные требования.

Подпись покрывает точные bytes manifest.

## Подготовка релиза

Рекомендуемый процесс:

1. exact release SHA проходит обязательный CI;
2. `Build hosting package` создаёт upload-ready ZIP и checksum;
3. оператор формирует manifest на точный ZIP;
4. manifest подписывается offline update-key;
5. manifest/signature/package регистрируются во внешнем реестре;
6. stable-feed начинает указывать на зарегистрированный release;
7. обычная установка получает обновление через Admin → Updates.

После принятия SHA-256 ZIP не перепаковывается.

## Пользовательский путь

Для текущих 1.0.x отдельный bootstrap-файл пользователю не нужен.

Обычный сценарий:

```text
Admin → Updates
  -> проверка stable-feed и entitlement
  -> проверка manifest/signature
  -> загрузка точного ZIP
  -> внешний staging
  -> backup
  -> проверенный release candidate
  -> maintenance
  -> apply
  -> migrations
  -> healthcheck
  -> commit
```

При ошибке после destructive boundary запускается rollback/recovery.

## Доставка

Сетевой слой: [UPDATE_REMOTE_DELIVERY.md](UPDATE_REMOTE_DELIVERY.md).

Entitlement выдачи: [ONLINE_UPDATE_ACCESS.md](ONLINE_UPDATE_ACCESS.md).

Проверенный кандидат: [UPDATE_RELEASE_CANDIDATES.md](UPDATE_RELEASE_CANDIDATES.md).

Применение и rollback: [UPDATER_LIVE_APPLY.md](UPDATER_LIVE_APPLY.md).

## Внешнее состояние

Staging, journal, maintenance marker, rollback backup и release candidates находятся вне live application tree. Это позволяет продолжить recovery даже после частичной замены файлов.

## Retention

Terminal artifacts не удаляются как побочный эффект успешного commit. Отдельная retention-процедура удаляет только достаточно старые, безопасные и неиспользуемые rollback/candidate artifacts, сохраняя заданное число последних транзакций.

## WebSocket

Перед destructive apply клиент останавливает realtime текущей вкладки.

После обновления:

- доступный Messenger → WebSocket перезапускается;
- отключённый/нелицензированный Messenger → старый WebSocket останавливается;
- rollback → восстанавливается исходное состояние процесса.

## Что запрещено

- распаковка ZIP поверх live tree;
- применение неподписанного manifest;
- подмена SHA-256 после подписи;
- хранение private signing key в установке клиента;
- изменение уже применённой миграции;
- ручное продолжение обычного user-flow через копирование новых updater PHP-файлов в старую установку.

## Поддерживаемый переход 1.0.13

Для опубликованной 1.0.13 основной предыдущий baseline — 1.0.12. После регистрации 1.0.13 во внешнем реестре контрольная установка 1.0.12 должна увидеть и применить её обычным updater.
