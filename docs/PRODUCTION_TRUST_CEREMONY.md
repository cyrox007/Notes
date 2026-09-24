# Церемония production trust

Эта процедура создаёт два production trust root Ed25519, используемых Workspace Organizer 1.0:

- подпись installation license;
- подпись update manifest.

Два домена ОБЯЗАНЫ использовать независимые пары ключей. Никогда не используйте один и тот же private/public key material одновременно для license-domain и update-domain.

## Граница безопасности

Выполняйте процедуру на контролируемой offline workstation или в эквивалентном изолированном signing environment поставщика.

Никогда не коммитьте, не загружайте в CI, не копируйте в release bundle, не устанавливайте на сервер клиента, не помещайте в `.env` и не вставляйте в issue/chat logs какой-либо приватный signing key.

В репозиторий коммитятся только base64url public keys:

- `config/license_trusted_keys.php`;
- `config/update_trusted_keys.php`.

Файлы приватных ключей остаются вне репозитория и должны быть защищены offline secret storage и backup controls поставщика.

## 1. Подготовьте два независимых внешних secret path

Пример:

```bash
umask 077
mkdir -p /secure/workspace-signing
chmod 700 /secure/workspace-signing
```

Используйте разные файлы и разные key ID:

- license: `prod-license-2026-01`;
- update: `update-prod-2026-01`.

Не используйте точку в update key ID, потому что token подписи обновления использует точки как разделители.

## 2. Создайте пару ключей для подписи лицензий

Из доверенного checkout исходников:

```bash
php tools/vendor-license/keygen.php \
  --key-id=prod-license-2026-01 \
  --private-out=/secure/workspace-signing/prod-license-2026-01.license-secret
```

Сохраните только напечатанную public registry entry. Приватный файл должен остаться с mode 0600 вне репозитория.

## 3. Создайте пару ключей для подписи обновлений

```bash
php tools/vendor-update/keygen.php \
  --key-id=update-prod-2026-01 \
  --private-out=/secure/workspace-signing/update-prod-2026-01.update-secret
```

Снова сохраните только public registry entry.

Публичный license key и публичный update key должны различаться. Контракт репозитория также запрещает повторное использование одного public key material даже при разных key ID.

## 4. Коммитьте только публичные trust roots

Добавьте публичную license entry в `config/license_trusted_keys.php`.

Добавьте публичную update entry в `config/update_trusted_keys.php`.

Откройте отдельный PR. Никогда не коммитьте `*.license-secret` или `*.update-secret`.

Stable release gate для `master` намеренно падает, если хотя бы один production public registry пуст.

## 5. License canary

Используйте непродуктивный test installation ID и offline-приватный license key:

```bash
php tools/vendor-license/issue.php \
  --private-key=/secure/workspace-signing/prod-license-2026-01.license-secret \
  --key-id=prod-license-2026-01 \
  --installation-id=11111111-2222-4333-8444-555555555555 \
  --license-id=lic-release-canary-001 \
  --edition=team \
  --max-users=20 \
  --features=workspace.notes,workspace.tasks,workspace.files,workspace.messenger,workspace.profile,workspace.admin
```

Проверьте полученный token на сборке, содержащей committed public registry. Не коммитьте canary token.

## 6. Canary подписи обновления

Создайте disposable ZIP package вне репозитория или используйте exact release-candidate bundle, затем соберите manifest:

```bash
php tools/vendor-update/build-manifest.php \
  --package=/secure/release/workspace-organizer-v1.0.2.zip \
  --version=1.0.2 \
  --version-code=10002 \
  --channel=stable \
  --source-commit=<FULL_40_HEX_RELEASE_COMMIT> \
  --min-source-version-code=10001 \
  --requires-php=8.1.0 \
  --out=/secure/release/update.json
```

Подпишите точные bytes manifest отдельным update key:

```bash
php tools/vendor-update/sign-manifest.php \
  --private-key=/secure/workspace-signing/update-prod-2026-01.update-secret \
  --key-id=update-prod-2026-01 \
  --manifest=/secure/release/update.json \
  --signature-out=/secure/release/update.sig
```

Updater должен принимать manifest только при наличии соответствующего public key в `config/update_trusted_keys.php`.

## 7. Приёмка перед выпуском в master

До merge финального release candidate в `master`:

1. оба public registry непустые;
2. key ID не пересекаются;
3. fingerprints публичных ключей не пересекаются;
4. production private keys существуют только в offline vendor environment;
5. canary license успешно проверяется;
6. canary update manifest успешно проверяется;
7. полный Stable release gate зелёный на exact release head;
8. финальный release artifact подписан update-domain key после окончательной фиксации его SHA-256.

Если какой-либо private signing material появляется в Git history, CI artifacts, customer packages, support archives или chat/log output, считайте эту пару ключей скомпрометированной и замените её до релиза.
