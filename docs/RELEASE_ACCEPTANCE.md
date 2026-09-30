# Финальная приёмка релиза Workspace Organizer 1.0.13

Этот документ — чек-лист выпуска и ручной приёмки `v1.0.13`. Он разделяет корректность репозитория, доказательства CI и действия, которые может выполнить только оператор. Релиз принимается только для одного точного commit; доказательства со старого HEAD автоматически не переносятся.

Для `1.0.13` исходной точкой является уже опубликованная `1.0.12`. Переход выполняется штатным подписанным updater без отдельного bootstrap предыдущей версии. Реальная Windows/OSPanel-приёмка пути `1.0.12 → 1.0.13` выполняется до публикации `v1.0.13` и обязательно подтверждает rollback/recovery, сохранность пользовательских данных, Profile и Messenger в Long Poll/WebSocket режимах.

## Идентичность релиз-кандидата

Принятый релиз-кандидат должен сообщать:

- версия: `1.0.13`;
- код версии: `10013`;
- статус: `stable`;
- релизный PR: `release/1.0.13-module-entitlements` → `master`;
- финальная релизная ветка: `master`;
- тег: `v1.0.13`.

До сборки финального пакета зафиксируйте точный 40-символьный SHA commit в релизных материалах.

## Gate A — контракт репозитория и исходников

До финального тестирования RC:

1. Все запланированные изменения релиза включены в `release/1.0.13-module-entitlements`; посторонние ветки в RC отсутствуют.
2. Никакие посторонние или непроверенные ветки не сливаются «для удобства».
3. `php bin/release_acceptance.php --json` не сообщает об ошибках source-contract.
4. В релизном дереве нет приватных ключей подписи лицензий или обновлений.
5. README, CHANGELOG и `docs/releases/v1.0.13.md` описывают одну и ту же версию и статус.
6. Manifest миграций и metadata владения модулями актуальны, а уже применённые исторические SQL-миграции не переписаны.
7. Контур release-evidence присутствует до строгой финальной приёмки.

Результат `pending` от нестрогого preflight ожидаем, пока внешние ceremony-gates ещё не завершены.

## Gate B — управление репозиторием

Настройки GitHub Settings находятся вне истории Git. Владелец или администратор репозитория должен проверить:

- ветка `1.0` защищена;
- изменения проходят только через pull request;
- перед merge ветка должна быть актуальна относительно target;
- always-on check `release-gate` обязателен;
- финальная политика `master` требует release gate, browser lifecycle для Notes/Tasks/Files/Profile/Admin и storage DB-failure checks, перечисленные в `.github/release-governance.json`;
- устаревшие approvals сбрасываются после новых commit;
- force-push и удаление ветки запрещены;
- независимый approval обязателен, если существует другой квалифицированный reviewer.

Не отмечайте этот gate завершённым только потому, что `.github/release-governance.json` корректен.

## Gate C — production trust roots

Следуйте `docs/PRODUCTION_TRUST_CEREMONY.md`.

Требуемые доказательства:

1. Для подписи лицензий и обновлений используются независимые пары Ed25519.
2. Приватные ключи существуют только в контролируемом offline-хранилище поставщика.
3. В `config/license_trusted_keys.php` и `config/update_trusted_keys.php` коммитятся только публичные ключи.
4. License canary успешно проверяется на релиз-кандидате.
5. Canary update-manifest успешно проверяется на релиз-кандидате.
6. Ни файл приватного ключа, ни необработанный приватный ключ не появляются в Git, CI artifacts, релизном пакете, support-архивах или логах/чатах.

## Gate D — автоматические доказательства exact-head

После заморозки финального source commit все релизно-значимые проверки должны быть выполнены на этом точном SHA.

Требуемые доказательства включают:

- Stable release gate;
- isolation/runtime/database ownership модулей;
- CSP;
- security observability;
- retention/permanent purge;
- data-key rotation;
- browser lifecycle для Notes/Tasks/Files/Profile/Admin;
- HTTPS/WSS browser smoke, включая принудительную потерю WebSocket, HTTP long-poll fallback, освобождение worker/reconnect и мост fallback-mutation → активный WS-клиент;
- локальный TOTP QR: матрица строится внутри приложения без внешних API, длинный `otpauth://` URI проходит runtime-контракт;
- shared-hosting профиль: базовая установка и Messenger работают при `WS_ENABLED=0` и отключённых process API;
- signed updater/staging/apply/backup/recovery;
- автоматический bootstrap update credential по действующей лицензии без activation code и ручного пути; обязательные матрицы `online-update-access (8.1/8.3)` и `admin-update-ui (8.1/8.3)`;
- обязательный `admin-update-e2e`: текущая архитектура поднимается как `1.0.12`, затем подписанные тестовые пакеты `1.0.13` проверяют успешную установку, автоматический rollback при ошибке миграции, rollback при ошибке post-healthcheck и ранний boot recovery без CLI;
- точный upgrade/rollback drill опубликованного `v1.0.1` (`0e6e4a3b352cfb7436db6b749fd869bbb07310c9`) -> `1.0.2`;
- hosting installer/package;
- cross-browser/mobile release evidence;
- authenticated load/soak release evidence;
- Windows hosting compatibility CI на PHP 8.1/8.3/8.4/8.5 и финальная ручная приёмка OSPanel 5.2.2 на production-signed artifacts.

Результат со старого commit не может заменять failed, skipped или unrun check на frozen release HEAD.

## Gate E — эксплуатационная приёмка

На окружении, репрезентативном production:

