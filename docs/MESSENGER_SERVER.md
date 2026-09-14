# Messenger WebSocket server — запуск и эксплуатация

Workspace Organizer использует отдельный долгоживущий **Workerman**-процесс для realtime Messenger. Обычного PHP-FPM/Apache недостаточно: web-приложение выдаёт короткоживущий WebSocket ticket, а браузер затем подключается к отдельному Workerman listener.

## 1. Архитектура

```text
Browser
  |
  | HTTPS / WSS
  v
Nginx / reverse proxy
  |                     |
  | HTTP/FastCGI        | /ws + Upgrade
  v                     v
PHP-FPM / Apache        Workerman
                        127.0.0.1:27800
  |                     |
  +----------+----------+
             |
      MySQL + private storage
```

Workerman entrypoint проекта:

```text
ws_server/server.php
```

Он создаёт listener из `WS_HOST` + `WS_PORT`, использует `Workerman\Protocols\Websocket`, проверяет `Origin`, валидирует `WS_TICKET_SECRET`, повторно проверяет активность/роль пользователя и только после этого разрешает Messenger actions.

## 2. Зависимости

В production bundle `vendor/` уже включён. При установке из Git checkout выполните:

```bash
composer install --no-dev --optimize-autoloader
```

В `composer.json` проект использует `workerman/workerman ^4.1`.

Проверка PHP:

```bash
php -v
php -m | grep -E 'mysqli|pdo_mysql|mbstring|json|fileinfo|sodium'
```

## 3. Обязательные переменные `.env`

Минимальный production-пример:

```env
SITEURL=https://workspace.example.com
BASE_PATH=/

WS_HOST=127.0.0.1
WS_PORT=27800
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
WS_TICKET_SECRET=<random-at-least-32-chars>

LOG_LEVEL=INFO
LOG_FILE=/var/log/workspace-organizer/app.log
```

Для установки в подкаталог, например `/workspace/`:

```env
SITEURL=https://example.com
BASE_PATH=/workspace/
WS_PUBLIC_URL=wss://example.com/workspace/ws
WS_ALLOWED_ORIGINS=https://example.com
```

`WS_ALLOWED_ORIGINS` — список browser origins через запятую. Это **origin**, а не путь: для `https://example.com/workspace/` origin остаётся `https://example.com`.

Секрет генерируйте отдельно от остальных ключей:

```bash
openssl rand -hex 32
```

Не публикуйте `WS_TICKET_SECRET` и не используйте один секрет для prod/stage/dev.

## 4. Ручной запуск и диагностика

Из корня приложения:

```bash
php ws_server/server.php start
```

Запуск daemon mode:

```bash
php ws_server/server.php start -d
```

Workerman также поддерживает штатные команды управления этим entrypoint:

```bash
php ws_server/server.php status
php ws_server/server.php restart
php ws_server/server.php stop
```

Проверить, что listener поднялся:

```bash
ss -ltnp | grep 27800
```

или:

```bash
nc -zv 127.0.0.1 27800
```

Raw TCP connect подтверждает только наличие listener. Полную авторизацию проверяйте через браузер Messenger или HTTPS/WSS smoke, потому что WebSocket connection требует корректные `Origin` и ticket.

## 5. Nginx: `/ws` → Workerman

В production браузер должен подключаться к `wss://`, а порт `27800` лучше оставлять только на loopback.

Минимальный location:

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

Для приложения в подкаталоге proxy-location должен совпадать с `WS_PUBLIC_URL`, например:

```nginx
location /workspace/ws {
    proxy_pass http://127.0.0.1:27800;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $http_host;
    proxy_set_header Origin $http_origin;
    proxy_read_timeout 60s;
}
```

Не проксируйте приватное хранилище и не открывайте `27800` наружу, если reverse proxy работает на том же сервере.

## 6. systemd для VPS/dedicated

Пример `/etc/systemd/system/workspace-messenger.service`:

```ini
[Unit]
Description=Workspace Organizer Messenger WebSocket
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/workspace-organizer
ExecStart=/usr/bin/php /var/www/workspace-organizer/ws_server/server.php start
ExecReload=/usr/bin/php /var/www/workspace-organizer/ws_server/server.php reload
ExecStop=/usr/bin/php /var/www/workspace-organizer/ws_server/server.php stop
Restart=on-failure
RestartSec=3
TimeoutStopSec=20

[Install]
WantedBy=multi-user.target
```

