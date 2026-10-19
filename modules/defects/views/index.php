<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$baseUrl = rtrim((string) ($base_url ?? ''), '/');
ob_start();
?>
<section class="module-page-header">
    <div>
        <h1>Дефекты</h1>
        <p>Регистрация, контроль сроков и закрытие обнаруженных дефектов.</p>
    </div>
</section>
<section class="calendar-shell" id="defects-workspace" data-base-url="<?= $view->e($baseUrl) ?>">
    <?= $csrf_input ?? '' ?>
    <div role="status" aria-live="polite" data-defects-feedback></div>
    <h2>Реестр дефектов</h2>
    <label>Состояние
        <select data-defects-status>
            <option value="open">Открытые</option>
            <option value="closed">Закрытые</option>
            <option value="all">Все</option>
        </select>
    </label>
    <label>Приоритет
        <select data-defects-priority>
            <option value="">Все</option>
            <option value="urgent">Неотложный</option>
            <option value="first">Первоочередной</option>
            <option value="important">Важный</option>
            <option value="normal">Обычный</option>
        </select>
    </label>
    <label>Участок / РЭС <select data-defects-filter-area><option value="">Все участки</option></select></label>
    <label>Объект (ID) <input type="number" min="1" data-defects-filter-object placeholder="Все объекты"></label>
    <label>Ответственная служба <select data-defects-filter-service><option value="">Все службы</option></select></label>
    <label>От <input type="date" data-defects-from></label>
    <label>До <input type="date" data-defects-to></label>
    <button type="button" data-defects-refresh>Обновить</button>
    <section aria-live="polite">
        <h3>Сводка</h3>
        <div data-defects-summary></div>
    </section>
    <div data-defects-results aria-live="polite"></div>
    <button type="button" data-defects-more hidden>Показать ещё</button>
</section>
<section class="calendar-shell">
    <h2>Зарегистрировать дефект</h2>
    <form data-defects-create>
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
        <label>Описание
            <textarea name="description" rows="4" maxlength="10000" required></textarea>
        </label>
        <label>Ответственные службы
            <select name="service_ids" multiple size="5" required></select>
        </label>
        <p>Для выбора нескольких служб удерживайте Ctrl или Cmd.</p>
        <button type="submit">Зарегистрировать</button>
    </form>
</section>
<?php if (!empty($can_manage_defects)): ?>
<section class="calendar-shell">
    <h2>Справочники</h2>
    <form data-defects-import>
        <h3>Импорт справочников из текстового файла</h3>
        <p>UTF-8, разделитель «;», одна запись в строке. Форматы:
            <code>area;Участок</code>,
            <code>category;Участок;Категория</code>,
            <code>object;Участок;Категория;Объект</code>,
            <code>service;Служба</code>.
            Не более 200 записей и 512 КиБ.
        </p>
        <label>Файл <input type="file" name="file" accept=".txt,.csv,text/plain,text/csv" required></label>
        <button type="button" data-defects-import-preview>Предварительный просмотр</button>
        <button type="button" data-defects-import-apply disabled>Применить импорт</button>
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
        <label>Идентификатор записи <input name="record_id" type="number" min="1" required></label>
        <button type="submit">Показать историю</button>
        <ul data-defects-reference-history-results aria-live="polite"></ul>
    </form>
    <form data-defects-reference>
        <label>Тип справочника
            <select name="kind">
                <option value="areas">Участок / РЭС</option>
                <option value="categories">Категория объекта</option>
                <option value="objects">Объект</option>
                <option value="services">Ответственная служба</option>
            </select>
        </label>
        <label>Родительская запись
            <select name="parent_id"><option value="">Не требуется</option></select>
        </label>
        <label>Название <input name="name" maxlength="160" required></label>
        <button type="submit">Добавить в справочник</button>
    </form>
</section>
<?php endif ?>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Дефекты',
    'user' => $user ?? [],
    'module_scripts' => [$view->moduleAsset('defects', 'defects.js')],
], $content);
