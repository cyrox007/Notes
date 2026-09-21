# Доверенное bootstrap-обновление Beta4 -> 1.0

`v0.14.0-beta.4` — опубликованный релиз, выпущенный до появления runtime подписанного updater-а. Tag указывает на commit `743d9283f3bb4fca8f536ad133d542a7078af3a9`; этот release не содержит `bin/update.php`, `UpdateManifestVerifier`, maintenance ownership, rollback backups, release candidates или live apply/recovery.

Поэтому первое поддерживаемое обновление Beta4 -> 1.0 не может честно быть self-update, запущенным из установленного дерева Beta4. Нужен один явный trusted bootstrap step. После установки 1.0 дальнейшие обычные signed updates запускаются уже установленным updater runtime.

## Граница доверия

Bootstrap runner — это `bin/update_bootstrap.php` из отдельно доверенного release bundle 1.0, распакованного **вне** live application tree Beta4.

Runner:

- не принимает private signing key;
- доверяет только public Ed25519 update keys из `config/update_trusted_keys.php` самого runner;
- требует от оператора закрепить точные expected source version и version code;
- загружает `Core\Version` из live legacy installation, а не из runner, поэтому существующая защита `updater_version_mismatch` остаётся привязанной к source tree;
- повторно использует production-реализации `UpdateManifestVerifier`, `UpdatePackageStager`, `UpdateArchiveInspector`, `MaintenanceModeService`, `UpdateTransactionJournal`, `UpdateBackupManager`, `UpdateReleaseCandidate` и `UpdateApplyCommand`;
- никогда не записывает package напрямую поверх live tree;
- хранит transaction journal, staging, rollback backup и release candidate вне live application root.

Bootstrap runner — это orchestration boundary, а не вторая реализация updater.

## Обязательные входные данные оператора

Перед запуском получите через обычный release channel:

1. доверенный release/runner bundle 1.0;
2. detached signed update manifest;
3. detached signature manifest;
4. точный ZIP package, на который ссылается подписанный manifest.

Production update public key уже должен присутствовать в trusted runner bundle. Private update-signing key остаётся офлайн у поставщика и никогда не передаётся в customer command.

Используйте внешние каталоги для всех recovery artifacts. Пример:

```bash
php /opt/workspace-1.0-bootstrap/bin/update_bootstrap.php \
  --app-root=/srv/workspace \
  --manifest=/opt/release/workspace-organizer.update.json \
  --signature=/opt/release/workspace-organizer.update.sig \
  --package=/opt/release/workspace-organizer-1.0.0.zip \
  --transaction=beta4-to-1-0-2026-001 \
  --expected-source-version=0.14.0-beta.4 \
  --expected-source-version-code=1404 \
  --stage-root=/var/lib/notes/update-staging \
  --state-root=/var/lib/notes/update-state \
  --backup-root=/var/lib/notes/update-backups \
  --candidate-root=/var/lib/notes/update-releases \
  --json
```

Команда проверяет signed manifest/package до входа в maintenance, затем выполняет тот же transaction pipeline, что и 1.0:

`verify -> ZIP audit -> immutable external stage -> maintenance -> journal -> verified code/MySQL backup -> reverify stage -> external release candidate -> pre-health/migration dry-run -> live switch -> migrations -> post-health/version/schema verification -> commit -> maintenance off`

## Восстановление

Если process завершился после destructive boundary, не начинайте новую transaction и не удаляйте recovery artifacts. Запустите recovery из того же trusted external runner с теми же external state/backup roots:

```bash
php /opt/workspace-1.0-bootstrap/bin/update_bootstrap.php \
  --app-root=/srv/workspace \
  --transaction=beta4-to-1-0-2026-001 \
  --state-root=/var/lib/notes/update-state \
  --backup-root=/var/lib/notes/update-backups \
  --recover \
  --json
```

Recovery следует durable journal. Transaction с failed/unverified rollback остаётся в maintenance; оператор не должен удалять marker только ради повторного открытия приложения.

## Release drill

`.github/workflows/beta4-upgrade-rollback-drill.yml` является release gate этой boundary. Он использует только ephemeral signing material CI и никогда не использует production private keys.

Workflow доказывает для exact `v0.14.0-beta.4`:

- опубликованный Beta4 tag/commit действительно не содержит signed updater runtime;
- две установки Beta4 создаются через реальный HTTP installer на MySQL;
- текущий candidate 1.0 упаковывается с drill target version `1.0.0 / 10000` и подписывается ephemeral Ed25519 update key;
- normal transaction достигает `committed`, проходит health/migration checks и сохраняет существующие settings/data;
- второй signed candidate намеренно ломается только на post-switch healthcheck после выполнения migrations;
- automatic rollback восстанавливает код Beta4 и pre-update MySQL snapshot, удаляет licensing state только 1.0, достигает `rollback_verified` и снимает maintenance;
- неверный source-version pin отклоняется до создания maintenance/backup.

Drill key генерируется под `/tmp`, его public half внедряется только в копию trust registry CI runner, а private key удаляется до начала любой live transaction.