1. Создайте свежий проверенный backup MySQL + `PRIVATE_STORAGE_PATH`.
2. Выполните restore drill в изолированное окружение.
3. Запустите `php bin/healthcheck.php --json`.
4. Проверьте HTTPS и WSS через production reverse proxy.
5. На установке с действующей лицензией и без готового update credential откройте Admin → Updates и подтвердите автоматический bootstrap в `PRIVATE_STORAGE_PATH/update-access/update-access.json` без activation code и ручной правки `.env`.
6. На опубликованной exact `v1.0.12` установке Windows/OSPanel подайте exact release-candidate `1.0.13`. Проверьте успешное обновление одной кнопкой, отдельный rollback при ошибке миграции, отдельный rollback при ошибке post-healthcheck и boot recovery после принудительного завершения процесса. Пользователь, роль, лицензия, `installation_id`, private storage и прикладные данные должны сохраняться, после `committed`/`rollback_verified` maintenance должен быть снят, а старая транзакция предыдущей установки не должна запускать rollback. Эта проверка обязательна до публикации `v1.0.13`.
7. С двумя аутентифицированными пользователями Messenger проверьте доставку по WebSocket, затем временно остановите/заблокируйте WS endpoint и убедитесь, что доставка автоматически продолжается через Long Poll без reload.
8. Восстановите WS endpoint и убедитесь, что оба клиента автоматически возвращаются в «WebSocket · в сети».
9. Проверьте одну зашифрованную заметку и одно зашифрованное сообщение Messenger.
10. Проверьте защищённый доступ к File Manager/Notes/Messenger media.
11. Просмотрите `php bin/observability.php --json` и retention preview.
12. Подтвердите достаточное свободное место в БД/private storage и возможность записи во внешние state paths.

## Gate F — дефекты и ручная приёмка

До того как владелец релиза объявит RC принятым:

- exact frozen RC/artifact прошёл live visual acceptance из #172, включая light/dark/system и проверку компактной ширины ноутбука;
- exact frozen RC/artifact прошёл Windows/OSPanel transport acceptance: WebSocket `101` + `Authorized`, автоматический переход на Long Poll, durable delivery и автоматический возврат к WebSocket;
- exact frozen RC прошёл автоматический updater acceptance: обычная лицензия сама создаёт внешний installation credential, повторная проверка не требует кода, а offline-режим не обращается в сеть;
- автоматический CI-контур подтвердил одношаговый переход `1.0.12 → 1.0.13`; реальная Windows/OSPanel-приёмка exact RC того же перехода выполняется до публикации `v1.0.13`;
- exact frozen RC прошёл 2FA/TOTP acceptance: персональное включение/отключение, обязательная политика Admin, принудительная настройка аккаунта без TOTP, одноразовый recovery code и сохранение работоспособности после прямой/обратной ротации `UNIQUE_KEY`;
- нет открытых P0/P1 дефектов с риском потери данных;
- нет открытых P0/P1 дефектов безопасности;
- нет неразрешённой release-blocking regression;
- любое известное ограничение меньшей критичности документировано и осознанно принято.

Автоматический CI не может подменить это решение. Если репрезентативная human beta cohort не запускалась, зафиксируйте этот факт явно, а не заявляйте о beta coverage.

## Gate G — неизменяемый артефакт и подпись

Соберите финальный upload-ready bundle из точного принятого SHA. Для ручной pre-tag проверки workflow `Build hosting package` можно запускать на точном commit/ref с `version=v1.0.13`: он сверяет версию с `core/Version.php` и сохраняет ZIP, SHA-256 и точный source SHA как workflow artifact. После появления проверенного неизменяемого тега тот же workflow имеет право `contents: write` только для публикации GitHub Release: он повторно собирает пакет с тега, проверяет соответствие версии и публикует Release вместе с checksum/source-SHA. Существующий Release автоматически не перезаписывается.

Далее:

1. Скачайте точный workflow ZIP + checksum + source-SHA artifact, а также bootstrap 1.0.2 + его checksum; сверьте записанные SHA-256 и source SHA с принятым commit.
2. Соберите update manifest с точным source commit/version/version-code.
3. Подпишите точные bytes manifest offline-приватным ключом update-domain.
4. Проверьте подпись manifest и hash пакета публичным registry, который поставляется в bundle.
5. Убедитесь, что bundle не содержит `.env`, private storage, vendor signing tools, приватные ключи подписи и transient state.
6. Не изменяйте и не перепаковывайте ZIP после принятия записанного SHA-256 и подписи.

## Gate H — финальный merge и tag

Только после завершения Gates A-G:

1. Запустите строгий preflight с правдивыми operator attestations:

```bash
php bin/release_acceptance.php --strict --json \
  --branch-protection-confirmed \
  --ci-green \
  --release-evidence-green \
  --backup-restore-current \
  --operational-acceptance-green \
  --visual-acceptance-green \
  --ospanel-acceptance-green \
  --update-bootstrap-acceptance-green \
  --one-click-update-acceptance-green \
  --two-factor-acceptance-green \
  --one-zero-one-drill-green \
  --p0p1-clear \
  --trust-canaries-green \
  --artifact-signed
```

2. Слейте exact accepted HEAD `release/1.0.13-module-entitlements` в `master` без внесения новых source changes.
3. Убедитесь, что `master` указывает на ожидаемое релизное содержимое.
4. Создайте подписанный/аннотированный тег `v1.0.13` согласно release policy репозитория.
5. Убедитесь, что tag-driven `Build hosting package` опубликовал GitHub Release из exact tagged SHA и приложил ZIP, checksum и source-SHA; update manifest и detached signature для production feed публикуются отдельно по операторскому процессу и не должны менять bytes принятого ZIP.
6. Ещё раз проверьте checksum опубликованной загрузки и release metadata.

Если после приёмки RC требуется любое изменение исходников, предыдущие exact-head доказательства аннулируются, а затронутые gates повторяются на новом SHA.
