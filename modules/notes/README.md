# Runtime ownership модуля Notes

Каталог `modules/notes/` содержит изолированный runtime модуля Notes. Миграция из общего legacy `app/*` завершена; manifest должен оставаться:

```text
runtime.mode = isolated
runtime.entrypoint = runtime.php
```

Модулю Notes принадлежат:

- HTTP routes списка, создания, редактирования и удаления заметок;
- routes share/unshare/public share;
- upload/download/delete/shared-download вложений;
- controllers/services/models/policies Notes;
- views и assets модуля;
- private-storage namespace `notes`;
- schema/migration ownership metadata;
- capability `workspace.notes`.

Cross-module dependencies должны оставаться явными platform/module contracts. Нельзя подключать internal files другого module напрямую или возвращать Notes product runtime в общие `app/controllers`, `app/services`, `app/models` или `core/`.

Module-owned views/assets обслуживаются через isolated runtime boundary и общие безопасные renderer/asset contracts.