После создания/изменения unit:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now workspace-messenger
sudo systemctl status workspace-messenger
```

Логи systemd:

```bash
journalctl -u workspace-messenger -f
```

Путь, user/group и PHP binary скорректируйте под сервер. Service account должен иметь доступ к application files, `.env`, MySQL и `PRIVATE_STORAGE_PATH`, но не должен работать от root.

> Если конкретная схема запуска Workerman через `Type=simple` конфликтует с политикой вашего process manager, используйте foreground mode без `-d`, как в примере выше. Не запускайте daemon mode внутри systemd.

## 7. Supervisor — альтернатива systemd

Пример:

```ini
[program:workspace-messenger]
command=/usr/bin/php /var/www/workspace-organizer/ws_server/server.php start
directory=/var/www/workspace-organizer
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
stdout_logfile=/var/log/workspace-organizer/ws-supervisor.log
stderr_logfile=/var/log/workspace-organizer/ws-supervisor-error.log
```

После изменения:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status workspace-messenger
```

## 8. Shared hosting / панели

Realtime Messenger требует **долгоживущего PHP process + WebSocket reverse proxy**.

Если панель предоставляет Background processes / Supervisor / WebSocket proxy:

```bash
php /home/account/public_html/workspace/ws_server/server.php start
```

и настройте публичный `/workspace/ws` или `/ws` на локальный `WS_PORT`.

Если тариф убивает долгоживущие процессы и не умеет WebSocket proxy, web-модули Notes/Tasks/Files/Profile будут работать, но realtime Messenger на таком тарифе развернуть корректно нельзя.

## 9. Проверка после deploy

1. Проверить приложение:

```bash
php bin/healthcheck.php
```

2. Проверить service:

```bash
systemctl is-active workspace-messenger
ss -ltn | grep 27800
```

3. Проверить Nginx configuration:

```bash
sudo nginx -t
```

4. Войти в приложение двумя пользователями и открыть Messenger.
5. В DevTools → Network → WS убедиться, что соединение идёт к `WS_PUBLIC_URL` и получает `101 Switching Protocols`.
6. Отправить сообщение из первого browser session и убедиться, что второе получает его без reload.

Repository CI делает аналогичный production-like smoke через TLS Nginx + PHP + Workerman + два Chromium contexts.

## 10. Рестарт после обновления

После deploy кода, который затрагивает `app/socket`, Messenger services, ticket validation или `ws_server/server.php`, перезапустите Workerman:

```bash
sudo systemctl restart workspace-messenger
```

или при ручном управлении:

```bash
php ws_server/server.php restart
```

После рестарта выполните healthcheck и Messenger smoke.

При ротации `WS_TICKET_SECRET` HTTP/PHP и WebSocket процессы должны увидеть одно и то же новое значение. Старые socket tickets после ротации становятся недействительными.

## 11. Логи и частые проблемы

### `Connection refused`

Проверьте:

- Workerman process запущен;
- `WS_HOST`/`WS_PORT` совпадают с reverse proxy;
- порт слушается;
- firewall не мешает локальному proxy connection.

### WebSocket сразу закрывается

Проверьте:

- `WS_ALLOWED_ORIGINS` содержит фактический origin браузера;
- `SITEURL`, `BASE_PATH`, `WS_PUBLIC_URL` согласованы;
- `WS_TICKET_SECRET` одинаков у web- и WS-процессов;
- пользователь активен и его роль допускает login;
- часы сервера синхронизированы, потому что ticket короткоживущий.

### `502 Bad Gateway` на `/ws`

Обычно reverse proxy не может подключиться к `WS_HOST:WS_PORT` либо Workerman упал. Смотрите одновременно Nginx error log, systemd/supervisor status и `LOG_FILE`.

### Соединение есть, realtime не работает

Проверьте browser WS frames, application log и наличие `Authorized` frame. После авторизации сервер принимает только allowlisted actions; произвольный dynamic dispatch заблокирован.

## 12. Что нельзя делать

- не открывайте `ws://0.0.0.0:27800` в public Internet вместо WSS proxy;
- не отключайте Origin/ticket checks ради «починки» соединения;
- не запускайте Workerman от root;
- не храните secrets в unit-файле или репозитории, если уже используется `.env`;
- не размещайте `PRIVATE_STORAGE_PATH` в document root;
- не считайте простой открытый TCP port доказательством успешной Messenger авторизации.
