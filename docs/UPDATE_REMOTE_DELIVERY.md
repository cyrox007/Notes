# Удалённая доставка подписанных обновлений

Для авторизованной доставки с server-side отзывом лицензии см. [ONLINE_UPDATE_ACCESS.md](ONLINE_UPDATE_ACCESS.md). И этот CLI, и admin-страница обновлений используют `UPDATE_ACCESS_MODE` и внешний `UPDATE_CREDENTIALS_FILE`.

Workspace Organizer 1.0 умеет обнаруживать и помещать в staging подписанное обновление из vendor-controlled HTTPS feed, не предоставляя сетевому слою никаких полномочий изменять live installation.

Граница намеренно разделена:

```text
remote feed
  -> signed manifest + detached signature
  -> compatibility verification
  -> exact package download from signed filename/size/SHA-256
  -> local ZIP structural audit
  -> immutable external staging
  -> STOP
```

Вход в maintenance, backup, извлечение release candidate, live apply и rollback остаются отдельными transaction steps.

## Конфигурация

Рекомендуемые production-настройки `.env`:

```dotenv
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
```

Поддерживаемые channels: `alpha`, `beta`, `stable`.

Remote delivery дополнительно требует PHP extension `openssl`. Остальная часть приложения продолжает работать, если эта необязательная network capability недоступна; `bin/update_remote.php` завершается fail-closed и не ослабляет TLS verification.

`UPDATE_FEED_URL` должен быть public HTTPS URL. Встроенный transport намеренно отклоняет:

- обычный HTTP;
- встроенные URL credentials;
- порты, отличные от 443;
- literal IP addresses;
- DNS results из private/reserved/special-use ranges;
- redirects;
- transfer-encoded/chunked responses;
- compressed HTTP response bodies;
- отсутствующий или неоднозначный `Content-Length`;
- raw ASCII control characters или spaces в URL/request target;
- TLS certificates/peer names, не прошедшие verification.

DNS разрешается до соединения, а проверенный public address закрепляется за TLS socket; certificate verification при этом по-прежнему использует исходное DNS host name. Это не позволяет уже проверенному public name незаметно разрешиться в private address в момент реального соединения.

## Контракт feed

Feed намеренно небольшой и **сам по себе не является trust root**:

```json
{
  "schema": 1,
  "product": "workspace-organizer",
  "channel": "stable",
  "manifest": "workspace-organizer-v1.0.0.update.json",
  "signature": "workspace-organizer-v1.0.0.update.sig"
}
```

`manifest` и `signature` должны быть простыми filenames в том же HTTPS directory, что и feed. Paths, absolute URLs и traversal отклоняются.

Feed может быть изменён недоверенным посредником без получения полномочий на обновление. Updater доверяет только manifest, точные bytes которого проходят настроенную Ed25519 verification update key. Проверенный manifest затем определяет:

- product/version/version code;
- channel;
- source commit;
- minimum source version;
- minimum PHP version;
- **package filename**;
- **package byte size**;
- **package SHA-256**.

Package URL выводится из подписанного package filename в том же feed directory. Неподписанное поле `package` в feed игнорируется.

## Проверка без скачивания package

```bash
php bin/update_remote.php --check-only
```

или явно:

```bash
php bin/update_remote.php \
  --feed-url=https://updates.example.com/workspace-organizer/stable/feed.json \
  --channel=stable \
  --check-only --json
```

Команда скачивает только feed, manifest и detached signature. Она проверяет signature, аутентифицирует signed metadata и классифицирует результат без получения bytes package:

- `update_available` — доступно более новое совместимое signed update;
- `up_to_date` — signed feed указывает на установленный `VERSION_CODE`;
- `ahead_of_feed` — эта installation новее signed feed;
- `update_incompatible` — более новое signed update существует, но не проходит policy source-version или PHP compatibility.

Все четыре read-only состояния нормально возвращаются с `package_downloaded=false` и `live_files_changed=false`. Команда **не** входит в maintenance, не создаёт transaction и не изменяет live files.

Это intended primitive для будущего administrator update UI.

## Скачивание и immutable staging

```bash
php bin/update_remote.php --json
```

Необязательный явный staging root:

```bash
php bin/update_remote.php \
  --stage-root=/var/lib/notes/update-staging \
  --json
```

Action path строже read-only check: same-version, downgrade, source-floor и PHP incompatibility отклоняются до скачивания package или создания stage.

Package path не принимается из feed. После verification manifest updater выводит package URL из подписанного filename, затем требует, чтобы HTTP `Content-Length` совпадал с signed size, и потоково записывает ровно это количество bytes во временный приватный внешний directory, одновременно рассчитывая SHA-256.

Remote package больше 512 MiB отклоняется до скачивания, даже если signed manifest запрашивает больший размер. Это дополнительный resource limit network ingress, а не замена проверке signed size.

После скачивания снова запускаются существующие local updater contracts:

1. `UpdatePackageStager::verifyPackage()` проверяет signed filename, size, SHA-256 и ZIP magic;
2. `UpdateArchiveInspector` выполняет ZIP structural/safety audit без распаковки;
3. `UpdatePackageStager::stage()` копирует точные manifest/signature/package в обычный immutable external stage и повторно проверяет package после копирования;
4. временные network download bytes удаляются.

Итоговый stage полностью взаимозаменяем с вручную подготовленным stage из `bin/update.php`. Downstream backup/candidate/apply commands не нужно знать, пришёл проверенный package через local media или remote feed.

## Concurrency

Remote package delivery использует non-blocking lock под external staging root. Параллельные remote downloads не могут одновременно писать через один ingress path. Существующий immutable staging lock продолжает сериализовать финальную публикацию stage.

## Доверие и поведение при ошибках

Remote delivery завершается fail-closed, если:

- не настроен trusted update public key;
- TLS, framing URL или DNS policy нельзя проверить;
- feed shape/product/channel некорректны;
- manifest/signature names небезопасны;
- signature verification не проходит;
- signed manifest channel отличается от configured channel;
- package size превышает remote ingress ceiling;
- transport size/hash package отличается от signed manifest;
- ZIP structural audit завершается ошибкой;
- staging root находится внутри application tree.

Кроме того, **stage action** завершается fail-closed, если signed target не новее или несовместим с текущими source/runtime. Read-only `--check-only` сообщает эти валидные signed states как данные, а не превращает их в network errors.

Ни одна ошибка этого слоя не должна требовать rollback, потому что слой никогда не пересекает live mutation boundary.

## Правило публикации

Публикуйте directory канала как immutable release assets плюс один небольшой mutable feed pointer. Типичный directory:

```text
/stable/feed.json
/stable/workspace-organizer-v1.0.0.update.json
/stable/workspace-organizer-v1.0.0.update.sig
/stable/workspace-organizer-v1.0.0.zip
```

Feed может переключаться на более новый signed manifest, но ранее опубликованные triples manifest/signature/package должны оставаться byte-identical для reproducibility и расследования incident.

Не размещайте production update-signing private key на update web server. Сервер только распространяет уже подписанные public artifacts.
