# Подписанные обновления Workspace Organizer

Актуально для линии **1.0.14**.

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

Начиная с 1.0.14 обычные обновления выполняются через **Admin → Updates**. Для двух уже опубликованных исходных версий есть одно переходное исключение: exact 1.0.12 и 1.0.13 перед первым обновлением до 1.0.14 требуют одноразовой подготовки updater-контура из доверенного exact-пакета 1.0.14:

```bash
php tools/release/bootstrap-1.0.12-updater.php --yes --root=/path/to/workspace
```

Этот handoff не меняет версию приложения и БД. После него основной сценарий снова выполняется через интерфейс:

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
- ручное выборочное копирование updater PHP-файлов в старую установку; единственное переходное исключение для exact 1.0.12/1.0.13 — штатный `tools/release/bootstrap-1.0.12-updater.php` из доверенного exact-пакета 1.0.14.

## Поддерживаемый переход 1.0.14

Для опубликованных exact 1.0.12 и 1.0.13 переход к 1.0.14 начинается с одноразового совместимого updater-handoff. После него установка должна увидеть 1.0.14 во внешнем реестре и применить подписанный релиз штатным web-updater.

Новые версии после 1.0.14 не должны требовать повторения этого переходного моста: исправленный updater-контур уже входит в 1.0.14.
