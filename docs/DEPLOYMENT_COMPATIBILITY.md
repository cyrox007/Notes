# Deployment compatibility

Этот документ разделяет техническую совместимость, проверяемые CI-сценарии и рекомендуемое production-окружение Workspace Organizer 1.0.

## Поддерживаемые сценарии

| Окружение | Web-модули | Realtime Messenger | Статус |
| --- | --- | --- | --- |
| Linux VPS/VDS + Nginx/Apache + PHP 8.1+ | Да | Да | Рекомендуемый production |
| Shared hosting с PHP 8.1+, постоянным background process и WebSocket reverse proxy | Да | Да | Поддерживается при наличии этих возможностей тарифа |
| Обычный shared hosting без long-running process/WebSocket proxy | Да | Нет | Notes/Tasks/Files/Profile/Admin работают без realtime Messenger |
| Open Server 6+ | Да | Да | Основное локальное Windows-окружение; требуется WebSocket bridge |
| Open Server 5.4.x + PHP 8.1+ | Да | Да при корректном Apache/Nginx proxy | Legacy-compatible local development |
| Windows/Open Server как Internet-facing production | Технически возможно | Технически возможно | Не рекомендуется; production baseline — Linux |

## Общий runtime contract 1.0

Приложение не требует Composer packages или каталога `vendor/` в production. HTTP views рендерятся внутренним `NativeViewRenderer`, а realtime Messenger обслуживается собственным PHP RFC6455 runtime на `stream_socket_server()` + `stream_select()`.

Нужны PHP 8.1+, MySQL и используемые приложением PHP extensions (`mysqli`, `pdo_mysql`, `mbstring`, `sodium`, `fileinfo`, `gd`).

## Open Server 5.4.x / 6+

Критичны фактические версии PHP/MySQL/Apache/Nginx и доступные proxy modules.

Требуется:

- PHP CLI и web PHP `8.1+`;
- Apache 2.4 с `mod_rewrite`, `mod_proxy`, `mod_proxy_http` и WebSocket Upgrade support либо Nginx;
- возможность запустить отдельный native WebSocket process через PHP CLI;
- `WS_HOST=127.0.0.1` и локальный `WS_PORT`, по умолчанию `27800`.

```text
Browser -> https://notes.local
        -> wss://notes.local/ws
        -> Apache/Nginx TLS + WebSocket Upgrade
        -> ws://127.0.0.1:27800
        -> Workspace native WebSocket server
```

Запуск/перезапуск:

```bat
php ws_server/server.php restart
php bin/ws_doctor.php
```

Открытый `127.0.0.1:27800` сам по себе не означает, что публичный WSS proxy работает. Подробности — `docs/OPEN_SERVER_WEBSOCKET.md`.

## Shared hosting

Fresh web-installation не требует Composer: используйте release ZIP и откройте `install.php`.

Для realtime Messenger дополнительно необходимы:

1. PHP CLI `8.1+`;
2. возможность постоянно держать `php ws_server/server.php start` как background process;
3. WebSocket reverse proxy от публичного `wss://domain[/base]/ws` к локальному `WS_PORT`;
4. механизм автоматического перезапуска — Supervisor, systemd-аналог панели или background process manager.

Если тариф завершает CLI-процессы или не позволяет WebSocket Upgrade proxy, realtime Messenger на таком тарифе не поддерживается. Остальные HTTP-модули продолжают работать.

## VPS/VDS production

Рекомендуемый baseline:

- Linux;
- PHP 8.3+ рекомендуется, 8.1+ compatibility floor;
- Nginx или Apache как TLS termination/reverse proxy;
- native WebSocket listener только на loopback;
- systemd или Supervisor;
- MySQL 8.x;
- private storage вне document root;
- HTTPS/WSS.

Полная конфигурация native Messenger server, Nginx, systemd и Supervisor — в `docs/MESSENGER_SERVER.md`.

## Проверка после развёртывания

```bash
php bin/healthcheck.php
php bin/ws_doctor.php
php ws_server/server.php status
```

Затем в DevTools -> Network -> WS соединение с `WS_PUBLIC_URL` должно получить `101 Switching Protocols`, после чего Messenger получает `Authorized` и realtime delivery без reload.

CI 1.0 дополнительно запускает production-like Chromium HTTPS/WSS smoke после принудительного удаления `vendor/`.
