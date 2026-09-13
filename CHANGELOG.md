# История версий Workspace Organizer

Формат основан на принципах Keep a Changelog. Пока проект находится в alpha, обратная совместимость между промежуточными версиями не гарантируется; миграции БД из `database/migrations/` являются частью обновления.

## Unreleased

### Notes hardening
- Вложения заметок переводятся из публичных `/uploads/notes` в `PRIVATE_STORAGE_PATH/notes`.
- MIME определяется сервером через `finfo` и сверяется с расширением по allowlist.
- Добавляются owner/share ACL endpoints для загрузки, чтения и удаления вложений.
- Public share получает отдельную token-bound выдачу вложений.
- `note_attachments.is_encrypted` теперь отражает реальность: новые файлы пока не шифруются побайтно и записываются с `0`; защита обеспечивается private filesystem + ACL.
- Публичный share UI ограничивается реально поддерживаемым режимом `view`.
- Восстанавливаются отсутствовавшие Notes attachment/share routes.

### Messenger — PR #50
- Forwarding сообщений и медиа между доступными чатами.
- Независимое копирование forwarded media в private storage целевого диалога.
- «Сохранённые сообщения» как отдельный приватный single-user dialog.
- Минимизированные forwarding metadata без раскрытия внутренних user/message UID.

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
