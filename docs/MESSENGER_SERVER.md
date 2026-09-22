# Messenger WebSocket server — запуск, отдельный WS-узел и эксплуатация

Workspace Organizer 1.0 использует WebSocket-first realtime transport: собственный native PHP WebSocket runtime является основным низколатентным каналом, а authenticated HTTP long poll — автоматическим резервным каналом. Сторонний Workerman и Composer `vendor/` для realtime Messenger не требуются. Обычные HTTP-запросы и fallback обслуживаются PHP-FPM/Apache, а ускоренный realtime — отдельным долгоживущим процессом `ws_server/server.php`.

Поддерживаемый production-контракт:

- при включённом WebSocket одна installation Workspace Organizer использует один активный WebSocket process;
- этот process может работать рядом с HTTP-приложением либо на одном отдельном WS-узле;
- при недоступном WebSocket Messenger автоматически продолжает durable realtime через HTTP long poll и параллельно пытается восстановить WS;
- durable mutations из HTTP fallback публикуют shared DB realtime revision, поэтому активные WS-клиенты получают `sync_required` и перечитывают canonical state без reconnect;
- несколько одновременно активных WS instances одной installation пока не поддерживаются: connection registry находится в памяти процесса, а полноценный multi-node pub/sub/presence отсутствует.

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

TLS завершается на reverse proxy. Внутренний listener использует `stream_socket_server()` + `stream_select()`, собственный RFC6455 handshake/frame codec и общий Messenger transport boundary. HTTP fallback входит в тот же transport boundary: `/messenger/realtime/action` dispatch-ит те же allowlisted Messenger actions, а `/messenger/realtime/poll` ждёт изменения durable state и возвращает canonical snapshot.

## Требования

- PHP 8.1+
- `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`
- обычные authenticated HTTP-запросы с возможностью long-poll ожидания 5–25 секунд и достаточной параллельностью PHP workers;
- для рекомендуемого WebSocket fast path — долгоживущий PHP process и WebSocket reverse proxy для production WSS.

Composer install для runtime не нужен.

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

На WS-узле запускайте `php ws_server/server.php check` перед первым стартом и `php bin/ws_doctor.php` после запуска. HTTP-приложение и отдельный WS-узел должны видеть одну application DB: она используется не только Messenger-данными, но и realtime revision bridge для уведомления WS-клиентов о durable mutations из HTTP fallback. Несколько WS процессов одной installation нельзя использовать как HA/load-balancing topology до отдельной реализации cross-node fan-out/presence.

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

Отчёт запуска показывает фактический CLI PHP binary/version, путь приложения и `.env`, обязательные PHP extensions/socket API, режим foreground/daemon, состояние `WS_TICKET_SECRET` без раскрытия секрета, PID/runtime/log paths, `SITEURL`, публичный `WS_PUBLIC_URL`, внутренний `WS_HOST:WS_PORT`, режим развёртывания/proxy, mapping reverse proxy, разрешённые origins, лимиты соединений/payload и результат тестовой привязки порта. Для каждой критичной ошибки выводятся отдельные строки `[FAIL]` с причиной и `[FIX]` с рекомендуемым действием.

После успешного application bootstrap дополнительно подтверждаются database/module lifecycle и включённый Messenger module. Строка `[RUNNING]` появляется только после успешного реального bind native listener, поэтому означает, что процесс действительно занял указанный адрес и порт.

Пример сокращённого успешного запуска:

```text
[OK] Среда PHP CLI — 8.3.x | binary=/usr/bin/php83 | sapi=cli
[OK] WebSocket URL для браузера — wss://workspace.example.com/ws
[OK] Внутренний listener — tcp://127.0.0.1:27800
[OK] Режим развёртывания — reverse proxy в рамках того же origin
[INFO] Reverse proxy — /ws -> http://127.0.0.1:27800
[OK] Разрешённые WebSocket origin — https://workspace.example.com
[OK] Проверка привязки listener — tcp://127.0.0.1:27800 доступен
[OK] Предварительная проверка запуска — все критические проверки пройдены; запускается WebSocket runtime
[OK] Инициализация приложения — core runtime загружен; база данных и сохранённое состояние модулей инициализированы
[OK] Модуль Messenger — включён в текущей runtime-конфигурации
[RUNNING] WebSocket-сервер — Внутренний WebSocket listener запущен: tcp://127.0.0.1:27800; ...
```

При ошибке запуск останавливается до long-running loop, например:

```text
[FAIL] Расширение PHP sodium — отсутствует в активном CLI-интерпретаторе PHP
[FIX] Расширение PHP sodium — включите/установите sodium для /usr/bin/php81
[FAIL] Предварительная проверка запуска — обнаружено критических проблем: 1; WebSocket-сервер не запущен
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

Оба транспорта используют один server-side Messenger authorization/dispatch boundary. HTTP fallback аутентифицируется обычной application session и проходит тот же action allow-list, permission checks, maintenance state и license read-only policy, что и WebSocket dispatcher.

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

Messenger остаётся работоспособным без long-running WebSocket process: browser автоматически переходит на authenticated HTTP long poll. WebSocket на том же сервере (обычно reverse proxy `/ws` → `WS_PORT`) или один отдельный WS-узел по контракту выше рекомендуются как fast path, потому что уменьшают задержку, число HTTP-запросов и занятость PHP workers.

Для fallback shared hosting должен разрешать обычные длительные HTTP requests и иметь достаточную параллельность PHP/FPM. Клиент освобождает PHP session lock на long-poll request, прерывает текущий poll перед собственным mutating HTTP action или refresh socket ticket и затем возобновляет ожидание. Значение `MESSENGER_LONG_POLL_TIMEOUT_SECONDS` по умолчанию равно 15 секундам и ограничивается диапазоном 5–25.

Ephemeral typing/activity остаются WebSocket enhancement; сообщения, диалоги, read/delivery state, reactions и другие durable изменения синхронизируются через fallback. После восстановления WebSocket клиент получает свежий ticket, проходит `Authorized`, отменяет long poll и бесшовно возвращается на основной канал.

Для Open Server используйте `docs/OPEN_SERVER_WEBSOCKET.md`.

## Проверка после deploy

1. `php bin/healthcheck.php`
2. `php ws_server/server.php status`
3. `php bin/ws_doctor.php`
4. проверить конфигурацию Nginx/Apache;
5. открыть Messenger двумя пользователями;
6. при доступном WebSocket в DevTools → Network → WS увидеть `101 Switching Protocols` и состояние «WebSocket · в сети»;
7. отправить сообщение и убедиться, что второй browser context получает его без reload;
8. временно остановить WS process или сделать endpoint недоступным, дождаться состояния «Long Poll · резервный канал», повторить отправку между двумя пользователями и убедиться, что durable state синхронизируется;
9. вернуть WS process и убедиться, что клиент автоматически возвращается на WebSocket без reload.

Repository CI выполняет production-like Chromium smoke через TLS Nginx + PHP + **native WebSocket server**, проверяет reconnect, HTTP fallback/worker-release и bridge fallback-mutation → активный WS-клиент; runtime проверяется без каталога `vendor/`.

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
