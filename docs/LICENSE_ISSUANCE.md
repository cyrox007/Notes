# Выпуск лицензии для одной установки

Эта инструкция описывает штатный выпуск одной installation-bound лицензии Workspace Organizer 1.0.

## Что нужно получить от установки

На целевой установке суперадминистратор открывает:

```text
/system/license
```

и копирует `Installation ID`.

Также ID можно получить локально:

```bash
php bin/control.php license status --json
```

Для выпуска нужен именно полный UUID установки, например:

```text
12345678-1234-4234-8234-1234567890ab
```

Лицензия привязывается к этому ID. Ключ от другой установки будет отклонён с кодом `wrong_installation`.

## Где должен находиться private key

Production private key лицензирования хранится только во внешнем защищённом каталоге vendor/operator и не должен попадать:

- в Git;
- в каталог приложения;
- в `.env`;
- в CI artifacts;
- в release ZIP;
- в issue/chat/log output;
- на сервер клиента.

Текущий production key ID:

```text
prod-license-2026-01
```

Соответствующий public key уже входит в `config/license_trusted_keys.php`.

## Выпуск бессрочной лицензии

Из доверенного checkout Workspace Organizer выполните:

```powershell
$PHP = "D:\РЕЗЕРВНАЯ КОПИЯ\OSPanel\modules\php\PHP_8.1\php.exe"
$SECURE = "D:\secure\workspace-signing"

$InstallationId = "ВСТАВЬТЕ-INSTALLATION-ID"
$LicenseId = "lic-customer-001"

& $PHP tools\vendor-license\issue.php `
  --private-key="$SECURE\prod-license-2026-01.license-secret" `
  --key-id=prod-license-2026-01 `
  --installation-id=$InstallationId `
  --license-id=$LicenseId `
  --edition=team `
  --max-users=20 `
  --features=workspace.notes,workspace.tasks,workspace.files,workspace.messenger,workspace.profile,workspace.admin
```

Команда выводит одну строку вида:

```text
wo1.prod-license-2026-01.<payload>.<signature>
```

Это и есть лицензионный ключ, который передаётся клиенту.

Не публикуйте токен в Git или публичных логах. Private key команда не печатает и не копирует.

## Лицензия с ограниченным сроком

Добавьте `--expires-at=<UNIX_TIMESTAMP>`.

Пример:

```powershell
& $PHP tools\vendor-license\issue.php `
  --private-key="$SECURE\prod-license-2026-01.license-secret" `
  --key-id=prod-license-2026-01 `
  --installation-id=$InstallationId `
  --license-id=$LicenseId `
  --edition=team `
  --expires-at=1798761600 `
  --max-users=20 `
  --features=workspace.notes,workspace.tasks,workspace.files,workspace.messenger,workspace.profile,workspace.admin
```

Без `--expires-at` лицензия бессрочная.

При необходимости можно также использовать:

- `--not-before=<UNIX_TIMESTAMP>` — лицензия начнёт действовать не раньше указанного времени;
- `--customer="Название клиента"` — подпись клиента в payload;
- `--features=...` — список разрешённых features в подписанном payload;
- `--max-users=N` — максимальное количество активных аккаунтов в установке. Заблокированный аккаунт занимает место, деактивированный (`is_active=0`) освобождает его. Если параметр не указан, лимит пользователей отсутствует.

## Проверка до передачи клиенту

Проверяйте выданный токен локально на том же installation ID:

```powershell
$LicenseToken = (& $PHP tools\vendor-license\issue.php `
  --private-key="$SECURE\prod-license-2026-01.license-secret" `
  --key-id=prod-license-2026-01 `
  --installation-id=$InstallationId `
  --license-id=$LicenseId `
  --edition=team `
  --max-users=20 `
  --features=workspace.notes,workspace.tasks,workspace.files,workspace.messenger,workspace.profile,workspace.admin).Trim()

$env:LICENSE_TOKEN = $LicenseToken
$env:INSTALLATION_ID = $InstallationId

& $PHP -r 'require "app/services/LicenseVerifier.php"; $v=new App\Services\LicenseVerifier(); $s=$v->verify(getenv("LICENSE_TOKEN"), getenv("INSTALLATION_ID")); $p=$s["payload"]??[]; if (!($s["valid"]??false) || ($p["edition"]??"")!=="team" || ($p["max_users"]??null)!==20) { fwrite(STDERR, json_encode($s, JSON_UNESCAPED_UNICODE).PHP_EOL); exit(1); } echo "LICENSE OK: team / max_users=20".PHP_EOL;'

Remove-Item Env:LICENSE_TOKEN
Remove-Item Env:INSTALLATION_ID
```

Ожидаемый результат:

```text
LICENSE OK: team / max_users=20
```

## Активация на установке клиента

Через web UI:

1. войти под superadmin;
2. открыть `/system/license`;
3. вставить полученный `wo1...` токен;
4. нажать «Проверить и активировать».

Либо локально через CLI:

```bash
php bin/control.php license activate --stdin
```

или:

```bash
php bin/control.php license activate --token-file=/secure/path/license.txt
```

После успешной активации токен хранится в `system_settings`, а проверка выполняется локально по production public key.

## Что проверяет приложение

При каждой проверке лицензии валидируются:

- формат `wo1.<key-id>.<payload>.<signature>`;
- известный production key ID;
- Ed25519 signature;
- версия payload;
- точное совпадение `installation_id`;
- `issued_at`;
- `not_before`, если указан;
- `expires_at`, если указан;
- `max_users`, если указан: лимит подписан тем же Ed25519 ключом и не может быть изменён без нарушения подписи.

Если лицензия отсутствует или недействительна, приложение не удаляет данные. Установка переходит в read-only: чтение остаётся доступным, а обычные изменения данных блокируются. Recovery-операции лицензии остаются доступны.

## Лимит пользователей

При наличии `max_users` система считает аккаунты с `is_active=1`. Создание пользователя через Admin и self-registration, а также повторная активация деактивированного аккаунта блокируются, когда лимит исчерпан.

Активация нового ключа также отклоняется, если его `max_users` меньше текущего количества активных аккаунтов. Это предотвращает случайный downgrade лицензии ниже фактического использования.

Пример тарифов:

```text
Personal   --max-users=5
Team       --max-users=20
Business   --max-users=100
Enterprise параметр не указывается для unlimited
```

Старые корректные лицензии без `max_users` остаются совместимыми и считаются unlimited.

## Перенос на другую установку

Лицензия не переносится простым копированием токена. Для нового `Installation ID` выпускается новый подписанный токен.

Старый токен можно считать отозванным на организационном уровне, однако текущая offline-модель не требует сетевой проверки vendor-сервера и не поддерживает мгновенный удалённый revoke уже выданного бессрочного токена. Если нужна централизованная online-деактивация, это отдельное развитие licensing backend после 1.0.
