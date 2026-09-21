# Финальная приёмка релиза Workspace Organizer 1.0

Этот документ — финальный checklist для выпуска `v1.0.1`. Он намеренно разделяет корректность репозитория, CI evidence и действия, которые выполняет только оператор. Релиз принимается только на одном точном commit; evidence со старого head автоматически не переносится.

## Идентичность release candidate

Принятый release candidate должен сообщать:

- version: `1.0.1`;
- version code: `10001`;
- status: `stable`;
- release branch: `1.0`;
- final release branch: `master`;
- tag: `v1.0.1`.

До сборки финального bundle зафиксируйте точный 40-символьный commit SHA в release notes.

## Gate A — контракт репозитория/исходников

До финального тестирования RC:

1. Все запланированные engineering PR для 1.0 объединены в `1.0`.
2. Несвязанные или непроверенные ветки не сливаются «для удобства».
3. `php bin/release_acceptance.php --json` не сообщает об ошибках source contract.
4. В release tree нет приватных ключей подписи лицензий/обновлений.
5. README, CHANGELOG и `docs/releases/v1.0.1.md` описывают одинаковые version/status.
6. Manifest миграций и metadata ownership модулей актуальны, а исторический уже применённый SQL не переписан.
7. Release-evidence harness присутствует до strict acceptance.

Результат `pending` от non-strict preflight ожидаем, пока внешние ceremony gates ещё не завершены.

## Gate B — управление репозиторием

Repository Settings находятся вне Git history. Владелец/admin репозитория должен проверить:

- `1.0` защищена;
- pull requests обязательны;
- branch должна быть актуальна перед merge;
- always-on `release-gate` является обязательным;
- финальная policy `master` требует release gate, а также Notes/Tasks/Files/Profile/Admin browser lifecycle и storage DB-failure checks из `.github/release-governance.json`;
- stale approvals сбрасываются после новых commits;
- force push и удаление branch запрещены;
- независимый approval обязателен, когда существует другой квалифицированный reviewer.

Не отмечайте gate завершённым только потому, что `.github/release-governance.json` корректен.

## Gate C — production trust roots

Следуйте `docs/PRODUCTION_TRUST_CEREMONY.md`.

Обязательные evidence:

1. подпись лицензий и обновлений использует независимые Ed25519 keypairs;
2. private keys существуют только в контролируемом офлайн vendor storage;
3. в `config/license_trusted_keys.php` и `config/update_trusted_keys.php` коммитятся только public keys;
4. license canary успешно проверяется на release candidate;
5. update-manifest canary успешно проверяется на release candidate;
6. private-key file или raw private key нигде не появляется в Git, CI artifacts, release bundle или support/chat logs.

## Gate D — автоматические evidence на точном head

После заморозки финального source commit запустите все release-relevant checks на этом точном SHA.

Обязательные evidence включают:

- Stable release gate;
- module isolation/runtime/database ownership;
- CSP;
- security observability;
- retention/permanent purge;
- data-key rotation;
- Notes/Tasks/Files/Profile/Admin browser lifecycle;
- HTTPS/WSS browser smoke;
- signed updater/staging/apply/backup/recovery;
- upgrade/rollback drill exact published `0.14.0-beta.4 -> 1.0.1`;
- hosting installer/package;
- cross-browser/mobile release evidence;
- authenticated load/soak release evidence.

Результат с предыдущего commit не может заменить failed, skipped или unrun check на frozen release head.

## Gate E — эксплуатационная приёмка

На deployment, репрезентативном для production:

1. создайте свежий проверенный backup MySQL + `PRIVATE_STORAGE_PATH`;
2. выполните restore drill в изолированной среде;
3. запустите `php bin/healthcheck.php --json`;
4. проверьте HTTPS и WSS через production reverse proxy;
5. проверьте одну зашифрованную Note и одно зашифрованное Messenger message;
6. проверьте защищённый доступ к File Manager/Notes/Messenger media;
7. просмотрите `php bin/observability.php --json` и retention preview;
8. подтвердите достаточное свободное место DB/private storage и writable external state paths.

## Gate F — приёмка дефектов

До того как владелец релиза объявит RC принятым:

- нет открытых P0/P1 дефектов с потерей данных;
- нет открытых P0/P1 security-дефектов;
- нет нерешённых release-blocking regressions;
- любое известное ограничение меньшей критичности документировано и осознанно принято.

Автоматический CI не может подменить это решение. Если репрезентативная группа реальных beta-пользователей не участвовала в тестировании, зафиксируйте этот факт явно вместо заявления о несуществующем beta coverage.

## Gate G — immutable artifact и подпись

Соберите финальный upload-ready bundle из точного принятого SHA. Workflow `Build hosting package` намеренно работает **только как сборка**: для ручной pre-tag сборки запустите его на точном принятом commit/ref с `version=v1.0.1`. Он проверяет version по `core/Version.php`, затем сохраняет ZIP, его SHA-256 и точный source SHA как один workflow artifact. До завершения офлайн подписи он не должен создавать или обновлять публичный GitHub Release.

Далее:

1. скачайте точный workflow ZIP + checksum + source-SHA artifact; проверьте записанные bundle SHA-256 и source SHA относительно принятого commit;
2. соберите update manifest с точными source commit/version/version-code;
3. подпишите точные bytes manifest офлайн private key домена обновлений;
4. проверьте manifest signature и hash package по public registry, входящему в bundle;
5. убедитесь, что bundle не содержит `.env`, private storage, vendor signing tools, private signing keys и transient state;
6. не изменяйте и не перепаковывайте ZIP после принятия записанного SHA-256 и подписи.

## Gate H — финальный merge и tag

Только после завершения Gates A-G:

1. запустите strict preflight с operator attestations:

```bash
php bin/release_acceptance.php --strict --json \
  --branch-protection-confirmed \
  --ci-green \
  --release-evidence-green \
  --backup-restore-current \
  --beta4-drill-green \
  --p0p1-clear \
  --trust-canaries-green \
  --artifact-signed
```

2. слейте точный принятый head `1.0` в `master`, не добавляя изменений исходников;
3. убедитесь, что `master` указывает на ожидаемое release content;
4. создайте signed/annotated tag `v1.0.1` согласно release policy репозитория;
5. опубликуйте ровно тот ранее принятый ZIP вместе с update manifest и detached signature; после подписи ZIP не пересобирайте и не перепаковывайте;
6. ещё раз проверьте checksum опубликованной загрузки и release metadata.

Если после приёмки RC требуется любое изменение исходников, предыдущие exact-head evidence становятся недействительными, а затронутые gates нужно повторить на новом SHA.
