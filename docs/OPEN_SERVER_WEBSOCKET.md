# Open Server 6+: Messenger WebSocket

## Fresh install: профиль OpenServer local

Web-installer распознаёт локальную Windows-структуру OpenServer/OSPanel вида `...\\domains\\<host>` и предлагает профиль **OpenServer / локальная Windows-установка**.

Для custom local domain вроде `http://notes.local` installer **не использует прямой** `ws://127.0.0.1:27800`. В современных Chromium-браузерах Local Network Access распространяется на WebSocket-соединения к loopback/local адресам, а permission flow требует secure context. Поэтому надёжный вариант для `notes.local` — same-origin WebSocket URL через веб-сервер:

```env
SITEURL=http://notes.local
BASE_PATH=/
WS_HOST=127.0.0.1
WS_PORT=27800
WS_PUBLIC_URL=ws://notes.local/ws
WS_ALLOWED_ORIGINS=http://notes.local
```

Для HTTPS локального домена installer соответственно формирует:

```env
WS_PUBLIC_URL=wss://notes.local/ws
WS_ALLOWED_ORIGINS=https://notes.local
```

Native process всё равно слушает только loopback `127.0.0.1:27800`. Apache/Nginx принимает browser WebSocket Upgrade на `/ws` и проксирует его к listener.

После установки:

```powershell
php ws_server/server.php start
```

Окно с процессом нужно оставить работающим. В другом терминале:

```powershell
php ws_server/server.php status
php bin/ws_doctor.php
```

Для Apache проект уже содержит `.htaccess` bridge на стандартный порт `27800`. Он требует `mod_proxy` и `mod_proxy_wstunnel` либо Apache с совместимой поддержкой WebSocket Upgrade через `mod_proxy_http`.

Проверка модулей из OpenServer shell:

```powershell
httpd -M
```

В выводе должны присутствовать proxy modules. Если proxy modules недоступны, включите их в активной конфигурации Apache или используйте Nginx reverse proxy.

## OSPanel / OpenServer 5.2.2

OpenServer 5.2.2 использует старую структуру `domains\\...` и не поддерживает project-local `.osp\\Apache` / `.osp\\Nginx` конфигурацию из Open Server 6. Поэтому `ws_doctor` не должен предлагать `.osp` пути для такой установки.

На OSPanel 5.x оставляйте browser endpoint same-origin:

```env
SITEURL=http://notes.local
BASE_PATH=/
WS_HOST=127.0.0.1
WS_PORT=27800
WS_PUBLIC_URL=ws://notes.local/ws
WS_ALLOWED_ORIGINS=http://notes.local
```

При HTTPS используйте `https://notes.local` + `wss://notes.local/ws`.

Если packaged `.htaccess` bridge не срабатывает, проверьте, что Apache действительно загрузил proxy modules. Для OSPanel 5.x конфигурация Apache хранится в legacy `userdata/config`/active module templates, а не в project-local `.osp` каталоге. После изменения Apache обязательно перезапустите OpenServer.

## Почему одного PHP-сайта недостаточно

Workspace Organizer 1.0 запускает собственный native PHP WebSocket listener:

```text
tcp://127.0.0.1:27800
```

Браузер при HTTPS-сайте подключается к публичному same-origin endpoint:

```text
wss://notes.local/ws
```

Между ними нужен WebSocket reverse proxy веб-сервера:

```text
Firefox / Chrome
    |
    | wss://notes.local/ws
    v
Open Server Apache/Nginx (TLS + Upgrade)
    |
    | ws://127.0.0.1:27800
    v
Workspace native WebSocket server
```

Статус native listener `[OK]` означает только, что внутренний процесс запущен. Он не подтверждает, что `/ws` виртуального хоста проксируется на listener.

Не исправляйте HTTPS-сайт заменой `WS_PUBLIC_URL` на `ws://127.0.0.1:27800`: браузер заблокирует mixed content. Также `wss://notes.local:27800` не заработает сам по себе, потому что TLS намеренно завершается на Apache/Nginx.

## Рекомендуемый `.env` для `https://notes.local`

