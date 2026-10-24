<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$baseUrl = rtrim((string) ($base_url ?? ''), '/');
ob_start();
?>
<main class="wp-journal" data-work-permits data-base-url="<?= $view->e($baseUrl) ?>" data-wp-can-manage="<?= !empty($can_manage_references) ? '1' : '0' ?>">
    <?= $csrf_input ?? '' ?>
    <header class="module-page-header">
        <h1>Наряды и допуски</h1>
        <p>Журнал нарядов и распоряжений: выдача, допуски, перерывы, продления, закрытие и печать наряда в PDF.</p>
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
            <label>Организация
                <select name="organization_id" data-wp-organization required><option value="">Загрузка организаций…</option></select>
            </label>
            <label>Подразделение
                <select name="department_id" data-wp-department><option value="">Не выбрано</option></select>
            </label>
            <label>Объект / подстанция
                <select name="facility_id" data-wp-facility required><option value="">Выберите объект</option></select>
            </label>
            <div class="wp-journal__template-picker wp-journal__wide" data-wp-job-picker="new">
                <label>Шаблон задания <select data-wp-job-choice><option value="">Выберите организацию</option></select></label>
                <button type="button" data-wp-apply-job>Подставить задание</button>
            </div>
            <label class="wp-journal__wide">Задание на производство работ
                <textarea name="work_description" maxlength="10000" rows="4" required></textarea>
            </label>
            <button type="submit" data-wp-submit>Сохранить черновик</button>
        </form>
        <p>Номер присваивается сервером. Черновик ещё не является выданным нарядом или распоряжением.</p>
    </section>

    <section class="calendar-shell wp-journal__editor" data-wp-editor hidden aria-labelledby="wp-edit-title">
        <h2 id="wp-edit-title" data-wp-edit-title>Изменить черновик</h2>
        <p>Карточка и состав бригады сохраняются вместе. При изменении в другой вкладке загрузите актуальную версию.</p>
        <form data-wp-edit-form>
            <label>Организация <select name="organization_id" data-wp-edit-org required><option value="">Выберите организацию</option></select></label>
            <label>Подразделение <select name="department_id" data-wp-edit-dept><option value="">Не выбрано</option></select></label>
            <label>Объект <select name="facility_id" data-wp-edit-facility required><option value="">Выберите объект</option></select></label>
            <div class="wp-journal__template-picker wp-journal__wide" data-wp-job-picker="edit">
                <label>Шаблон задания <select data-wp-job-choice><option value="">Выберите организацию</option></select></label>
                <button type="button" data-wp-apply-job>Подставить задание</button>
            </div>
            <label class="wp-journal__wide">Задание <textarea name="work_description" maxlength="10000" rows="4" required></textarea></label>
            <div class="wp-journal__template-picker wp-journal__wide" data-wp-crew-picker>
                <label>Шаблон бригады <select data-wp-crew-choice><option value="">Выберите организацию</option></select></label>
                <button type="button" data-wp-apply-crew>Подставить состав бригады</button>
            </div>
            <fieldset class="wp-journal__wide wp-journal__crew">
                <legend>Состав бригады</legend>
                <p>Сотрудник может выполнять несколько ролей. Допускающий по умолчанию — оперативный персонал; наблюдающий — не назначается.</p>
                <label>Выдавший документ <select name="role_issuer" data-wp-role><option value="">Не выбран</option></select></label>
                <label>Ответственный руководитель <select name="role_responsible_manager" data-wp-role><option value="">Не назначен</option></select></label>
                <label>Допускающий <select name="role_admitter" data-wp-role><option value="">Оперативный персонал</option></select></label>
                <label>Производитель работ <select name="role_producer" data-wp-role><option value="">Не выбран</option></select></label>
                <label>Наблюдающий <select name="role_observer" data-wp-role><option value="">Не назначается</option></select></label>
                <label>Члены бригады (множественный выбор) <select name="member_ids" data-wp-members multiple size="6"></select></label>
            </fieldset>
            <div class="wp-journal__actions wp-journal__wide">
                <button type="submit" data-wp-edit-submit>Сохранить изменения</button>
                <button type="button" data-wp-edit-cancel>Отменить</button>
            </div>
        </form>
    </section>

    <?php if (!empty($can_manage_references)): ?>
    <section class="calendar-shell wp-journal__registry" data-wp-management>
        <h2>Журнал на выдачу и первичный допуск</h2>
        <p>Показываются документы разных авторов. Операции требуют разрешения на управление модулем.</p>
        <label>Состояние
            <select data-wp-management-state>
                <option value="draft">Черновики для выдачи</option>
                <option value="issued">Выданные документы</option>
                <option value="closed">Закрытые</option>
            </select>
        </label>
        <div data-wp-management-list aria-live="polite"></div>
        <button type="button" data-wp-management-more hidden>Показать ещё</button>
    </section>
    <section class="calendar-shell wp-journal__editor" data-wp-lifecycle hidden aria-label="Выдача и первичный допуск">
        <h2 data-wp-lifecycle-title>Выдача документа</h2>
        <p>Операция фиксирует действие пользователя системы. Электронной подписью работника она не является. Фактическую подготовку места и инструктаж подтверждайте только после их выполнения.</p>
        <form data-wp-issue-form hidden>
            <label>Плановое начало (местное время)
                <input type="datetime-local" name="planned_start" required>
            </label>
            <label>Плановое окончание (местное время)
                <input type="datetime-local" name="planned_finish" required>
            </label>
            <div class="wp-journal__template-picker wp-journal__wide" data-wp-issue-template="conditions">
                <label>Шаблон условий производства работ
                    <select data-wp-issue-choice><option value="">Выберите организацию</option></select>
                </label>
                <button type="button" data-wp-issue-apply>Подставить условия</button>
            </div>
            <label class="wp-journal__wide">Условия производства работ
                <textarea name="work_conditions" maxlength="6000" rows="4"></textarea>
            </label>
            <div class="wp-journal__template-picker wp-journal__wide" data-wp-issue-template="instructions">
                <label>Шаблон отдельных указаний
                    <select data-wp-issue-choice><option value="">Выберите организацию</option></select>
                </label>
                <button type="button" data-wp-issue-apply>Подставить указания</button>
            </div>
            <label class="wp-journal__wide">Отдельные указания
                <textarea name="special_instructions" maxlength="4000" rows="3"></textarea>
            </label>
            <fieldset class="wp-journal__wide" data-wp-measures>
                <legend>Меры безопасности и подготовка рабочего места</legend>
                <p>Укажите отдельные мероприятия. Если отключения не нужны, явно опишите почему.</p>
                <div data-wp-measure-list></div>
                <button type="button" data-wp-add-measure>Добавить мероприятие</button>
            </fieldset>
            <div class="wp-journal__actions wp-journal__wide">
                <button type="submit" data-wp-issue-submit>Выдать документ</button>
                <button type="button" data-wp-lifecycle-cancel>Отмена</button>
            </div>
        </form>
        <form data-wp-admission-form hidden>
            <label>Фактическое начало работ (местное время)
                <input type="datetime-local" name="started_at" required>
            </label>
            <label>Допускающий
                <select name="admitted_employee_id" required></select>
            </label>
            <label>Инструктаж провёл
                <select name="briefed_by_employee_id" required></select>
            </label>
            <label>Инструктаж получил (производитель работ)
                <select name="briefing_recipient_id" required></select>
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="workplace_prepared" required>
                Рабочее место фактически подготовлено
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="briefing_confirmed" required>
                Целевой инструктаж фактически проведён и получен
            </label>
            <div class="wp-journal__actions wp-journal__wide">
                <button type="submit" data-wp-admission-submit>Зафиксировать первый допуск</button>
                <button type="button" data-wp-lifecycle-cancel>Отмена</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <?php if (!empty($can_manage_references)): ?>
    <section class="calendar-shell wp-journal__editor" data-wp-closure hidden>
        <h2 data-wp-closure-title>Завершение и закрытие</h2>
        <p>Действия фиксируют события в Notes, но не заменяют собственноручные подписи или обязательное оформление наряда.</p>
        <div data-wp-closure-history aria-live="polite"></div>
        <form data-wp-complete-form hidden>
            <h3>Полное окончание работ по наряду</h3>
            <label>Фактическое время окончания (местное время)
                <input type="datetime-local" name="completed_at" required>
            </label>
            <label>Производитель работ
                <select name="producer_employee_id" required></select>
            </label>
            <label class="wp-journal__wide">Примечание
                <textarea name="note" maxlength="1000" rows="3"></textarea>
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="workplace_safe" required>
                Рабочее место приведено в безопасное состояние
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="crew_withdrawn" required>
                Бригада выведена с рабочего места
            </label>
            <button type="submit">Зафиксировать окончание работ</button>
        </form>
        <form data-wp-final-close-form hidden>
            <h3 data-wp-final-close-label>Окончательно закрыть документ</h3>
            <label>Дата и время закрытия (местное время)
                <input type="datetime-local" name="closed_at" required>
            </label>
            <label>Закрывающий работник
                <select name="closer_employee_id" required></select>
            </label>
            <label class="wp-journal__wide">Примечание
                <textarea name="note" maxlength="1000" rows="3"></textarea>
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="workplace_accepted" required>
                Состояние рабочего места проверено
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="documents_handed_over" required>
                Оформленные документы переданы
            </label>
            <button type="submit" data-wp-final-close-submit>Закрыть документ</button>
        </form>
        <button type="button" data-wp-closure-cancel>Вернуться в журнал</button>
    </section>
    <?php endif; ?>

    <?php if (!empty($can_manage_references)): ?>
    <section class="calendar-shell wp-journal__editor" data-wp-amendments hidden>
        <h2 data-wp-amendments-title>Изменения выданного наряда</h2>
        <p>Изменения оформляются между рабочими периодами. Все прежние сроки и составы сохраняются в истории.</p>
        <div data-wp-amendments-history aria-live="polite"></div>
        <form data-wp-crew-change>
            <h3>Изменить членов бригады</h3>
            <p>Выдавший, производитель и другие ответственные роли не изменяются.</p>
            <label>Дата и время изменения (местное время)
                <input type="datetime-local" name="changed_at" required>
            </label>
            <label>Причина изменения
                <textarea name="reason" maxlength="1000" required></textarea>
            </label>
            <label class="wp-journal__wide">Члены бригады (несколько значений)
                <select name="member_ids" data-wp-amendment-members multiple size="7" required></select>
            </label>
            <button type="submit">Сохранить состав бригады</button>
        </form>
        <form data-wp-extension>
            <h3>Продлить срок наряда</h3>
            <label>Дата и время оформления (местное время)
                <input type="datetime-local" name="extended_at" required>
            </label>
            <label>Новая дата окончания (местное время)
                <input type="datetime-local" name="new_finish" required>
            </label>
            <label>Причина продления
                <textarea name="reason" maxlength="1000" required></textarea>
            </label>
            <label>Выдавший наряд
                <select name="issuer_employee_id" data-wp-extension-issuer required></select>
            </label>
            <button type="submit">Зарегистрировать продление</button>
        </form>
        <button type="button" data-wp-amendments-close>Закрыть карточку</button>
    </section>
    <?php endif; ?>

    <?php if (!empty($can_manage_references)): ?>
    <section class="calendar-shell wp-journal__editor" data-wp-daily-panel hidden>
        <h2 data-wp-daily-title>Рабочие смены наряда</h2>
        <p>Сведения отражают фактические допуски и остановки работ. Запись в Notes не заменяет подписи работников в официальном наряде.</p>
        <div data-wp-period-list aria-live="polite"></div>
        <form data-wp-period-start hidden>
            <h3 data-wp-period-start-title>Повторный допуск</h3>
            <label>Дата и время фактического допуска (местное время)
                <input type="datetime-local" name="started_at" required>
            </label>
            <label>Допускающий
                <select name="admitted_employee_id" required></select>
            </label>
            <label>Инструктаж провёл
                <select name="briefed_by_employee_id" required></select>
            </label>
            <label>Инструктаж получил (производитель)
                <select name="briefing_recipient_id" required></select>
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="workplace_prepared" required>
                Рабочее место повторно проверено и подготовлено
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="briefing_confirmed" required>
                Инструктаж в эту смену фактически проведён
            </label>
            <button type="submit" data-wp-period-submit>Записать допуск</button>
        </form>
        <form data-wp-period-stop hidden>
            <h3>Остановка работ</h3>
            <label>Дата и время фактической остановки (местное время)
                <input type="datetime-local" name="ended_at" required>
            </label>
            <label>Вид события
                <select name="end_kind" required>
                    <option value="break">Перерыв в течение дня</option>
                    <option value="day_end">Завершение рабочего дня</option>
                </select>
            </label>
            <label class="wp-journal__wide">Причина и принятые меры
                <textarea name="note" maxlength="1000" rows="3"></textarea>
            </label>
            <label class="wp-journal__wide"><input type="checkbox" name="work_stopped" required>
                Работы фактически остановлены, рабочее место приведено в безопасное состояние
            </label>
            <button type="submit" data-wp-period-submit>Зафиксировать остановку</button>
        </form>
        <button type="button" data-wp-period-close>Закрыть карточку</button>
    </section>
    <?php endif; ?>

    <?php if (!empty($can_manage_references)): ?>
    <details class="calendar-shell wp-journal__registry" data-wp-admin>
        <summary>Управление справочниками</summary>
        <p>Названия можно изменять; старые документы сохраняют название на момент регистрации. Удаления нет — используйте архив.</p>
        <form data-wp-reference-form>
            <label>Справочник
                <select name="kind" data-wp-kind>
                    <option value="organizations">Организации</option>
                    <option value="departments">Подразделения</option>
                    <option value="facilities">Объекты</option>
                    <option value="employees">Сотрудники</option>
                </select>
            </label>
            <label data-wp-parent-field hidden>Организация
                <select name="organization_id" data-wp-admin-org><option value="">Выберите организацию</option></select>
            </label>
            <label data-wp-employee-field hidden>Подразделение сотрудника
                <select name="department_id" data-wp-admin-dept><option value="">Без подразделения</option></select>
            </label>
            <label>Название / ФИО <input name="name" maxlength="180" required></label>
            <label data-wp-group-field hidden>Группа электробезопасности
                <select name="electrical_group">
                    <option value="">Не указана</option>
                    <?php foreach (['I', 'II', 'III', 'IV', 'V'] as $group): ?>
                    <option value="<?= $view->e($group) ?>"><?= $view->e($group) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Добавить</button>
        </form>
        <label><input type="checkbox" data-wp-show-archive> Показать архивные записи</label>
        <div data-wp-reference-list aria-live="polite"></div>
        <button type="button" data-wp-reference-more hidden>Показать ещё</button>
    </details>
    <?php endif; ?>
    <?php if (!empty($can_manage_references)): ?>
    <details class="calendar-shell wp-journal__registry" data-wp-template-admin>
        <summary>Управление шаблонами заданий, бригад, условий и указаний</summary>
        <p>Шаблон сохраняется отдельно. При выборе его содержание копируется в форму и остаётся редактируемым. Создание шаблона бригады использует назначения в открытом редакторе черновика.</p>
        <form data-wp-template-form>
            <label>Организация
                <select name="organization_id" data-wp-template-org required><option value="">Выберите организацию</option></select>
            </label>
            <label>Вид шаблона
                <select name="kind" data-wp-template-kind>
                    <option value="job">Задание</option>
                    <option value="crew">Бригада</option>
                    <option value="conditions">Условия производства работ</option>
                    <option value="instructions">Отдельные указания</option>
                </select>
            </label>
            <label>Название шаблона <input name="name" maxlength="160" required></label>
            <label data-wp-template-description><span data-wp-template-text-label>Содержание задания</span>
                <textarea name="work_description" maxlength="10000" rows="3"></textarea>
                <button type="button" data-wp-copy-job>Взять задание из открытой формы</button>
            </label>
            <p data-wp-template-crew-hint hidden>Для шаблона бригады сначала откройте собственный черновик, назначьте сотрудников и выберите ту же организацию.</p>
            <button type="submit">Сохранить новый шаблон</button>
        </form>
        <label><input type="checkbox" data-wp-template-archived> Показывать также архивные шаблоны</label>
        <div data-wp-template-list aria-live="polite"></div>
        <button type="button" data-wp-template-more hidden>Показать ещё</button>
    </details>
    <?php endif; ?>
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
    'module_scripts' => [
        $view->moduleAsset('work-permits', 'work-permits.js'),
        $view->moduleAsset('work-permits', 'work-permits-templates.js'),
        $view->moduleAsset('work-permits', 'work-permits-lifecycle.js'),
        $view->moduleAsset('work-permits', 'work-permits-daily.js'),
        $view->moduleAsset('work-permits', 'work-permits-amendments.js'),
        $view->moduleAsset('work-permits', 'work-permits-closure.js'),
    ],
], $content);
