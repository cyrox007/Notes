# Deployment compatibility

Этот документ разделяет **техническую совместимость**, проверяемые CI-сценарии и рекомендуемое production-окружение Workspace Organizer.

## Поддерживаемые сценарии

| Окружение | Web-модули | Realtime Messenger | Статус |
| --- | --- | --- | --- |
| Linux VPS/VDS + Nginx/Apache + PHP 8.1+ | Да | Да | Рекомендуемый production |
| Shared hosting с PHP 8.1+, постоянным background process и WebSocket reverse proxy | Да | Да | Поддерживается при наличии этих возможностей тарифа |
| Обычный shared hosting без long-running process/WebSocket proxy | Да | Нет | Поддерживаются Notes/Tasks/Files/Profile/Admin, но не realtime Messenger |
| Open Server 6+ | Да | Да | Основное локальное Windows-окружение; требуется WebSocket bridge |
| Open Server 5.4.x + PHP 8.1+ | Да | Да при корректном Apache/Nginx proxy | Legacy-compatible локальная разработка; не является production-рекомендацией |
| Windows/Open Server как Internet-facing production | Технически возможно | Технически возможно | Не рекомендуется; production baseline — Linux |

## Open Server 5.4.x

Workspace Organizer не зависит от версии панели Open Server как таковой. Критичны фактические версии PHP/MySQL/Apache/Nginx и доступные модули веб-сервера.

Для ветки Open Server 5.4.x требуется:

- PHP CLI и web PHP версии `8.1+`;
- MySQL 8.x рекомендуется как основной проверяемый путь;
- Apache 2.4 с `mod_rewrite`, а для встроенного `/ws` bridge также `mod_proxy`, `mod_proxy_http` и `mod_proxy_wstunnel`; либо Nginx с эквивалентным proxy-конфигом;
- возможность запустить отдельный Workerman process через PHP CLI;
- `WS_HOST=127.0.0.1` и отдельный локальный порт, по умолчанию `27800`.

Архитектура остаётся той же, что и для Open Server 6+:

```text
Browser -> https://notes.local
        -> wss://notes.local/ws
        -> Apache/Nginx TLS + WebSocket Upgrade
        -> ws://127.0.0.1:27800
        -> Workerman
```

Сначала используйте проектный `.htaccess`: в `0.14.0-beta.2+` он содержит guarded bridge `/ws -> 127.0.0.1:27800`, который активируется только при наличии необходимых Apache proxy modules.

Если конкретная сборка Open Server 5.4 запрещает proxy из `.htaccess`, добавьте proxy-правило в пользовательскую конфигурацию виртуального хоста выбранного Apache/Nginx-модуля. Точное имя и расположение шаблона зависят от установленного модуля Open Server 5.4, поэтому проект не перезаписывает глобальную конфигурацию панели автоматически.

Apache-пример:

```apache
ProxyPreserveHost On
ProxyPass "/ws" "ws://127.0.0.1:27800/"
ProxyPassReverse "/ws" "ws://127.0.0.1:27800/"
```

После изменения конфигурации полностью перезапустите Open Server, затем запустите/перезапустите Workerman:

```bat
php ws_server/server.php restart
php bin/ws_doctor.php
```

`ws_doctor` отдельно показывает состояние локального listener и публичного WebSocket endpoint. Открытый `127.0.0.1:27800` сам по себе не означает, что `wss://notes.local/ws` работает.

> Open Server 5.4.x не запускается в Linux CI, поэтому он имеет статус legacy-compatible, а не отдельного CI-certified runtime. Совместимость обеспечивается общим PHP 8.1 floor и стандартным Apache/Nginx WebSocket contract.

## Shared hosting

Fresh web-installation не требует Composer на конечном хостинге: используйте `workspace-organizer-v*.zip` из GitHub Release и откройте `install.php`.

Для web-модулей достаточно обычного PHP/MySQL hosting contract проекта. Для realtime Messenger дополнительно необходимы **все** следующие возможности:

1. PHP CLI `8.1+`;
2. возможность постоянно держать `php ws_server/server.php start` как background process;
3. WebSocket reverse proxy от публичного `wss://domain[/base]/ws` к локальному Workerman port;
4. механизм автоматического перезапуска процесса — Supervisor, systemd-аналог панели или штатный background process manager.

Если тариф завершает CLI-процессы через короткое время или не позволяет proxy WebSocket Upgrade, Messenger realtime на таком тарифе не считается поддерживаемым. Не используйте cron «раз в минуту» как замену process manager.

При наличии панели с Background processes / Supervisor запускайте, например:

```bash
php /home/account/public_html/workspace/ws_server/server.php start
```

а публичный `/workspace/ws` проксируйте на `127.0.0.1:27800`.

## VPS/VDS production

Рекомендуемый production baseline:

- Linux;
- PHP-FPM/Apache PHP `8.3+` рекомендуется, `8.1+` остаётся compatibility floor;
- Nginx или Apache как TLS termination/reverse proxy;
- Workerman на loopback;
- systemd или Supervisor;
- MySQL 8.x;
- private storage вне document root;
- HTTPS/WSS.

Полная конфигурация Workerman, Nginx, systemd и Supervisor приведена в `docs/MESSENGER_SERVER.md`.

## Проверка после развёртывания

```bash
php bin/healthcheck.php
php bin/ws_doctor.php
```

Затем в браузере откройте DevTools -> Network -> WS. Соединение с `WS_PUBLIC_URL` должно получить HTTP `101 Switching Protocols`, после чего Messenger должен получить авторизацию и realtime delivery без reload.
