# WebSocket-сервер Messenger — запуск, перенос на отдельный сервер и эксплуатация

Workspace Organizer использует собственный native PHP WebSocket runtime. Сторонний Workerman и Composer `vendor/` для работы realtime Messenger не требуются. Обычные HTTP-запросы обслуживаются PHP-FPM/Apache, а realtime Messenger — отдельным долгоживущим процессом `ws_server/server.php`.

Поддерживаемый production-контракт на текущий момент:

- **одна установка Workspace Organizer + один активный WebSocket process**;
- WebSocket process может работать **на том же сервере**, что и HTTP-приложение, либо **на отдельном сервере**;
- несколько одновременно активных WebSocket экземпляров для одной установки пока **не считаются поддерживаемой production-топологией**. Причина и требования для будущего multi-instance режима описаны ниже.

## 1. Как работает realtime Messenger

HTTP-приложение выдаёт браузеру короткоживущий подписанный WebSocket ticket. Браузер открывает соединение с `WS_PUBLIC_URL`, передавая ticket. Native WebSocket server:

1. проверяет browser `Origin` по `WS_ALLOWED_ORIGINS`;
2. валидирует ticket через общий `WS_TICKET_SECRET`;
3. получает пользователя из общей MySQL БД;
4. проверяет `messenger.use` и остальные RBAC/module policies;
5. выполняет разрешённые Messenger actions;
6. читает/изменяет те же Messenger данные в MySQL;
7. для Messenger media проверяет файл в `PRIVATE_STORAGE_PATH/messenger`;
8. для mutating actions повторно учитывает maintenance/license runtime policy.

`WS_TICKET_SECRET` не передаётся браузеру. В браузер уходит только короткоживущий ticket.

### Требования к WS runtime

- PHP 8.1+; для production рекомендуется поддерживаемая ветка PHP, сейчас 8.3+;
- extensions `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`;
- PHP CLI и возможность держать долгоживущий process;
- доступ к application MySQL;
- корректные Messenger/application secrets;
- WSS endpoint через reverse proxy/TLS для публичного production;
- доступ к private/runtime state согласно выбранной topology.

Composer install для runtime не нужен.

## 2. Штатный режим: WebSocket server на той же машине

Рекомендуемая production-схема по умолчанию:

```text
Browser
  |
  | HTTPS / WSS
  v
Nginx / Apache reverse proxy
  |                     |
  | HTTP/FastCGI        | /ws + Upgrade
  v                     v
PHP-FPM / Apache        Native PHP WebSocket server
                        127.0.0.1:27800
  |                     |
  +----------+----------+
             |
      MySQL + private storage
```

TLS завершается на Nginx/Apache. Native listener остаётся локальным и не публикуется напрямую в Internet.

Типичный `.env`:

```env
SITEURL=https://workspace.example.com
BASE_PATH=/
WS_HOST=127.0.0.1
WS_PORT=27800
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
WS_TICKET_SECRET=<random-at-least-32-chars>
WS_MAX_CONNECTIONS=256
WS_MAX_PAYLOAD_BYTES=2097152
```

Запуск из корня приложения:

```bash
php ws_server/server.php start
```

Проверка и управление:

```bash
php ws_server/server.php status
php bin/ws_doctor.php
php ws_server/server.php restart
php ws_server/server.php stop
```

На Unix при наличии `pcntl` доступен daemon mode:

```bash
php ws_server/server.php start -d
```

В systemd/Supervisor используйте foreground `start`, а не `-d`.

### Nginx

```nginx
location /ws {
    proxy_pass http://127.0.0.1:27800;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $http_host;
    proxy_set_header Origin $http_origin;
    proxy_read_timeout 60s;
}
```

### Apache 2.4.47+

```apache
ProxyPreserveHost On
ProxyPass "/ws" "http://127.0.0.1:27800/" upgrade=websocket
ProxyPassReverse "/ws" "http://127.0.0.1:27800/"
```

Не публикуйте `27800` в Internet, если reverse proxy находится на той же машине.

## 3. Вынос WebSocket server на отдельную машину

Отдельный WebSocket сервер поддерживается как **один удалённый realtime-узел для одной установки**.

Рекомендуемая схема:

```text
                         +----------------------+
Browser -- HTTPS ------> | app.example.com      |
                         | HTTP application      |
                         +----------+-----------+
                                    |
                                    +--------------------+
                                    |                    |
                                    v                    v
                                  MySQL         shared private storage
                                    ^                    ^
                                    |                    |
                         +----------+--------------------+
Browser -- WSS --------> | ws.example.com               |
                         | TLS reverse proxy             |
                         |        |                      |
                         |        v                      |
                         | 127.0.0.1:27800               |
                         | Native PHP WS process         |
                         +-------------------------------+
```

Публичный HTTP-сайт и публичный WSS endpoint могут иметь разные hostnames. При этом `WS_ALLOWED_ORIGINS` содержит **origin сайта**, а не hostname WebSocket-сервера.

Пример для HTTP/Web сервера:

```env
SITEURL=https://app.example.com
BASE_PATH=/
WS_PUBLIC_URL=wss://ws.example.com/ws
WS_ALLOWED_ORIGINS=https://app.example.com
WS_TICKET_SECRET=<same-shared-secret>
```

На отдельном WS-сервере используется тот же release Workspace Organizer и отдельный защищённый `.env` с теми же installation/runtime параметрами, которые нужны Messenger:

```env
SITEURL=https://app.example.com
BASE_PATH=/

DBHOST=<same-mysql-host>
DBPORT=3306
DBUSER=<same-application-db-user>
DBPASS=<same-application-db-password>
DBNAME=<same-database>

MSG_SECRET_KEY=<same-messenger-data-key>
UNIQUE_KEY=<same-installation-key>
WS_TICKET_SECRET=<same-shared-secret>

WS_HOST=127.0.0.1
WS_PORT=27800
WS_PUBLIC_URL=wss://ws.example.com/ws
WS_ALLOWED_ORIGINS=https://app.example.com
WS_MAX_CONNECTIONS=256
WS_MAX_PAYLOAD_BYTES=2097152
# PID должен быть локальным для WS-машины, а не лежать на shared PRIVATE_STORAGE_PATH.
WS_PID_FILE=/run/workspace-organizer/ws-server.pid

PRIVATE_STORAGE_PATH=/srv/workspace-private
UPDATE_STATE_PATH=/srv/workspace-shared/update-state
```

Не копируйте `.env` через публичные артефакты или Git. Секреты передаются на WS-узел через ваш защищённый deployment/secret-management канал.

Для remote topology задайте `WS_PID_FILE` в **локальном runtime-каталоге WS-узла**. По умолчанию PID file может попадать под `PRIVATE_STORAGE_PATH/runtime`; если `PRIVATE_STORAGE_PATH` общий между машинами, такой PID file тоже станет общим. PID процесса не является cluster state и не должен шариться между хостами.

Пример:

```bash
sudo install -d -o www-data -g www-data -m 0750 /run/workspace-organizer
```

```env
WS_PID_FILE=/run/workspace-organizer/ws-server.pid
```

### 3.1. Что обязательно должно быть общим

#### Одинаковая MySQL БД

Remote WS process не является простым socket relay. Он загружает application runtime и обращается к тем же таблицам пользователей, Messenger, RBAC, module lifecycle, settings и лицензирования.

WS-узел должен подключаться к **той же БД**, что и HTTP-приложение.

#### Одинаковый `WS_TICKET_SECRET`

HTTP-приложение подписывает ticket, а WebSocket server его проверяет. Значения должны совпадать byte-for-byte.

При ротации `WS_TICKET_SECRET` обновите secret на HTTP и WS узлах и перезапустите оба runtime. Уже выданные старые tickets после ротации перестанут приниматься — это ожидаемо.

#### Одинаковый `MSG_SECRET_KEY`

WebSocket handlers читают и создают зашифрованные Messenger messages. Другой `MSG_SECRET_KEY` сделает Messenger данные недоступными/несовместимыми.

Не меняйте `MSG_SECRET_KEY` вручную; используйте штатную процедуру key rotation.

#### Одинаковая версия приложения

HTTP и WS узлы должны работать на **одном release/commit**. Version skew не считается поддерживаемым режимом.

Причина: WS process загружает те же Messenger services, models, module manifests, RBAC и schema assumptions. После миграции БД старый WS-код может стать несовместимым с новой схемой/логикой.

После deploy версии, затрагивающей Messenger/runtime:

1. удерживайте maintenance;
2. обновите HTTP application;
3. обновите код удалённого WS-узла до того же release;
4. перезапустите WS process;
5. выполните health/browser smoke;
6. только после проверки возвращайте installation в обычный режим.

#### Общий Messenger private storage

Messenger attachment сначала загружается HTTP-приложением, а WebSocket action `MediaSocket::send` затем проверяет физическое наличие файла.

В текущем формате БД `messenger_attachments.stored_path` содержит physical path. Поэтому при выносе WS-сервера:

- `PRIVATE_STORAGE_PATH/messenger` должен быть доступен WS-узлу;
- для текущего контракта shared storage должен быть смонтирован **под тем же абсолютным путём** на обоих узлах;
- путь должен оставаться вне document root;
- текущий полный runtime/`healthcheck.php` ожидает writable `PRIVATE_STORAGE_PATH`, поэтому поддерживаемый профиль — общий private storage, доступный WS service account на запись; не пытайтесь обходить это read-only mount без отдельной доработки;
- HTTP application сохраняет обычные права на запись upload bytes.

Пример:

```text
HTTP node: /srv/workspace-private/messenger
WS node:   /srv/workspace-private/messenger
```

Если storage на двух машинах доступен только под разными absolute paths, текущий media contract требует отдельной доработки и не должен считаться поддерживаемым remote-WS deployment.

#### Общий maintenance state

WebSocket mutating actions проверяют `MaintenanceModeService`. Если HTTP/updater и WS читают разные локальные maintenance markers, можно получить опасное расхождение:

```text
HTTP node: maintenance = ON
WS node:   maintenance = OFF
```

Поэтому для remote WS topology `UPDATE_STATE_PATH` должен указывать на **одно общее внешнее state-хранилище**, доступное HTTP/updater и WS-узлу под согласованным путём.

Хранилище должно поддерживать используемые приложением file operations/locking. Не размещайте maintenance state внутри application tree.

### 3.2. Сетевой доступ

Разрешайте от WS-узла только необходимые исходящие соединения:

- MySQL к application database;
- shared private/update-state storage;
- инфраструктурные зависимости, которые явно нужны вашей установке.

Не открывайте MySQL всему Internet. Ограничьте доступ firewall/security-group правилами конкретным WS-узлом или private network/VPN.

### 3.3. TLS и reverse proxy на отдельном WS-сервере

При `WS_PUBLIC_URL=wss://ws.example.com/ws` на WS-машине должен быть TLS reverse proxy:

```nginx
server {
    listen 443 ssl;
    server_name ws.example.com;

    # ssl_certificate /...;
    # ssl_certificate_key /...;

    location /ws {
        proxy_pass http://127.0.0.1:27800;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $http_host;
        proxy_set_header Origin $http_origin;
        proxy_read_timeout 60s;
    }
}
```

Native PHP listener остаётся:

```env
WS_HOST=127.0.0.1
WS_PORT=27800
```

Таким образом наружу публикуется только `443/tcp`, а `27800` остаётся loopback.

### 3.4. Альтернатива: same-origin proxy на основном web host

Можно сохранить browser endpoint:

```text
wss://app.example.com/ws
```

и на основном reverse proxy направить `/ws` по private network на отдельный WS-узел.

В таком варианте native listener на WS-узле должен слушать конкретный private address, доступный только reverse proxy, например:

```env
WS_HOST=10.20.0.15
WS_PORT=27800
WS_PUBLIC_URL=wss://app.example.com/ws
WS_ALLOWED_ORIGINS=https://app.example.com
```

Firewall должен разрешать `10.20.0.15:27800` только от reverse-proxy host. Не используйте `0.0.0.0` без необходимости.

Для простоты эксплуатации отдельный `wss://ws.example.com/ws` с локальным TLS proxy на WS-машине обычно понятнее.

## 4. Установка и запуск отдельного WS-узла

1. Разверните **тот же release ZIP/commit**, что работает на основном приложении.
2. Не запускайте web installer на отдельной БД и не создавайте вторую installation.
3. Создайте защищённый `.env`, указывающий на существующую application DB.
4. Передайте на узел те же необходимые application secrets.
5. Смонтируйте shared `PRIVATE_STORAGE_PATH` под тем же absolute path.
6. Смонтируйте/настройте общий `UPDATE_STATE_PATH`.
7. Задайте локальный `WS_PID_FILE`, не расположенный на shared storage.
8. Настройте `WS_PUBLIC_URL` и `WS_ALLOWED_ORIGINS`.
9. Настройте TLS reverse proxy.
10. Запустите native process через systemd/Supervisor.
11. Проверьте локальный runtime и затем browser end-to-end.