```env
SITEURL=https://notes.local
BASE_PATH=/
WS_HOST=127.0.0.1
WS_PORT=27800
WS_PUBLIC_URL=wss://notes.local/ws
WS_ALLOWED_ORIGINS=https://notes.local
WS_MAX_CONNECTIONS=256
WS_MAX_PAYLOAD_BYTES=2097152
```

`WS_TICKET_SECRET` оставьте текущим секретным значением. После изменения `.env` перезапустите HTTP/PHP окружение и native WebSocket process, чтобы оба процесса увидели одинаковую конфигурацию.

## Apache в Open Server 6+: стандартный случай

Для стандартного `WS_PORT=27800` проект содержит безопасный `.htaccess` bridge:

```apache
RewriteCond %{HTTP:Upgrade} ^websocket$ [NC]
RewriteCond %{HTTP:Connection} (^|,)\s*upgrade\s*(,|$) [NC]
RewriteRule ^ws/?$ ws://127.0.0.1:27800/ [P,L]
```

Правило проксирует только фиксированный loopback backend и работает также при установке приложения в подкаталог.

После обновления `.htaccess` перезапустите Open Server, затем native WebSocket server:

```bat
php ws_server/server.php restart
```

Если процесс ещё не запущен:

```bat
php ws_server/server.php start
```

Проверка:

```bat
php ws_server/server.php status
php bin/ws_doctor.php
```

## Apache: fallback / нестандартный порт

Если автоматический bridge не сработал либо `WS_PORT` изменён, создайте project-local host extension:

```text
C:\OSPanel\home\notes.local\.osp\Apache\notes.local.conf
```

Для Apache 2.4.47+:

```apache
ProxyPreserveHost On
ProxyPass "/ws" "http://127.0.0.1:27800/" upgrade=websocket
ProxyPassReverse "/ws" "http://127.0.0.1:27800/"
```

Для старого Apache:

```apache
ProxyPreserveHost On
ProxyPass "/ws" "ws://127.0.0.1:27800/"
ProxyPassReverse "/ws" "ws://127.0.0.1:27800/"
```

После сохранения перезапустите Open Server.

## Nginx в Open Server

Если проект использует Nginx, создайте:

```text
C:\OSPanel\home\notes.local\.osp\Nginx\notes.local.conf
```

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

При `BASE_PATH=/workspace/` публичный endpoint:

```text
wss://example.local/workspace/ws
```

Для внешнего virtual-host proxy path должен быть `/workspace/ws`:

```apache
ProxyPass "/workspace/ws" "http://127.0.0.1:27800/" upgrade=websocket
ProxyPassReverse "/workspace/ws" "http://127.0.0.1:27800/"
```

## Диагностика

```bat
php bin/ws_doctor.php
```

Команда показывает SITEURL, browser WS URL, внутренний native listener, proxy backend и доступность TCP listener, а также готовые Apache/Nginx snippets.

Успешное browser соединение в DevTools → Network → WS должно получить `101 Switching Protocols`, затем прикладное сообщение `Authorized`.

Если listener `[OK]`, а браузер не подключается, проверяйте:

1. Open Server перезапущен после `.htaccess`/`.osp` изменений.
2. Apache proxy modules доступны либо настроен Nginx host extension.
3. `WS_PUBLIC_URL` совпадает с proxy path.
4. `WS_ALLOWED_ORIGINS` содержит фактический browser origin.
5. `WS_TICKET_SECRET` одинаков у HTTP и native WS процессов.
6. Порт `27800` не занят другим процессом.
7. В web-server log нет `502/503`.
8. В `LOG_FILE` нет `Rejected WebSocket origin`/ticket errors.

## Production / VPS

Та же схема:

```text
Internet -> HTTPS/WSS reverse proxy -> 127.0.0.1:27800 -> native PHP WebSocket server
```

Не выставляйте внутренний listener на `0.0.0.0`, если reverse proxy находится на том же сервере.

На shared hosting realtime Messenger поддерживается только если тариф позволяет долгоживущий PHP CLI process, WebSocket Upgrade proxy и доступ proxy к локальному listener. Если нет — HTTP-модули продолжают работать, realtime Messenger нет.

Composer/Workerman для 1.0 runtime не требуются.
