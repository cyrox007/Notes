# Messenger WebSocket server — запуск, отдельный WS-узел и эксплуатация

Workspace Organizer 1.0 использует собственный native PHP WebSocket runtime. Сторонний Workerman и Composer `vendor/` для realtime Messenger не требуются. Обычные HTTP-запросы обслуживаются PHP-FPM/Apache, а realtime Messenger — отдельным долгоживущим процессом `ws_server/server.php`.

Поддерживаемый transport-контракт:

- браузер всегда предпочитает WebSocket, если native listener/proxy доступен;
- при недоступном WebSocket клиент автоматически переключается на authenticated same-origin **Long Poll** и продолжает работу без отдельного VPS;
- переключение использует encrypted DB event journal + cursor, поэтому события между WebSocket и Long Poll не расходятся;
- одна installation Workspace Organizer использует не более одного активного native WebSocket process;
- этот process может работать рядом с HTTP-приложением либо на одном отдельном WS-узле;
- несколько одновременно активных WS instances одной installation пока не поддерживаются: connection registry находится в памяти процесса, а cross-node pub/sub/fan-out отсутствует.

## Архитектура

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

TLS завершается на reverse proxy. Внутренний listener использует `stream_socket_server()` + `stream_select()`, собственный RFC6455 handshake/frame codec и общий Messenger transport boundary.

## Требования

- PHP 8.1+
- `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`
- долгоживущий PHP process
- WebSocket reverse proxy для production WSS

Composer install для runtime не нужен.

## Автоматический Long Poll fallback

Long Poll является встроенным compatibility transport, а не отдельной урезанной реализацией Messenger. HTTP fallback и native WebSocket используют один action dispatcher: одинаковые action allowlist, RBAC, anti-impersonation stripping, maintenance и license checks.

Push-события сначала записываются в `messenger_transport_events`, зашифрованные существующим XChaCha20-Poly1305 application crypto boundary, и получают monotonic cursor. WebSocket и Long Poll читают тот же поток. При переключении browser передаёт последний cursor, поэтому событие, возникшее между падением WebSocket и открытием HTTP poll, не теряется. Durable состояние сообщений/диалогов по-прежнему хранится в canonical Messenger tables; transport journal является короткоживущим мостом, а не вторым хранилищем сообщений.

Defaults:

```env
MESSENGER_LONG_POLL_TIMEOUT_SECONDS=20
MESSENGER_EVENT_RETENTION_SECONDS=600
```

Для activity/typing journal TTL принудительно короче, чтобы устаревший статус «печатает…» не воспроизводился после долгого разрыва.

На shared hosting без long-running process или WebSocket reverse proxy Messenger остаётся рабочим через Long Poll. Цена compatibility mode — больше HTTP/DB запросов и немного более высокая задержка по сравнению с WebSocket. Как только WebSocket снова становится доступен, клиент автоматически возвращается на него.

`php bin/ws_doctor.php` остаётся диагностикой именно WebSocket acceleration path: его FAIL означает, что WebSocket transport не готов, но сам Messenger может продолжать работать через Long Poll.

## Штатная topology: WebSocket рядом с приложением

По умолчанию native listener работает на той же машине, что и HTTP-приложение, слушает loopback `127.0.0.1:27800`, а публичный `/ws` проксируется через Nginx/Apache. Это рекомендуемый и самый простой production-режим.

## Один отдельный WebSocket-узел

Допускается вынести realtime Messenger на **одну отдельную машину**. Это не stateless proxy: WS-узел загружает application runtime, проверяет RBAC/module lifecycle и работает с теми же Messenger-данными.

Обязательные условия remote topology:

- HTTP и WS узлы работают на одном release/commit;
- используется одна и та же application MySQL DB;
- `WS_TICKET_SECRET`, `MSG_SECRET_KEY` и installation crypto context совпадают;
- `PRIVATE_STORAGE_PATH/messenger` доступен WS-узлу с теми же данными;
- `UPDATE_STATE_PATH` общий, чтобы WS mutations видели updater maintenance;
- `WS_ALLOWED_ORIGINS` содержит origin HTTP-приложения, а не hostname WS-сервера;
- native listener остаётся loopback/private, наружу публикуется только WSS endpoint;
- PID file должен быть локальным для WS-машины, а не лежать на shared storage.

Пример внешнего endpoint:

```env
SITEURL=https://app.example.com
WS_PUBLIC_URL=wss://ws.example.com/ws
WS_ALLOWED_ORIGINS=https://app.example.com
WS_HOST=127.0.0.1
WS_PORT=27800
WS_PID_FILE=/run/workspace-organizer/ws-server.pid
```

На WS-узле запускайте `php ws_server/server.php check` перед первым стартом и `php bin/ws_doctor.php` после запуска. Несколько WS процессов одной installation нельзя использовать как HA/load-balancing topology до отдельной реализации cross-node fan-out/presence.

## Переменные `.env`

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

`WS_ALLOWED_ORIGINS` содержит browser origins, а не URL-пути. `WS_TICKET_SECRET` должен быть отдельным случайным секретом.

## Управление процессом

Из корня приложения:

```bash
php ws_server/server.php check
php ws_server/server.php start
php ws_server/server.php status
php ws_server/server.php restart
php ws_server/server.php stop
```

`check` выполняет тот же preflight, что и `start`, но не запускает долгоживущий процесс. `start` всегда сначала выполняет preflight и прекращает запуск при любой критичной проблеме.