Проверка на **WS-узле**:

```bash
php bin/healthcheck.php
php ws_server/server.php status
php bin/ws_doctor.php
```

Важно: `ws_doctor` проверяет listener той машины, на которой команда запущена. При remote topology его следует запускать на WS-узле. На основном HTTP-узле отсутствие локального listener является нормальным, если browser использует внешний `WS_PUBLIC_URL`.

После этого проверьте извне:

1. открыть Messenger двумя пользователями;
2. DevTools → Network → WS;
3. увидеть `101 Switching Protocols`;
4. получить прикладное сообщение `Authorized`;
5. отправить обычное текстовое сообщение;
6. проверить realtime получение вторым пользователем без reload;
7. отправить attachment и убедиться, что media send проходит;
8. кратковременно включить maintenance в контролируемой среде и убедиться, что WS mutation блокируется.

## 5. systemd

Пример для локального или отдельного WS-узла:

```ini
[Unit]
Description=Workspace Organizer native Messenger WebSocket
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/workspace-organizer
ExecStart=/usr/bin/php /var/www/workspace-organizer/ws_server/server.php start
ExecStop=/usr/bin/php /var/www/workspace-organizer/ws_server/server.php stop
Restart=on-failure
RestartSec=3
TimeoutStopSec=20

[Install]
WantedBy=multi-user.target
```

Если MySQL расположен на другой машине, не указывайте локальный `mysql.service` в `After=`; готовность DB проверяется application health/smoke.

После deploy кода, затрагивающего Messenger services, socket handlers, ticket validation или module/runtime policy:

```bash
sudo systemctl restart workspace-messenger
php bin/ws_doctor.php
php bin/healthcheck.php
```

## 6. Supervisor

```ini
[program:workspace-messenger]
command=/usr/bin/php /var/www/workspace-organizer/ws_server/server.php start
directory=/var/www/workspace-organizer
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
```

## 7. Можно ли запускать несколько экземпляров

### Технически

Один и тот же `WS_HOST:WS_PORT` второй process занять не сможет. `server.php` также использует PID file как дополнительную защиту от повторного штатного запуска.

Можно искусственно запустить несколько процессов на разных портах/PID files, но это **не делает Messenger горизонтально масштабируемым**.

### Почему multi-instance сейчас не поддерживается

Активные browser connections и presence хранятся в памяти конкретного PHP process:

```text
$clients
$connections[user_uid]
```

Realtime broadcast отправляется только соединениям, которые находятся в памяти этого процесса.

Если:

```text
User A -> ws-1
User B -> ws-2
```

то запись сообщения в общей MySQL БД сохранится, но `ws-1` не знает о live connection пользователя B на `ws-2` и не сможет выполнить мгновенный cross-node broadcast.

Sticky sessions проблему не решают: они удерживают одного клиента на одном узле, но не создают fan-out между разными узлами.

Поэтому production-правило сейчас:

> **Для одной installation запускайте ровно один активный native WebSocket instance.**

### Что потребуется для отдельной задачи multi-instance WS

Для полноценного горизонтального масштабирования потребуется отдельная разработка, как минимум:

- общий pub/sub/event bus между WS-узлами (например Redis/NATS или другой выбранный backend);
- cross-node fan-out Messenger events;
- distributed/central presence registry либо корректная модель presence events;
- правила deduplication/event identity;
- load balancer и проверенный reconnect behavior;
- shared maintenance coordination;
- health/readiness для каждого WS node;
- тесты на пользователей, подключённых к разным WS instances;
- отдельный deploy/update contract без опасного version skew.

До реализации и тестирования этого контракта несколько WS processes не следует использовать как HA/load-balancing решение.

## 8. Security boundary

При WebSocket Upgrade сервер:

