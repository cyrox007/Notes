<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';

ob_start();
?>
<section class="module-page-header calendar-page-header">
    <div>
        <h1>Календарь</h1>
        <p>События, повторения и напоминания в одном расписании.</p>
    </div>
    <button type="button" class="calendar-primary" data-calendar-create>
        <i class="fa fa-plus" aria-hidden="true"></i><span>Новое событие</span>
    </button>
</section>

<section class="calendar-shell" data-calendar-root>
    <header class="calendar-toolbar">
        <div class="calendar-toolbar__navigation">
            <button type="button" class="calendar-icon-button" data-calendar-prev aria-label="Предыдущий период"><i class="fa fa-chevron-left" aria-hidden="true"></i></button>
            <button type="button" class="calendar-secondary" data-calendar-today>Сегодня</button>
            <button type="button" class="calendar-icon-button" data-calendar-next aria-label="Следующий период"><i class="fa fa-chevron-right" aria-hidden="true"></i></button>
            <h2 data-calendar-period>Календарь</h2>
        </div>
        <div class="calendar-view-switch" role="group" aria-label="Режим календаря">
            <button type="button" data-calendar-view="month" aria-pressed="true">Месяц</button>
            <button type="button" data-calendar-view="week" aria-pressed="false">Неделя</button>
            <button type="button" data-calendar-view="day" aria-pressed="false">День</button>
        </div>
    </header>

    <div class="calendar-feedback" data-calendar-feedback hidden></div>
    <div class="calendar-loading" data-calendar-loading hidden><span></span><span>Обновляем расписание…</span></div>
    <div class="calendar-surface" data-calendar-surface aria-live="polite"></div>
</section>

<div class="calendar-editor" data-calendar-editor hidden>
    <button type="button" class="calendar-editor__backdrop" data-calendar-close aria-label="Закрыть редактор"></button>
    <section class="calendar-editor__panel" role="dialog" aria-modal="true" aria-labelledby="calendarEditorTitle">
        <header class="calendar-editor__header">
            <div>
                <span class="calendar-editor__eyebrow">Calendar</span>
                <h2 id="calendarEditorTitle" data-calendar-editor-title>Новое событие</h2>
            </div>
            <button type="button" class="calendar-icon-button" data-calendar-close aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button>
        </header>

        <form class="calendar-form" data-calendar-form novalidate>
            <input type="hidden" data-field="uid">
            <label class="calendar-field calendar-field--wide">
                <span>Название</span>
                <input type="text" maxlength="190" data-field="title" required placeholder="Например, встреча с командой">
            </label>

            <div class="calendar-form__row">
                <label class="calendar-field">
                    <span>Начало</span>
                    <input type="datetime-local" data-field="starts_at" required>
                </label>
                <label class="calendar-field">
                    <span>Окончание</span>
                    <input type="datetime-local" data-field="ends_at">
                </label>
            </div>

            <div class="calendar-form__row calendar-form__row--compact">
                <label class="calendar-check"><input type="checkbox" data-field="all_day"><span>Весь день</span></label>
                <label class="calendar-field calendar-field--timezone">
                    <span>Часовой пояс</span>
                    <input type="text" maxlength="64" data-field="timezone" required placeholder="Europe/Stockholm">
                </label>
            </div>

            <div class="calendar-form__row">
                <label class="calendar-field">
                    <span>Место</span>
                    <input type="text" maxlength="255" data-field="location" placeholder="Офис, кабинет, ссылка…">
                </label>
                <label class="calendar-field">
                    <span>Повторять</span>
                    <select data-field="frequency">
                        <option value="none">Не повторять</option>
                        <option value="daily">Каждый день</option>
                        <option value="weekly">Каждую неделю</option>
                        <option value="monthly">Каждый месяц</option>
                        <option value="yearly">Каждый год</option>
                    </select>
                </label>
            </div>

            <section class="calendar-recurrence" data-calendar-recurrence hidden>
                <div class="calendar-form__row">
                    <label class="calendar-field">
                        <span>Интервал</span>
                        <input type="number" min="1" max="365" value="1" data-field="interval">
                    </label>
                    <label class="calendar-field">
                        <span>Повторять до</span>
                        <input type="date" data-field="until">
                    </label>
                </div>
                <div class="calendar-weekdays" data-calendar-weekdays hidden aria-label="Дни недели">
                    <?php foreach (['MO' => 'Пн', 'TU' => 'Вт', 'WE' => 'Ср', 'TH' => 'Чт', 'FR' => 'Пт', 'SA' => 'Сб', 'SU' => 'Вс'] as $code => $label): ?>
                        <label><input type="checkbox" value="<?= $view->e($code) ?>" data-weekday><span><?= $view->e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </section>

            <label class="calendar-field calendar-field--wide">
                <span>Описание</span>
                <textarea rows="4" maxlength="5000" data-field="description" placeholder="Контекст, подготовка, ссылки и заметки…"></textarea>
            </label>

            <fieldset class="calendar-reminders">
                <legend>Напоминить</legend>
                <?php foreach ([0 => 'В момент начала', 300 => 'За 5 минут', 900 => 'За 15 минут', 3600 => 'За 1 час', 86400 => 'За 1 день'] as $offset => $label): ?>
                    <label><input type="checkbox" value="<?= (int) $offset ?>" data-reminder-offset><span><?= $view->e($label) ?></span></label>
                <?php endforeach; ?>
            </fieldset>

            <div class="calendar-form__feedback" data-calendar-form-feedback hidden></div>
            <footer class="calendar-form__actions">
                <button type="button" class="calendar-danger" data-calendar-delete hidden>Удалить</button>
                <span class="calendar-form__spacer"></span>
                <button type="button" class="calendar-secondary" data-calendar-close>Отмена</button>
                <button type="submit" class="calendar-primary" data-calendar-save>Сохранить</button>
            </footer>
        </form>
    </section>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Календарь',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'module_styles' => [$view->moduleAsset('calendar', 'calendar.css')],
    'module_scripts' => [$view->moduleAsset('calendar', 'calendar.js')],
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
