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
    <button type="button" data-defects-refresh>Обновить</button>
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
