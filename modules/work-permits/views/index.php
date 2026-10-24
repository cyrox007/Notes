<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$baseUrl = rtrim((string) ($base_url ?? ''), '/');
ob_start();
?>
<main class="wp-journal" data-work-permits data-base-url="<?= $view->e($baseUrl) ?>">
    <?= $csrf_input ?? '' ?>
    <header class="module-page-header">
        <h1>Наряды и допуски</h1>
        <p>Регистрация черновиков нарядов-допусков и распоряжений. Выдача и закрытие будут подключены отдельным этапом.</p>
    </header>
    <p data-wp-feedback role="status" aria-live="polite" hidden></p>
    <section class="calendar-shell wp-journal__editor" aria-labelledby="wp-form-title">
        <h2 id="wp-form-title">Новый документ</h2>
        <form data-wp-form>
            <label>Тип документа
                <select name="kind" required>
                    <option value="permit">Наряд-допуск</option>
                    <option value="order">Распоряжение</option>
                </select>
            </label>
            <label>Организация <input name="organization" maxlength="160" required></label>
            <label>Подразделение <input name="subdivision" maxlength="160"></label>
            <label>Объект / подстанция <input name="facility" maxlength="180" required></label>
            <label class="wp-journal__wide">Задание на производство работ
                <textarea name="work_description" maxlength="10000" rows="4" required></textarea>
            </label>
            <button type="submit" data-wp-submit>Сохранить черновик</button>
        </form>
        <p>Номер присваивается сервером. Черновик ещё не является выданным нарядом или распоряжением.</p>
    </section>
    <section class="calendar-shell wp-journal__registry" aria-labelledby="wp-registry-title">
        <h2 id="wp-registry-title">Мои документы за последний месяц</h2>
        <div data-wp-list aria-live="polite"></div>
        <button type="button" data-wp-more hidden>Показать ещё</button>
    </section>
</main>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Наряды и допуски',
    'user' => $user ?? [],
    'module_styles' => [$view->moduleAsset('work-permits', 'work-permits.css')],
    'module_scripts' => [$view->moduleAsset('work-permits', 'work-permits.js')],
], $content);
