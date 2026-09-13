# История версий Workspace Organizer

Формат основан на принципах Keep a Changelog. Пока проект находится в alpha, обратная совместимость между промежуточными версиями не гарантируется; database migrations являются частью обновления.

## Unreleased

### Product UI / UX
- Добавлен единый product design layer для dashboard, Notes, Tasks, Profile, File Manager, Admin и общей оболочки Messenger.
- Sidebar переработан: desktop collapse, mobile drawer/overlay, current-route state и более крупные touch targets.
- Обновлены header/footer, login и registration screens.
- Удалены Google Fonts; интерфейс использует системный font stack.
- Добавлены focus-visible, skip-link/доступные labels, aria-live states и `prefers-reduced-motion`.
- File Manager больше не выполняет пользовательский code content: незавершённый Ace/code-run flow удалён, текст/code открывается только read-only preview.
- Исправлены runtime-баги динамических File Manager actions после создания папки.
- Admin panel получил полноценную таблицу аккаунтов со статусами, блокировкой/активацией и безопасной деактивацией.
- Custom profile fields в Admin синхронизированы с canonical `user_fields` schema и получили серверную валидацию имён/типов/длины.

### Production / Core hardening
- Исправлены case-sensitive bootstrap paths `core.php` для Linux filesystem.
- Добавлен CLI `bin/healthcheck.php` для PHP/extensions/secrets/private storage/DB/schema checks.
- Добавлен file-backed request rate limiter с `flock` и private state под `PRIVATE_STORAGE_PATH/rate-limit`.
- Login/registration защищены `AuthRateLimit`, upload endpoints — `UploadRateLimit`.
- Web-registration закрыта без явного `REGISTRATION_INVITE_CODE`.
- Registration validation синхронизирована с canonical user contract.
- CSP очищена от dev-domain/Google Fonts/external JS CDN; `unsafe-eval` удалён после отказа от browser code runner.
- Добавлены Permissions Policy и COOP; `unsafe-inline` пока остаётся как известный legacy Smarty CSP debt.
- Добавлена production/deployment документация `docs/PRODUCTION.md`.
- Admin physical user delete заменён на deactivation contract: строка пользователя и связанные Notes/Tasks/Messenger данные сохраняются.
- Admin lifecycle вынесен в `AdminUserService` с повторной проверкой active administrator role, запретом self/admin targets и защитой group owner до transfer ownership.
- Reactivation теперь восстанавливает одновременно `role` и `is_active`, поэтому деактивированный аккаунт действительно снова может войти после явной активации.

### Merged hardening after initial 0.10 baseline
- **PR #50:** Messenger forwarding и «Сохранённые сообщения», независимые forwarded media copies и минимизированные forwarding metadata.
- **PR #51:** Notes private attachments/share ACL, truthful attachment encryption flag и актуализированная документация.
- **PR #52:** canonical Tasks schema, ACL/validation contract, рабочие subtasks/categories/UI и Tasks integration CI.
- **PR #53:** private user avatars, canonical Profile contract и safe account deactivation вместо physical user delete.
- **PR #54:** versioned DB migration runner, checksums, fresh-vs-upgrade installer contract и legacy DB integration test.
- **PR #55:** resumable fail-closed legacy Messenger/Notes ciphertext migration CLI.

## 0.10.0-alpha — 2026-09-13

### Security baseline
- Закрыта возможность исполняемых публичных upload-файлов; File Manager переведён на private storage и авторизованную выдачу.
- Авторизация WebSocket переведена на короткоживущие HMAC tickets с server-bound identity.
- Добавлены origin allowlist и явный allowlist WebSocket actions.
- Исправлены destructive GET/CSRF-риски и middleware coverage для критических маршрутов.
- Пароли используют `password_hash`/Argon2id, сессия ротируется при входе.
- Активные crypto paths работают fail-closed.

### Crypto
- `CryptMethods` использует `UNIQUE_KEY` и libsodium XChaCha20-Poly1305.
- Messenger text/caption encryption использует версионированный XChaCha20-Poly1305 payload.
- Legacy AES-CBC чтение Messenger возможно только при явно заданном временном legacy key.
- Silent plaintext fallback для новых зашифрованных данных запрещён.

### Messenger v2
- Каноническая схема `users / dialogs / user_to_dialogs / messages / messenger_attachments / message_user_deletions`.
- Личные и групповые чаты, reply, edit, delete-for-me и sender-only delete-for-all.
- Явные read/delivered cursors и UI статусов отправки/доставки/прочтения.
- Multi-device WebSocket registry: события доставляются всем активным вкладкам/устройствам пользователя.
- Per-user состояния диалогов: pin, mute, archive.
- Private media attachments с MIME allowlist, ACL и Range streaming.
- Групповые роли owner/admin/member, добавление/удаление участников, transfer ownership, rename и private group avatar.
- Media reply-to и cleanup orphan uploads.
- Bounded encrypted search без plaintext message index.
- Голосовые сообщения: MediaRecorder upload, private storage, player, seek и скорости 1x/1.5x/2x.
- Realtime reactions с серверным allowlist, атомарным toggle, aggregate count и персональным `reacted_by_me`.

### CI
- Security baseline workflow: Composer validate/install/audit, PHP lint, JS syntax, clean schema smoke и crypto fail-closed tests.
- Отдельные integration workflows для Messenger groups, media lifecycle, encrypted search, voice recording и reactions.
- Реальные HTTP multipart smoke tests применяются для критичных upload-путей Messenger.

## 0.9.0-alpha — 2026-05-14

- Стабилизация ORM (`where`, `GROUP BY`, aliases).
- Переход части контрактов с `user_uid` на `user_id`.
- Исправления создания/редактирования Notes и обновление crypto helpers того периода.
- Исправления Router, логирования, sidebar/profile UI и документации.

## 0.8.0-alpha — 2026-05-07

- Добавлены Tasks/ежедневник и File Manager.
- Добавлены мультимедиа-просмотр и CodeExplorer.
- Расширены регистрация по invite, профиль и admin panel.
- Добавлены middleware/routing и ранняя WebSocket-интеграция Messenger.

## 0.7.0-alpha

- Первый функциональный Messenger и Notes sharing.
- Realtime сообщения, typing indicator и ранняя поддержка media/voice.

## 0.6.0-alpha

- Расширенный профиль пользователя.
- Смена пароля и удаление аккаунта.
- Admin panel, блокировка пользователей и custom profile fields.

## 0.5.0-alpha

- ORM/database layer: SELECT/JOIN/WHERE/GET/FIRST и логирование SQL.

## 0.4.0-alpha

- Middleware, Request, redirect/session helpers и переработка Router/Core.

## 0.3.0-alpha

- Smarty, MVC, Router и базовое подключение к БД.

## 0.2.0-alpha

- Регистрация, авторизация, сессии и базовый профиль.

## 0.1.0-alpha

- Начальная структура приложения и конфигурация окружения.