Startup report показывает фактический CLI PHP binary/version, путь приложения и `.env`, обязательные PHP extensions/socket API, режим foreground/daemon, состояние `WS_TICKET_SECRET` без раскрытия секрета, PID/runtime/log paths, `SITEURL`, browser-facing `WS_PUBLIC_URL`, native `WS_HOST:WS_PORT`, deployment/proxy mode, reverse-proxy mapping, allowed origins, connection/payload limits и результат тестового bind порта. Для каждой критичной ошибки выводятся отдельные строки `[FAIL]` с причиной и `[FIX]` с рекомендуемым действием.

После успешного application bootstrap дополнительно подтверждаются database/module lifecycle и включённый Messenger module. Строка `[RUNNING]` появляется только после успешного реального bind native listener, поэтому означает, что процесс действительно занял указанный адрес и порт.

Пример сокращённого успешного запуска:

```text
[OK] PHP CLI runtime — 8.3.x | binary=/usr/bin/php83 | sapi=cli
[OK] Browser WebSocket URL — wss://workspace.example.com/ws
[OK] Native listener — tcp://127.0.0.1:27800
[OK] Deployment mode — same-origin reverse proxy
[INFO] Reverse proxy — /ws -> http://127.0.0.1:27800
[OK] Allowed WebSocket origins — https://workspace.example.com
[OK] Listener bind test — tcp://127.0.0.1:27800 is available
[OK] Startup preflight — all critical checks passed; starting WebSocket runtime
[OK] Application bootstrap — core runtime loaded; database and persisted module lifecycle initialized
[OK] Messenger module — enabled in the effective runtime composition
[RUNNING] WebSocket server — Native WebSocket listener started: tcp://127.0.0.1:27800; ...
```

При ошибке запуск останавливается до long-running loop, например:

```text
[FAIL] PHP extension sodium — missing from the active CLI PHP binary
[FIX] PHP extension sodium — enable/install sodium for /usr/bin/php81
[FAIL] Startup preflight — 1 critical problem(s) found; WebSocket server was not started
```

На Unix при наличии `pcntl` доступен daemon mode:

```bash
php ws_server/server.php start -d
```

В systemd/Supervisor используйте foreground `start`, а не `-d`.

Диагностика:

```bash
php bin/ws_doctor.php
php bin/healthcheck.php
```

`ws_doctor` проверяет конфигурацию и доступность внутреннего listener. Открытый TCP port сам по себе не доказывает успешную WebSocket авторизацию.

## Security boundary

При WebSocket Upgrade сервер:

1. проверяет `Origin` по `WS_ALLOWED_ORIGINS`;
2. валидирует короткоживущий подписанный `SocketTicket`;
3. проверяет `messenger.use`;
4. повторно проверяет permission на каждом входящем сообщении;
5. принимает только allowlisted Messenger actions;
6. удаляет клиентские `user_uid`, `user_id`, `from_user_id` перед dispatch;
7. ограничивает число соединений и размер WebSocket payload;
8. обслуживает heartbeat и закрывает зависшие соединения.

## Nginx

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

Не публикуйте `27800` в Internet, если reverse proxy работает на том же сервере.

## systemd

```ini
[Unit]
Description=Workspace Organizer native Messenger WebSocket
After=network.target mysql.service

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

После deploy кода, затрагивающего `app/socket`, Messenger services или ticket validation:

```bash
sudo systemctl restart workspace-messenger
php bin/ws_doctor.php
php bin/healthcheck.php
```

## Supervisor

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

## Shared hosting / Open Server

WebSocket остаётся предпочтительным transport: при его наличии нужен отдельный PHP process и browser WebSocket endpoint. На том же сервере это обычно reverse proxy `/ws` → `WS_PORT`; альтернативой может быть один отдельный WS-узел по контракту выше. Если hosting не поддерживает long-running PHP process/WebSocket Upgrade, **отдельный VPS не обязателен**: Messenger автоматически работает через встроенный Long Poll compatibility mode.

Для Open Server используйте `docs/OPEN_SERVER_WEBSOCKET.md`.

## Проверка после deploy

1. `php bin/healthcheck.php`
2. `php ws_server/server.php status`
3. `php bin/ws_doctor.php`
4. проверить конфигурацию Nginx/Apache;
5. открыть Messenger двумя пользователями;
6. в DevTools → Network → WS увидеть `101 Switching Protocols`;
7. отправить сообщение и убедиться, что второй browser context получает его без reload.

Repository CI выполняет аналогичный production-like Chromium smoke через TLS Nginx + PHP + **native WebSocket server**, причём runtime проверяется без каталога `vendor/`.

## Частые проблемы

### Connection refused
Проверьте native process, `WS_HOST`, `WS_PORT`, firewall и reverse proxy backend.

### WebSocket сразу закрывается
Проверьте `WS_ALLOWED_ORIGINS`, `SITEURL`, `BASE_PATH`, `WS_PUBLIC_URL`, общий `WS_TICKET_SECRET`, статус/роль пользователя и системное время.

### 502 Bad Gateway на `/ws`
Reverse proxy не может подключиться к listener либо native process остановлен. Проверяйте `ws_server/server.php status`, `ws_doctor`, web-server error log и `LOG_FILE`.

### Соединение есть, realtime не работает
Проверьте browser WS frames и наличие `Authorized`. Сервер принимает только allowlisted actions.

## Нельзя

- открывать внутренний WS port публично вместо WSS proxy;
- отключать Origin/ticket/RBAC checks;
- запускать process от root;
- хранить секреты в unit-файле/репозитории;
- считать открытый TCP port доказательством успешной Messenger авторизации.
