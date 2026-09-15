# Open Server 6+: Messenger WebSocket

## Почему одного Workerman недостаточно

Workspace Organizer намеренно запускает Workerman как внутренний plain-WebSocket listener, например:

```text
tcp://127.0.0.1:27800
```

Браузер при HTTPS-сайте подключается к публичному same-origin endpoint:

```text
wss://notes.local/ws
```

Это два разных endpoint. Между ними нужен WebSocket reverse proxy веб-сервера:

```text
Firefox / Chrome
    |
    | wss://notes.local/ws
    v
Open Server Apache/Nginx (TLS + Upgrade)
    |
    | ws://127.0.0.1:27800
    v
Workerman
```

Статус Workerman `[ok]` означает только, что внутренний listener запущен. Он не подтверждает, что `/ws` виртуального хоста проксируется на listener.

Не исправляйте HTTPS-сайт заменой `WS_PUBLIC_URL` на `ws://127.0.0.1:27800`: браузер заблокирует mixed content. Также `wss://notes.local:27800` не заработает сам по себе, потому что стандартный listener Workerman в проекте не завершает TLS.

## Рекомендуемый `.env` для `https://notes.local`

```env
SITEURL=https://notes.local
BASE_PATH=/

WS_HOST=127.0.0.1
WS_PORT=27800
WS_PUBLIC_URL=wss://notes.local/ws
WS_ALLOWED_ORIGINS=https://notes.local
```

`WS_TICKET_SECRET` оставьте текущим секретным значением.

После изменения `.env` перезапустите HTTP/PHP окружение и Workerman, чтобы оба процесса увидели одинаковую конфигурацию.

## Apache в Open Server 6+

Для проекта `C:\OSPanel\home\notes.local` создайте файл:

```text
C:\OSPanel\home\notes.local\.osp\Apache\notes.local.conf
```

Содержимое для Apache 2.4.47+:

```apache
ProxyPreserveHost On
ProxyPass "/ws" "http://127.0.0.1:27800/" upgrade=websocket
ProxyPassReverse "/ws" "http://127.0.0.1:27800/"
```

После сохранения **перезапустите Open Server**, затем запустите/перезапустите Workerman:

```bat
php ws_server/server.php restart
```

Если процесс ещё не запущен:

```bat
php ws_server/server.php start
```

Для старого Apache, где `upgrade=websocket` недоступен, backend можно указать через WebSocket scheme:

```apache
ProxyPreserveHost On
ProxyPass "/ws" "ws://127.0.0.1:27800/"
ProxyPassReverse "/ws" "ws://127.0.0.1:27800/"
```

## Nginx в Open Server

Если проект использует Nginx, создайте project-local extension:

```text
C:\OSPanel\home\notes.local\.osp\Nginx\notes.local.conf
```

Добавьте в server context:

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

Перезапустите Open Server после изменения конфигурации.

## Приложение в подкаталоге

При `BASE_PATH=/workspace/` публичный endpoint должен быть:

```text
wss://example.local/workspace/ws
```

и proxy path тоже должен быть `/workspace/ws`:

```apache
ProxyPass "/workspace/ws" "http://127.0.0.1:27800/" upgrade=websocket
ProxyPassReverse "/workspace/ws" "http://127.0.0.1:27800/"
```

Не используйте `/ws`, если `WS_PUBLIC_URL` указывает на `/workspace/ws`.

## Диагностика

После запуска Workerman выполните из корня проекта:

```bat
php bin/ws_doctor.php
```

Команда показывает:

- фактический `SITEURL`;
- URL, куда идёт браузер;
- внутренний listener Workerman;
- требуемый proxy path/backend;
- доступен ли TCP listener;
- готовые Apache и Nginx snippets;
- на Windows — ожидаемые `.osp` пути для текущего домена.

Затем откройте Messenger и проверьте DevTools → Network → WS. Успешное соединение должно получить:

```text
101 Switching Protocols
```

После handshake сервер первым прикладным сообщением отправляет `Authorized`.

## Если listener `[OK]`, а браузер всё ещё не подключается

Проверяйте по порядку:

1. Open Server был перезапущен после создания `.osp` config.
2. Активен именно тот web-server (Apache или Nginx), для которого создан config.
3. `WS_PUBLIC_URL` совпадает с proxy path.
4. `WS_ALLOWED_ORIGINS` содержит browser origin, например `https://notes.local`.
5. `WS_TICKET_SECRET` одинаков у HTTP/PHP и Workerman процессов.
6. Порт `27800` не занят другим процессом.
7. В логах веб-сервера нет `502`, `503` или ошибки загрузки proxy module.
8. В `LOG_FILE` / Workerman log нет `Rejected WebSocket origin` или ошибки ticket validation.

## Production / VPS

Та же схема применяется на production:

```text
Internet -> HTTPS/WSS reverse proxy -> 127.0.0.1:27800 -> Workerman
```

Workerman не нужно выставлять на `0.0.0.0` только ради браузерного подключения, если reverse proxy находится на том же сервере. Публичный TLS-сертификат и WSS обслуживает фронтовый Apache/Nginx/Caddy.

На shared hosting realtime Messenger поддерживается только если тариф позволяет одновременно:

- долгоживущий PHP CLI process;
- WebSocket reverse proxy/Upgrade;
- доступ proxy к локальному listener Workerman.

Если этих возможностей нет, обычные HTTP-модули могут работать, но realtime Messenger корректно развернуть нельзя.
