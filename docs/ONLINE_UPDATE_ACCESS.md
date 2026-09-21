# Серверный доступ к обновлениям Notes

Сервис хранит реестр подписанных лицензий и разрешает загрузку официальных
обновлений. Проверка выполняется при **каждом** запросе feed, manifest, подписи
и ZIP, включая прямое обращение к известному имени ZIP. Изменение клиентского
PHP, режима доступа или публичного ключа не даёт доступа к закрытому хранилищу.

`tools/license-server/` — отдельное приложение PHP 8.1+ с sodium, mbstring и
pdo_sqlite, без Composer. Оно не входит в клиентский hosting ZIP: сборка исключает
`tools/`. Клиенту SQLite не требуется.

## Поведение

- `UPDATE_ACCESS_MODE=offline` сохраняет прежнюю подписанную доставку, включая
  ручной перенос. Закрытый сервер отказывает запросам без учётных данных независимо
  от режима, выставленного клиентом.
- `online` требует секрет установки в файле `0600` вне дерева приложения.
  Одноразовый код обменивается на секрет по HTTPS. Секрет не попадает в URL,
  вывод CLI или HTML. Сервер хранит только хеши кода и секрета, саму подписанную
  лицензию и её право на обновления. Одного Installation ID для активации мало.
- Статус revoked, истёкшая подписанная лицензия или `updates-until` запрещают
  выдачу артефактов. Срок права сравнивается с текущим временем сервера: после
  его окончания запрещена также загрузка старых версий. `max-version` ограничивает
  и feed, и прямую загрузку конкретного релиза.
- Недоступность сервиса блокирует только получение обновлений. Нет сетевой
  проверки при открытии заметок, изменения локальной лицензии, режима read-only
  или пользовательских данных по результату запроса обновлений. Прежняя проверка
  срока подписанной runtime-лицензии продолжает действовать локально.
- Ed25519-подпись manifest, SHA-256 и размер ZIP, проверка архива и staging
  остаются обязательными после авторизации.
- Отзыв действует со следующего запроса. Уже начатую передачу и скачанные пакеты
  вернуть невозможно. Секрет и Installation ID можно скопировать вместе с
  установкой: они не доказывают идентичность физического сервера.

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

## Регистрация и активация установки

Выпустите `wo1...` лицензию для Installation ID по `LICENSE_ISSUANCE.md`.
Передайте её клиенту для существующей локальной активации. Копию файла
зарегистрируйте на сервере поставщика:

```bash
php tools/license-server/manage.php \
  --db=/var/lib/notes-license/licenses.sqlite --register \
  --installation-id=12345678-1234-4234-8234-123456789012 \
  --license-file=/var/lib/notes-license/customer-license.txt \
  --activation-out=/var/lib/notes-license/customer.activation \
  --updates-until=1798761599 --max-version=10001
```

Ограничения необязательны: без них право бессрочное и на все версии, пока действует
подписанная лицензия и статус active. Команда не выводит секрет и не перезаписывает
выходной файл. Повторная регистрация обновляет условия, возвращает active и
**отзывает прежний секрет**, создавая новый код. Это также способ заменить
потерянные или утёкшие учётные данные.

Передайте файл активации администратору по защищённому каналу. На клиенте, от
аккаунта, читающего приватные файлы Notes:

```bash
php bin/update_activate.php \
  --server=https://updates.example.com/ \
  --installation-id=12345678-1234-4234-8234-123456789012 \
  --activation-file=/var/lib/notes/private/customer.activation \
  --credentials-out=/var/lib/notes/private/update-access.json
```

Installation ID берётся с `/admin/license`. Удалите использованный код и
настройте `.env`:

```dotenv
UPDATE_ACCESS_MODE=online
UPDATE_CREDENTIALS_FILE=/var/lib/notes/private/update-access.json
UPDATE_FEED_URL=https://updates.example.com/stable/feed.json
UPDATE_CHANNEL=stable
```

Храните секрет вне document root и общих резервных копий. Веб-интерфейс сверяет
ID в файле с БД Notes. CLI доставки использует ID файла, сохраняя независимость
от БД. Секрет отправляется только на активированный origin и префикс доставки.
При потере ответа на одноразовую активацию запросите новый код. Автоматического
перехода к публичной загрузке при ошибке нет.

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
