# Серверный доступ к обновлениям Notes

Сервис хранит реестр подписанных лицензий и разрешает загрузку официальных
обновлений. Проверка выполняется при **каждом** запросе feed, manifest, подписи
и ZIP, включая прямое обращение к известному имени ZIP. Изменение клиентского
PHP, режима доступа или публичного ключа не даёт доступа к закрытому хранилищу.

`tools/license-server/` — отдельное приложение PHP 8.1+ с sodium, mbstring и
pdo_sqlite, без Composer. Оно не входит в клиентский hosting ZIP: сборка исключает
`tools/`. Клиенту SQLite не требуется.

## Поведение

Начиная с `1.0.2`, обычному пользователю **не требуется** получать отдельный
код активации обновлений, создавать временный файл, выбирать путь для
`update-access.json` или вручную запускать `bin/update_activate.php`.

Штатный сценарий:

1. оператор выпускает installation-bound лицензию `wo1...` и регистрирует её в
   control plane;
2. пользователь активирует эту лицензию в Workspace Organizer;
3. Workspace локально проверяет Ed25519-подпись и привязку к Installation ID;
4. приложение по HTTPS передаёт тот же подписанный лицензионный токен штатному
   control plane только для bootstrap доступа к обновлениям;
5. control plane сверяет точный зарегистрированный токен, повторно проверяет
   подпись, Installation ID, статус и сроки лицензии;
6. сервер выдаёт отдельный случайный installation credential для загрузки
   feed/manifest/signature/package;
7. Workspace автоматически сохраняет credential вне дерева приложения в
   `PRIVATE_STORAGE_PATH/update-access/update-access.json`;
8. дальнейшие запросы используют только этот installation credential.

Приватный ключ лицензирования ни на одном из этих шагов клиенту или control
plane не передаётся.

По умолчанию новая установка получает:

```dotenv
UPDATE_SERVER_URL=https://jsinteractive.ru/api/notes/v1/
UPDATE_FEED_URL=https://jsinteractive.ru/api/notes/v1/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_ACCESS_MODE=auto
UPDATE_CREDENTIALS_FILE=
```

Пустой `UPDATE_CREDENTIALS_FILE` является штатным состоянием. Безопасный путь
выводится из `PRIVATE_STORAGE_PATH`. Явный путь нужен только для нестандартной
схемы хранения и всё равно обязан находиться вне дерева приложения.

Если `.env` старой установки содержит ошибочный путь внутри приложения, `1.0.2`
не требует от пользователя исправлять его вручную: небезопасное значение
игнорируется, а credential сохраняется по штатному внешнему пути.

Если control plane временно недоступен в момент активации лицензии, локальная
лицензия остаётся активной. Bootstrap повторяется при следующей проверке
обновлений.

`UPDATE_ACCESS_MODE=offline` остаётся явным способом полностью отключить сетевой
bootstrap и сохранить офлайн-доставку.

Старый одноразовый `activation code` и `bin/update_activate.php` сохраняются
только для совместимости с `1.0.0/1.0.1` и нестандартных операторских сценариев.
Они не являются частью обычного UX `1.0.2+`.

Статус `revoked`, истёкшая подписанная лицензия или `updates_until` запрещают
выдачу артефактов. Ограничение применяется на стороне control plane при каждом
запросе. Ed25519-подпись manifest, SHA-256, размер ZIP, проверка архива и
внешний staging остаются обязательными после авторизации.

Недоступность control plane влияет только на получение новых обновлений и не
переводит действующую локальную лицензию в read-only.
## Развёртывание сервера поставщика

1. Разместите исходники в `/opt/notes-license`, используйте отдельный домен и
   выделенный аккаунт PHP-FPM. Приложение Notes и его установщик здесь не запускаются.
2. Настройте официальные **публичные** ключи в `config/license_trusted_keys.php`
   и `config/update_trusted_keys.php`. Приватные ключи подписи сервису не нужны.
   При пустом реестре зарегистрировать лицензию или релиз невозможно. Выпуск
   production-ключей остаётся отдельной операцией по существующей процедуре.
3. Создайте `/var/lib/notes-license` с режимом `0700`, владельцем аккаунтом сервиса,
   вне любого document root. SQLite, пакеты и резервные копии хранятся там.
   Исходники должны быть доступны PHP-FPM только для чтения. Команды управления
   выполняйте от аккаунта сервиса.
4. Инициализируйте реестр:

```bash
php tools/license-server/manage.php --db=/var/lib/notes-license/licenses.sqlite --init
```

После инициализации проверьте локальное состояние сервиса:

```bash
php tools/license-server/manage.php \
  --db=/var/lib/notes-license/licenses.sqlite --status --json
```

После запуска HTTPS endpoint доступна безопасная unauthenticated health-проверка:

```text
GET https://updates.example.com/health
```

Она возвращает только состояние registry/license-trust/update-trust и не раскрывает Installation ID, лицензии, credentials, пути к пакетам или содержимое SQLite. Для production monitoring ожидается HTTP 200 + `{"status":"ok",...}`; degraded service отвечает 503.

