# Production-церемония доверия

Эта церемония создаёт два production Ed25519 trust roots, используемых Workspace Organizer 1.0:

- подпись лицензий установки;
- подпись signed update manifest.

Эти два домена ОБЯЗАНЫ использовать независимые keypairs. Никогда не используйте один и тот же private/public key material одновременно для лицензий и обновлений.

## Граница безопасности

Проводите церемонию на контролируемой офлайн workstation или в эквивалентной изолированной vendor signing environment.

Никогда не коммитьте private signing key, не загружайте его в CI, не копируйте в release bundle, не устанавливайте на сервер клиента, не помещайте в `.env` и не вставляйте в issue/chat logs.

В репозиторий коммитятся только base64url public keys:

- `config/license_trusted_keys.php`;
- `config/update_trusted_keys.php`.

Private key files остаются вне репозитория и должны защищаться офлайн secret storage и backup controls поставщика.

## 1. Подготовьте два независимых внешних пути для secret

Пример:

```bash
umask 077
mkdir -p /secure/workspace-signing
chmod 700 /secure/workspace-signing
```

Используйте разные files и разные key IDs:

- лицензия: `prod-license-2026-01`;
- обновление: `update-prod-2026-01`.

Не используйте точку в update key ID, потому что token подписи обновления использует точки как разделители.

## 2. Создайте keypair подписи лицензий

Из доверенного checkout исходников:

```bash
php tools/vendor-license/keygen.php \
  --key-id=prod-license-2026-01 \
  --private-out=/secure/workspace-signing/prod-license-2026-01.license-secret
```

Зафиксируйте только выведенную public registry entry. Private file должен оставаться с mode 0600 вне репозитория.

## 3. Создайте keypair подписи обновлений

```bash
php tools/vendor-update/keygen.php \
  --key-id=update-prod-2026-01 \
  --private-out=/secure/workspace-signing/update-prod-2026-01.update-secret
```

Снова сохраните только public registry entry.

License public key и update public key должны различаться. Контракт репозитория также отклоняет повторное использование одинакового public key material даже при разных key IDs.

## 4. Закоммитьте только публичные trust roots

Добавьте license public entry в `config/license_trusted_keys.php`.

Добавьте update public entry в `config/update_trusted_keys.php`.

Откройте отдельный PR. Никогда не коммитьте `*.license-secret` или `*.update-secret`.

Stable release gate для `master` намеренно завершается ошибкой, пока любой из production public registry пуст.

## 5. Canary лицензии

Используйте непродуктивный test installation ID и офлайн license private key:

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

Проверьте полученный token на build, содержащем committed public registry. Canary token не коммитьте.

## 6. Canary подписи обновления

Создайте disposable ZIP package вне репозитория или используйте точный release-candidate bundle, затем соберите manifest:

```bash
php tools/vendor-update/build-manifest.php \
  --package=/secure/release/workspace-organizer-v1.0.1.zip \
  --version=1.0.1 \
  --version-code=10001 \
  --channel=stable \
  --source-commit=<FULL_40_HEX_RELEASE_COMMIT> \
  --min-source-version-code=1404 \
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

## 7. Приёмка перед релизом в master

Перед merge финального release candidate в `master`:

1. оба public registry непусты;
2. key IDs не пересекаются;
3. fingerprints public keys не совпадают;
4. production private keys существуют только в офлайн vendor environment;
5. canary license успешно проверяется;
6. canary update manifest успешно проверяется;
7. полный Stable release gate зелёный на точном release head;
8. финальный release artifact подписан update-domain key после окончательной фиксации его SHA-256.

Если private signing material появляется в Git history, CI artifacts, customer packages, support archives или chat/log output, считайте keypair скомпрометированной и замените её до релиза.