1. проверяет `Origin` по `WS_ALLOWED_ORIGINS`;
2. валидирует короткоживущий подписанный `SocketTicket`;
3. проверяет `messenger.use`;
4. повторно проверяет permission/runtime mutation policy на входящих actions;
5. принимает только allowlisted Messenger actions;
6. не доверяет identity, присланной клиентом;
7. ограничивает число соединений и размер WebSocket payload;
8. обслуживает heartbeat и закрывает зависшие соединения.

Для remote topology дополнительно:

- не публикуйте `.env`, private storage и update state;
- держите `WS_PID_FILE` локальным для конкретного WS-узла;
- не открывайте DB всему Internet;
- используйте WSS;
- ограничьте native WS port loopback/private firewall scope;
- `WS_ALLOWED_ORIGINS` должен содержать только реальные доверенные web origins;
- не используйте разные `WS_TICKET_SECRET`/`MSG_SECRET_KEY` для HTTP и WS частей одной installation.

## 9. Shared hosting / Open Server

Realtime Messenger требует возможность держать отдельный PHP process и принимать WebSocket Upgrade.

Если hosting этого не поддерживает, Notes/Tasks/Files/Profile продолжают работать, но realtime Messenger на таком тарифе корректно развернуть нельзя.

Для Open Server/OSPanel используйте `docs/OPEN_SERVER_WEBSOCKET.md`.

## 10. Проверка после deploy

Для локального same-host режима:

```bash
php bin/healthcheck.php
php ws_server/server.php status
php bin/ws_doctor.php
```

Для remote WS режима:

**HTTP node**

```bash
php bin/healthcheck.php
```

Проверить, что `WS_PUBLIC_URL` указывает на внешний WSS endpoint.

**WS node**

```bash
php bin/healthcheck.php
php ws_server/server.php status
php bin/ws_doctor.php
```

**Browser smoke**

1. login;
2. открыть Messenger двумя пользователями;
3. `101 Switching Protocols`;
4. `Authorized`;
5. text send/receive без reload;
6. typing/activity;
7. attachment send/receive;
8. reconnect после controlled restart WS process.

Открытый TCP port сам по себе не доказывает успешную WebSocket авторизацию.

## 11. Частые проблемы

### Ошибка Connection refused

Проверьте native process, `WS_HOST`, `WS_PORT`, firewall и reverse proxy backend.

### WebSocket-соединение сразу закрывается

Проверьте:

- `WS_ALLOWED_ORIGINS`;
- `SITEURL`;
- `BASE_PATH`;
- `WS_PUBLIC_URL`;
- одинаковый `WS_TICKET_SECRET`;
- доступ WS-узла к общей DB;
- статус/роль пользователя;
- системное время на HTTP и WS узлах.

### 502 Bad Gateway на `/ws`

Reverse proxy не может подключиться к listener либо native process остановлен. Проверяйте `ws_server/server.php status`, `ws_doctor`, web-server error log и `LOG_FILE`.

### Авторизация проходит, но сообщения не читаются/не отправляются

Проверьте одинаковый `MSG_SECRET_KEY`, общую DB, module lifecycle и соответствие release/commit между HTTP и WS узлами.

### Текст работает, attachment send падает

Проверьте:

- одинаковый/shared `PRIVATE_STORAGE_PATH/messenger`;
- одинаковый absolute mount path на HTTP и WS узлах;
- права WS service account;
- что путь из `messenger_attachments.stored_path` реально существует на WS-узле.

### Во время update WS продолжает принимать изменения

Remote topology настроена неправильно. HTTP/updater и WS должны видеть один и тот же maintenance marker через общий `UPDATE_STATE_PATH`.

### Один пользователь получает realtime, другой нет при нескольких WS nodes

Это ожидаемое ограничение текущего single-instance runtime. Несколько активных WS экземпляров для одной installation пока не поддерживаются.

## 12. Нельзя

- запускать несколько WS instances и считать их HA/load-balanced кластером;
- открывать внутренний WS port всему Internet вместо WSS proxy;
- отключать Origin/ticket/RBAC checks;
- запускать process от root;
- хранить секреты в unit-файле, репозитории или release ZIP;
- использовать на WS-узле другую application DB;
- использовать другой `WS_TICKET_SECRET` или `MSG_SECRET_KEY`;
- оставлять remote WS node на старой версии после DB/application upgrade;
- использовать локальный отдельный maintenance marker на remote WS node;
- считать открытый TCP port доказательством успешной Messenger авторизации.