Все запросы домена направляйте только в `tools/license-server/public/index.php`.
Не добавляйте статический alias к пакетам и не оставляйте публичные копии тех же
пакетов в GitHub Releases, CDN или object storage.

Пример Nginx (замените домен, сертификаты и сокет своего выделенного PHP-FPM pool):

```nginx
# Контекст http:
limit_req_zone $binary_remote_addr zone=notes_licenses:10m rate=5r/s;

server {
    listen 443 ssl;
    server_name updates.example.com;
    ssl_certificate /etc/letsencrypt/live/updates.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/updates.example.com/privkey.pem;
    root /opt/notes-license/tools/license-server/public;
    client_max_body_size 1k;
    gzip off;

    location / {
        limit_req zone=notes_licenses burst=20 nodelay;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/notes-license/tools/license-server/public/index.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param LICENSE_SERVER_DB /var/lib/notes-license/licenses.sqlite;
        fastcgi_param LICENSE_SERVER_BASE_URL https://updates.example.com/;
        fastcgi_pass unix:/run/php/notes-license.sock;
        fastcgi_buffering off;
        fastcgi_cache off;
        fastcgi_read_timeout 300s;
    }
}
```

`HTTPS=on` задаётся доверенной конфигурацией TLS-сервера, не заголовком
пользователя `X-Forwarded-Proto`. HTTP должен отклоняться, а не принимать секреты
с последующим redirect. Клиент использует HTTPS:443, проверку сертификата,
закрепление проверенного публичного IP для TLS и запрет redirects. Ответы имеют
Content-Length и `Cache-Control: no-store`. Не включайте сжатие и кеширование
ответов; транспорт также отклоняет Transfer-Encoding.

## Регистрация лицензии и автоматический bootstrap

Оператор выпускает `wo1...` для конкретного Installation ID. В production
реестр и выдачу update credentials обслуживает `jsint-site`; локальный
`tools/license-server/` сохраняется как совместимый контракт и инструмент
изолированных проверок.

После выпуска лицензии сервер должен хранить:

- полный подписанный `wo1...` токен;
- Installation ID;
- статус лицензии;
- срок доступа к обновлениям;
- при необходимости ограничение максимальной версии.

Для `1.0.2+` клиенту передаётся только обычный лицензионный токен. После его
активации Workspace сам получает update credential через:

```text
POST /api/notes/v1/activate
```

Запрос содержит Installation ID, подписанный `license_token`, текущую версию,
код версии и канал. Сервер принимает bootstrap только если токен в точности
совпадает с зарегистрированным для этой установки и повторно проходит
криптографическую проверку.

Ответ содержит отдельный случайный credential, который клиент сохраняет в
private storage. На сервере хранится только его хеш. Готовый credential
повторно используется и не ротируется на каждой проверке feed.

Старый вариант с одноразовым кодом:

```bash
php bin/update_activate.php ...
```

остаётся только для `1.0.0/1.0.1` и аварийной совместимости. Для пользователя
`1.0.2+` этот шаг в инструкции отсутствует.
## Публикация и отзыв

Подготовьте подписанные артефакты существующими `tools/vendor-update/`, перенесите
их в закрытое хранилище и зарегистрируйте:

```bash
php tools/license-server/manage.php --db=/var/lib/notes-license/licenses.sqlite \
  --publish --manifest=/var/lib/notes-license/releases/update.json \
  --signature=/var/lib/notes-license/releases/update.sig \
  --package=/var/lib/notes-license/releases/notes-1.0.1.zip
```

Имя ZIP должно совпадать с manifest. Версия и имя ZIP уникальны в пределах канала.
Зарегистрированные файлы не меняйте: хеш проверяется при выдаче. Feed формируется
из зарегистрированных релизов с учётом права установки.

Клиент использует `/admin/updates` либо прежние команды:

```bash
php bin/update_remote.php --check-only --json
php bin/update_remote.php --json
```

Отказ 401/403 сообщает о необходимости проверить активацию и право на обновления.
Применение уже скачанного пакета — отдельная существующая операция.

```bash
php tools/license-server/manage.php --db=/var/lib/notes-license/licenses.sqlite \
  --revoke --installation-id=12345678-1234-4234-8234-123456789012
php tools/license-server/manage.php --db=/var/lib/notes-license/licenses.sqlite \
  --restore --installation-id=12345678-1234-4234-8234-123456789012
```

Restore не продлевает сроки и не отменяет ограничение версии. Резервируйте SQLite
через SQLite backup API или при остановленном сервисе, вместе с закрытыми
артефактами и публичными реестрами. Ограничивайте доступ к копиям как к рабочей БД.

## Проверки

`tests/integration/online_update_access_contract.php` проверяет подмену ключа,
привязку, одноразовую активацию, ротацию, сроки, версии, прямые запросы ZIP,
отзыв между проверкой feed и загрузкой, целостность, область передачи секрета и
независимость офлайн-лицензии. Также запускаются прежние контракты подписанной
доставки, сетевых ограничений, интерфейса обновлений и лицензирования.
