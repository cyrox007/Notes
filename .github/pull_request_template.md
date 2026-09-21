## Объём изменений

<!-- Что меняется и что намеренно не входит в этот PR? -->

## Checklist релиза

- [ ] У PR одна сфокусированная задача и нет несвязанных refactor.
- [ ] `Master release gate` и релевантные module/browser workflows проходят на текущем head.
- [ ] Изменения, влияющие на browser, покрыты существующим lifecycle test либо PR добавляет/обновляет такой test.
- [ ] Для URL, redirects и assets учтены root install и `BASE_PATH=/workspace/`.
- [ ] Видимый пользователю success выдаётся только после успешной durable DB/storage операции.
- [ ] Изменения БД соответствуют `docs/DB_ARCHITECTURE.md`: canonical `*_schema.sql` описывает текущую схему, compatibility upgrade SQL используется только для ранее установленных legacy-форм.
- [ ] Изменения storage lifecycle сохраняют гарантии reconciliation/cleanup между DB и filesystem.
- [ ] Security-sensitive изменения сохраняют проверки `role + is_active`, CSRF и ownership/access.
- [ ] Учтено влияние на документацию и changelog.

## Политика review

- [ ] Branch актуальна относительно `master` перед merge.
- [ ] Если есть другой квалифицированный участник, присутствует хотя бы один независимый approval.
- [ ] Не планируется administrator bypass для падающего required check.

## Проверка

<!-- Перечислите конкретные CI jobs, локальные команды или browser flows, использованные для проверки. -->
