# Messenger WebSocket server — запуск и эксплуатация

Workspace Organizer 1.0 использует собственный PHP WebSocket runtime. Сторонний Workerman и Composer `vendor/` для работы приложения не требуются. Обычные HTTP-запросы обслуживаются PHP-FPM/Apache, а realtime Messenger — отдельным долгоживущим процессом `ws_server/server.php`.

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
php ws_server/server.php start
php ws_server/server.php status
php ws_server/server.php restart
php ws_server/server.php stop
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

Realtime Messenger требует возможность держать отдельный PHP process и проксировать `/ws` на `WS_PORT`. Если hosting этого не поддерживает, Notes/Tasks/Files/Profile продолжают работать, но realtime Messenger корректно запустить нельзя.

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
