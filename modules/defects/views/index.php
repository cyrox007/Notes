<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$baseUrl = rtrim((string) ($base_url ?? ''), '/');
ob_start();
?>
<div id="defects-workspace" class="defects-workspace" data-base-url="<?= $view->e($baseUrl) ?>">
    <?= $csrf_input ?? '' ?>
    <header class="module-page-header defects-heading">
        <div>
            <h1>Дефекты</h1>
            <p>Реестр, сроки устранения и выполненные работы.</p>
        </div>
        <button type="button" class="defects-button defects-button--primary" data-defects-create-open>
            <i class="fa fa-plus" aria-hidden="true"></i> Зарегистрировать
        </button>
    </header>

    <div class="defects-feedback" data-defects-feedback role="status" aria-live="polite" hidden></div>
    <section class="defects-summary" aria-label="Состояние дефектов">
        <div class="defects-summary__item"><span>Открытые</span><strong data-defects-count-open>—</strong></div>
        <div class="defects-summary__item"><span>Просроченные</span><strong data-defects-count-overdue>—</strong></div>
        <div class="defects-summary__item"><span>Закрытые</span><strong data-defects-count-closed>—</strong></div>
    </section>

    <section class="calendar-shell defects-registry" aria-labelledby="defectsRegistryTitle">
        <header class="defects-registry__header">
            <div><h2 id="defectsRegistryTitle">Реестр</h2><p>Выберите запись, чтобы открыть карточку и историю.</p></div>
            <button type="button" class="defects-button defects-button--secondary" data-defects-refresh>
                <i class="fa fa-refresh" aria-hidden="true"></i> Обновить
            </button>
        </header>
        <div class="defects-segments" role="group" aria-label="Состояние записей">
            <button type="button" data-defects-tab="open" aria-pressed="true">Открытые</button>
            <button type="button" data-defects-tab="closed" aria-pressed="false">Закрытые</button>
            <button type="button" data-defects-tab="all" aria-pressed="false">Все</button>
        </div>
        <details class="defects-filters" data-defects-filters>
            <summary><i class="fa fa-sliders" aria-hidden="true"></i> Дополнительные фильтры</summary>
            <div class="defects-filter-grid">
                <label>Приоритет
                    <select data-defects-priority>
                        <option value="">Любой</option>
                        <option value="urgent">Неотложный</option>
                        <option value="first">Первоочередной</option>
                        <option value="important">Важный</option>
                        <option value="normal">Обычный</option>
                    </select>
                </label>
                <label>Участок / РЭС <select data-defects-filter-area><option value="">Все участки</option></select></label>
                <label>Объект (ID) <input type="number" min="1" data-defects-filter-object placeholder="Все объекты"></label>
                <label>Ответственная служба <select data-defects-filter-service><option value="">Все службы</option></select></label>
                <label>Обнаружен с <input type="date" data-defects-from></label>
                <label>По <input type="date" data-defects-to></label>
            </div>
            <button type="button" class="defects-button defects-button--secondary" data-defects-reset>Сбросить фильтры</button>
        </details>
        <div class="defects-progress" data-defects-loading role="status" hidden><span class="defects-spinner" aria-hidden="true"></span> Загружаем дефекты…</div>
        <div class="defects-results" data-defects-results aria-live="polite"></div>
        <footer class="defects-pagination">
            <button type="button" class="defects-button defects-button--secondary" data-defects-more hidden>Показать ещё</button>
        </footer>
    </section>

    <details class="calendar-shell defects-administration" <?= empty($can_manage_defects) ? 'hidden' : '' ?>>
        <summary><i class="fa fa-cog" aria-hidden="true"></i> Управление справочниками</summary>
        <div class="defects-admin-grid">
            <form data-defects-reference>
                <h3>Добавить запись</h3>
                <label>Справочник
                    <select name="kind">
                        <option value="areas">Участки / РЭС</option>
                        <option value="categories">Категории объектов</option>
                        <option value="objects">Объекты</option>
                        <option value="services">Ответственные службы</option>
                    </select>
                </label>
                <label data-defects-parent-area hidden>Участок для категории <select name="category_area"><option value="">Выберите участок</option></select></label>
                <label>Родительская запись <select name="parent_id" disabled><option value="">Не требуется</option></select></label>
                <label>Название <input name="name" maxlength="160" required></label>
                <button type="submit" class="defects-button defects-button--primary">Добавить</button>
            </form>
            <form data-defects-import>
                <h3>Импорт справочников</h3>
                <p>Файл UTF-8 до 512 КиБ и 200 записей. Формат строки: <code>area;Название</code>, <code>category;Участок;Категория</code>, <code>object;Участок;Категория;Объект</code> или <code>service;Название</code>.</p>
                <label>Файл <input type="file" name="file" accept=".txt,.csv,text/plain,text/csv" required></label>
                <div class="defects-form-actions">
                    <button type="button" class="defects-button defects-button--secondary" data-defects-import-preview>Предпросмотр</button>
                    <button type="button" class="defects-button defects-button--primary" data-defects-import-apply disabled>Применить</button>
                </div>
                <pre data-defects-import-result aria-live="polite"></pre>
            </form>
            <form data-defects-reference-history>
                <h3>История справочника</h3>
                <label>Справочник
                    <select name="kind">
                        <option value="areas">Участки / РЭС</option>
                        <option value="categories">Категории объектов</option>
                        <option value="objects">Объекты</option>
                        <option value="services">Ответственные службы</option>
                    </select>
                </label>
                <label>ID записи <input name="record_id" type="number" min="1" required></label>
                <button type="submit" class="defects-button defects-button--secondary">Посмотреть историю</button>
                <ol data-defects-reference-history-results class="defects-timeline" aria-live="polite"></ol>
            </form>
        </div>
    </details>

    <div class="defects-overlay" data-defects-create-overlay hidden>
        <button type="button" class="defects-overlay__backdrop" data-defects-create-cancel aria-label="Закрыть форму регистрации"></button>
        <section class="defects-panel" role="dialog" aria-modal="true" aria-labelledby="defectsCreateTitle" tabindex="-1">
            <header class="defects-panel__header">
                <div><span>Новая запись</span><h2 id="defectsCreateTitle">Регистрация дефекта</h2></div>
                <button type="button" class="defects-icon-button" data-defects-create-cancel aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button>
            </header>
            <form data-defects-create class="defects-form">
                <label>Участок / РЭС <select name="area" required><option value="">Выберите участок</option></select></label>
                <label>Категория объекта <select name="category" required><option value="">Выберите категорию</option></select></label>
                <label>Объект <select name="object_id" required><option value="">Выберите объект</option></select></label>
                <label>Приоритет
                    <select name="priority" required>
                        <option value="urgent">Неотложный · 1 день</option>
                        <option value="first">Первоочередной · 7 дней</option>
                        <option value="important">Важный · 14 дней</option>
                        <option value="normal" selected>Обычный · 30 дней</option>
                    </select>
                </label>
                <label>Дата обнаружения <input type="datetime-local" name="detected" required></label>
                <label>ФИО регистрирующего <input name="registered_by_name" maxlength="160" required></label>
                <label class="defects-field-wide">Описание <textarea name="description" rows="4" maxlength="10000" required></textarea></label>
                <fieldset class="defects-field-wide">
                    <legend>Ответственные службы</legend>
                    <div data-defects-services class="defects-service-options" aria-label="Выбор ответственных служб"></div>
                </fieldset>
                <div data-defects-create-error class="defects-form-error" role="alert" hidden></div>
                <footer class="defects-form-actions defects-field-wide">
                    <button type="button" class="defects-button defects-button--secondary" data-defects-create-cancel>Отмена</button>
                    <button type="submit" class="defects-button defects-button--primary">Зарегистрировать</button>
                </footer>
            </form>
        </section>
    </div>

    <div class="defects-overlay" data-defects-detail-overlay hidden>
        <button type="button" class="defects-overlay__backdrop" data-defects-detail-close aria-label="Закрыть карточку"></button>
        <section class="defects-panel" role="dialog" aria-modal="true" aria-labelledby="defectsDetailTitle" tabindex="-1">
            <header class="defects-panel__header">
                <div><span>Карточка дефекта</span><h2 id="defectsDetailTitle" data-defects-detail-title>Загрузка…</h2></div>
                <button type="button" class="defects-icon-button" data-defects-detail-close aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button>
            </header>
            <div class="defects-detail-body">
                <div data-defects-detail-loading class="defects-progress" role="status">Загрузка карточки…</div>
                <div data-defects-detail-content hidden>
                    <dl class="defects-detail-list" data-defects-detail-fields></dl>
                    <h3>История изменений</h3>
                    <ol data-defects-history-list class="defects-timeline"></ol>
                    <button type="button" class="defects-button defects-button--secondary" data-defects-history-more hidden>Ранее не показанные события</button>
                    <form data-defects-close class="defects-close-form" hidden>
                        <h3>Закрыть дефект</h3>
                        <label>Выполненные работы
                            <textarea name="completed_work" minlength="1" maxlength="10000" rows="5" required placeholder="Опишите, как устранён дефект"></textarea>
                        </label>
                        <p>После закрытия запись останется в истории.</p>
                        <div data-defects-close-error class="defects-form-error" role="alert" hidden></div>
                        <button type="submit" class="defects-button defects-button--primary">Подтвердить закрытие</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Дефекты',
    'user' => $user ?? [],
    'module_styles' => [$view->moduleAsset('defects', 'defects.css')],
    'module_scripts' => [$view->moduleAsset('defects', 'defects.js')],
], $content);
