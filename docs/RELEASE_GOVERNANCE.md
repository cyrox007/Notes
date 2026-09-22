# Управление релизными ветками

Workspace Organizer использует `master` как опубликованную релизную ветку, а
`1.0` — как ветку стабилизации текущей линии 1.0.x.

Репозиторий хранит ожидаемую политику в
`.github/release-governance.json` и проверяет её тестами. **Настройки branch
protection живут вне истории Git** и должны быть применены в GitHub Settings
владельцем или администратором репозитория.

## Защита ветки 1.0

Перед финальной приёмкой ветка `1.0` должна:

1. принимать изменения только через pull request;
2. требовать актуальную базу перед merge;
3. требовать весь список `stabilization_required_checks` из
   `.github/release-governance.json`;
4. отклонять устаревшие approvals после новых push;
5. запрещать force-push и удаление ветки;
6. требовать одно независимое approval, если в репозитории существует другой
   участник с правом write/maintain/admin.

В 1.0.2 набор стабилизации совпадает с финальным набором `master`. Это
сознательно: результат разных workflow на разных SHA больше не считается
доказательством готовности одного релизного кандидата.

## Защита master

Для `master` применяется тот же обязательный набор checks. В него входят:

- `release-gate`;
- lifecycle браузерные проверки Notes, Tasks, Files, Profile и Admin;
- `storage-db-failure`;
- `browser-wss-e2e`;
- `release-evidence`;
- точный drill `1.0.1 -> 1.0.2 -> rollback`;
- Windows/PHP 8.1 и 8.3 compatibility;
- WebSocket deployment contract для PHP 8.1 и 8.3;
- `messenger-realtime-fallback`.

Каждый обязательный workflow обязан запускаться **на каждом** pull request в
`1.0` и `master`. Для pull_request-триггера запрещены `paths` и
`paths-ignore`: отсутствие запуска не должно выглядеть как успешная
проверка.

Матрицы Windows и WebSocket перечислены отдельными check contexts для каждой
версии PHP, потому что GitHub создаёт отдельный check run на каждый элемент
matrix.

## Правило merge

PR в `1.0` или `master` допускается к merge только когда:

- все обязательные checks успешны на текущем SHA;
- ветка актуальна относительно target;
- изменения БД соответствуют `docs/DB_ARCHITECTURE.md`;
- пользовательские операции с хранилищем не сообщают успех до durable write;
- не сломаны root-install и `BASE_PATH=/workspace/`;
- изменения браузерного поведения покрыты соответствующим lifecycle/E2E;
- при наличии независимого reviewer получено требуемое approval.

Обычный merge через admin bypass для красного или устаревшего PR не
используется. Аварийный bypass требует отдельной фиксации причины и
последующего корректирующего PR.

## Применение branch protection

В репозитории есть скрипт
`tools/release/apply-github-protection.sh`. Его запускает владелец или
администратор из доверенного checkout:

```bash
bash tools/release/apply-github-protection.sh cyrox007/Notes
```

Скрипт:

- читает required check IDs из `.github/release-governance.json`;
- применяет protection для `1.0` и `master`;
- включает strict/up-to-date status checks;
- запрещает force-push и удаление;
- включает dismiss stale reviews;
- распространяет правила на администратора;
- определяет наличие независимого direct collaborator и, если он есть,
  требует одно approval;
- после записи повторно читает protection и проверяет фактические значения.

Скрипт использует уже авторизованную сессию `gh`. Он не создаёт и не
сохраняет новые credentials.

## Почему часть политики находится вне Git

GitHub Actions и файлы репозитория не должны самостоятельно получать
Administration permission. Поэтому Git содержит ожидаемый контракт, а
применение protection остаётся явной операцией владельца.

Это разделяет две проверки:

- **source contract** — требуемые workflow существуют, всегда запускаются на
  релизных PR и перечислены в policy;
- **repository enforcement** — GitHub Settings действительно требуют именно
  эти checks.

Зелёный source contract не заменяет проверку второй части.

## Проверка

`tests/integration/release_governance_contract.php` подтверждает, что:

- policy содержит `master` и `1.0`;
- обе релизные ветки требуют одинаковый финальный набор checks;
- каждый check соответствует существующему workflow job;
- каждый обязательный workflow запускается для PR в `1.0` и `master`
  без path filters;
- exact `1.0.1 -> 1.0.2` drill поддерживает ручной запуск;
- release gate запускается для обеих релизных веток;
- PR template сохраняет подсказки по release/browser/database review.

Перед выпуском дополнительно выполняется
`tools/release/apply-github-protection.sh` и сохраняется его вывод как
доказательство A102-06.
