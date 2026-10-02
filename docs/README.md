# Документация Workspace Organizer

Текущая опубликованная stable: **1.0.14**. Исправляющий релиз **1.0.15** проходит финальную проверку. Следующая продуктовая линия: **1.1**.

Этот файл — точка входа в документацию. Если сведения в старом аудите или релизном плане расходятся с документами из раздела «Актуальные», приоритет имеют актуальные документы и фактический код `master`.

## Пользователю и администратору

- [Инструкция пользователя](USER_GUIDE.md)
- [Установка на обычный хостинг](HOSTING_INSTALL.md)
- [Совместимость окружений](DEPLOYMENT_COMPATIBILITY.md)
- [Совместимость с виртуальным хостингом](SHARED_HOSTING_COMPATIBILITY.md)
- [Open Server / OSPanel и WebSocket](OPEN_SERVER_WEBSOCKET.md)
- [Двухфакторная аутентификация](TWO_FACTOR_AUTH.md)

## Эксплуатация

- [Production-развёртывание](PRODUCTION.md)
- [Эксплуатация, резервное копирование и восстановление](OPERATIONS.md)
- [Messenger: Long Poll и WebSocket](MESSENGER_SERVER.md)
- [Лицензирование](LICENSING.md)
- [Выпуск лицензии](LICENSE_ISSUANCE.md)
- [Доступ к обновлениям через реестр](ONLINE_UPDATE_ACCESS.md)
- [Подписанные обновления](UPDATES.md)
- [Сервисная диагностика и отправка отчётов](SUPPORT_DIAGNOSTICS.md)
- [Удалённая доставка обновлений](UPDATE_REMOTE_DELIVERY.md)
- [Транзакционное применение и откат обновления](UPDATER_LIVE_APPLY.md)
- [Церемония production trust](PRODUCTION_TRUST_CEREMONY.md)

## Архитектура и разработка

- [Ядро](CORE.md)
- [Архитектура БД](DB_ARCHITECTURE.md)
- [Модульная платформа](MODULE_PLATFORM.md)
- [Разработка модулей](MODULE_DEVELOPMENT.md)
- [Изоляция runtime модулей](MODULE_RUNTIME_ISOLATION_1.0.md)
- [RBAC и границы доступа](RBAC.md)
- [Системные требования](SYSTEM_REQUIREMENTS.md)
- [Контракт производительности на 1000 активных пользователей](PERFORMANCE_1000_USERS.md)

## План развития

- [Дорожная карта](ROADMAP.md)
- [Банк идей и отложенных направлений](PRODUCT_BACKLOG.md)
- [1.1 — Календарь и ежедневник](plans/1.1-calendar.md)
- [1.1 — Общая система уведомлений](plans/1.1-notifications.md)
- [1.2 — Дефекты](plans/1.2-defects.md)
- [1.3 — Наряды и допуски](plans/1.3-work-permits.md)
- [1.4 — Общий медиаслой](plans/1.4-media.md)
- [1.5 — CodeExplorer / Workspace IDE](plans/1.5-code-explorer.md)
- [1.6 — Офисный контур](plans/1.6-office.md)

## Релизы и приёмка

- [Статус линии 1.0.x](RELEASE_STATUS_1.0.md)
- [Финальная приёмка 1.0.15](RELEASE_ACCEPTANCE.md)
- [Доказательства релизной готовности](RELEASE_EVIDENCE.md)
- [Управление релизными ветками](RELEASE_GOVERNANCE.md)
- [Windows / OSPanel: эксплуатационная проверка 1.0.15](WINDOWS_OSPANEL_ACCEPTANCE_1.0.15.md)
- [История выпусков](releases/)

## Исторические документы

Старые планы 0.12–0.14, промежуточные аудиты, beta-hardening и планы отдельных переработок сохранены как свидетельства развития, но не являются текущим списком задач.

Полный перечень и правила чтения: [ARCHIVE.md](ARCHIVE.md). История специальных переходов старого updater: [UPDATER_HISTORY.md](UPDATER_HISTORY.md).
