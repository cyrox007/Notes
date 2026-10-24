import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

// Настоящие шаблоны PHP и клиентские скрипты. Ответы API моделируются отдельно.
const html = execFileSync('php', ['tests/e2e/work-permits-view-fixture.php', 'manager'], { encoding: 'utf8' });
const regularHtml = execFileSync('php', ['tests/e2e/work-permits-view-fixture.php', 'regular'], { encoding: 'utf8' });
const styles = await readFile('modules/work-permits/assets/work-permits.css', 'utf8');
const scripts = await Promise.all([
    'work-permits.js', 'work-permits-templates.js', 'work-permits-lifecycle.js',
    'work-permits-daily.js', 'work-permits-amendments.js', 'work-permits-closure.js',
].map(name => readFile('modules/work-permits/assets/' + name, 'utf8')));
const people = [
    { id: 21, name: 'Иванов Иван Иванович', electrical_group: 'IV', organization_id: 1 },
    { id: 22, name: 'Петров Пётр Петрович', electrical_group: 'V', organization_id: 1 },
];
const refs = {
    organizations: [{ id: 1, name: 'Электросети' }],
    departments: [{ id: 11, name: 'РЭС', organization_id: 1 }],
    facilities: [{ id: 12, name: 'ПС Болдыревка', organization_id: 1 }],
    employees: people,
};
const templates = [
    { id: 1, name: 'Условия КРУН', kind: 'conditions', organization_id: 1,
        work_description: 'Проверить ограждение' },
    { id: 2, name: 'Указания РЗА', kind: 'instructions', organization_id: 1,
        work_description: 'Уведомить диспетчера' },
];
const initial = {
    id: 100, number: 100, kind: 'permit', state: 'draft', version: 1,
    organization: 'Электросети', subdivision: 'РЭС', facility: 'ПС Болдыревка',
    organization_id: 1, department_id: 11, facility_id: 12,
    work_description: 'Проверка РЗА', created_at: '2026-10-24 08:00:00', crew: [],
    lifecycle: { issuance: null, first_admission: null, measures: [], periods: [],
        extensions: [], crew_revisions: [], completion: null, closure: null },
};
const state = { docs: [initial], failNext: false, calls: [] };
const success = (value = {}) => ({ status: 200, body: { status: 'ok', ...structuredClone(value) } });
const error = (message, status = 409) => ({ status, body: { status: 'error', message } });
const person = (role, id) => {
    const item = people.find(p => String(p.id) === String(id));
    if (!item) throw Error('Работник отсутствует: ' + id);
    return { role, employee_id: item.id, employee_name: item.name, electrical_group: item.electrical_group };
};
function answer(method, url, data, manager) {
    const parts = url.pathname.replace(/^\/api\/work-permits/, '').split('/').filter(Boolean);
    if (parts[0] === 'management' && !manager) return error('Нет разрешения', 403);
    if (method === 'GET' && parts[0] === 'references') {
        const org = url.searchParams.get('organization_id');
        return success({ items: (refs[parts[1]] || []).filter(x => !org || String(x.organization_id) === org),
            next_cursor: null });
    }
    if (method === 'GET' && parts[0] === 'templates') {
        if (parts[1]) return success({ template: templates.find(x => x.id === Number(parts[1])) });
        return success({ items: templates.filter(x => x.kind === url.searchParams.get('kind')
            && String(x.organization_id) === url.searchParams.get('organization_id')), next_cursor: null });
    }
    if (method === 'GET' && parts[0] === 'management') {
        return success({ items: state.docs.filter(x => x.state === url.searchParams.get('state'))
            .map(x => ({ ...x, has_first_admission: Number(!!x.lifecycle.first_admission),
                has_completion: Number(!!x.lifecycle.completion) })), next_cursor: null });
    }
    if (method === 'GET' && parts.length === 0) {
        return success({ items: state.docs, next_cursor: null });
    }
    const doc = state.docs.find(x => x.id === Number(parts[0]));
    if (!doc) return error('Не найден', 404);
    if (method === 'GET' && parts.length === 1) return success({ document: doc });
    if (method !== 'POST') return error('Неизвестный адрес', 404);
    state.calls.push({ path: parts.join('/'), data });
    if (state.failNext) {
        state.failNext = false;
        return error('Сбой сети: повторите сохранение', 503);
    }
    if (Number(data.version) !== doc.version) return error('Версия изменилась', 409);
    const updated = () => {
        doc.version++;
        return success({ document: { id: doc.id, version: doc.version,
            state: doc.state, replayed: false } });
    };
    if (parts[1] === 'edit') {
        doc.work_description = data.work_description;
        doc.crew = Object.entries(data.roles).filter(([, id]) => id).map(([role, id]) => person(role, id));
        doc.crew.push(...data.member_ids.map(id => person('member', id)));
        return updated();
    }
    if (parts[1] === 'issue') {
        if (!data.measures?.length) return error('Не указана подготовка места');
        doc.state = 'issued';
        doc.lifecycle.issuance = { ...data, producer_employee_id: 21,
            producer_name: people[0].name, issuer_employee_id: 21, issuer_name: people[0].name };
        doc.lifecycle.measures = data.measures;
        return updated();
    }
    if (parts[1] === 'first-admission') {
        doc.lifecycle.first_admission = { ...data, admitted_name: people[0].name,
            briefed_by_name: people[1].name, briefing_recipient_name: people[0].name };
        doc.lifecycle.periods.push({
            start_kind: 'first', started_at: data.started_at,
            admitter_employee_id: 21, admitter_name: people[0].name,
            instructor_employee_id: 22, instructor_name: people[1].name,
            recipient_employee_id: 21, recipient_name: people[0].name,
            ended_at: null, end_kind: null,
        });
        return updated();
    }
    if (parts[1] === 'work-period') {
        const last = doc.lifecycle.periods.at(-1);
        if (parts[2] === 'break' || parts[2] === 'day_end') {
            if (last.ended_at) return error('Смена уже завершена');
            last.ended_at = data.ended_at;
            last.end_kind = parts[2];
            last.end_note = data.note;
        } else {
            if (!last.ended_at) return error('Сначала завершите смену');
            doc.lifecycle.periods.push({
                start_kind: parts[2], started_at: data.started_at,
                admitter_employee_id: 21, admitter_name: people[0].name,
                instructor_employee_id: 22, instructor_name: people[1].name,
                recipient_employee_id: 21, recipient_name: people[0].name,
                ended_at: null, end_kind: null,
            });
        }
        return updated();
    }
    if (parts[1] === 'complete') {
        if (!doc.lifecycle.periods.at(-1)?.ended_at) return error('Сначала завершите рабочий период');
        doc.lifecycle.completion = { completed_at: data.completed_at, producer_name: people[0].name };
        return updated();
    }
    if (parts[1] === 'close-permit') {
        if (!doc.lifecycle.completion) return error('Сначала завершите работы');
        doc.lifecycle.closure = { closed_at: data.closed_at, closer_name: people[1].name };
        doc.state = 'closed';
        return updated();
    }
    return error('Неподдерживаемая команда', 404);
}
const browser = await chromium.launch({ headless: true });
const failures = [];
const pages = [];
async function launch(width, manager = true) {
    const context = await browser.newContext({ viewport: { width, height: 900 },
        isMobile: width <= 390, deviceScaleFactor: 1 });
    const page = await context.newPage();
    pages.push(page);
    page.on('pageerror', e => failures.push(e.message));
    await page.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname === '/work-permits/') {
            await route.fulfill({ status: 200, contentType: 'text/html',
                body: manager ? html : regularHtml });
            return;
        }
        if (!url.pathname.startsWith('/api/work-permits')) {
            await route.fulfill({ status: 404, body: 'Не найдено' });
            return;
        }
        if (request.method() === 'POST') {
            assert.equal(request.headers()['x-csrf-token'], 'test-csrf-token', 'Отсутствует CSRF');
        }
        const result = answer(request.method(), url,
            request.method() === 'POST' ? request.postDataJSON() : {}, manager);
        await route.fulfill({ status: result.status, contentType: 'application/json',
            body: JSON.stringify(result.body) });
    });
    await page.goto('http://notes.test/work-permits/');
    await page.addStyleTag({ content: ':root{--ui-text:#222;--ui-muted:#666;--ui-border:#ccc;'
        + '--ui-surface:#fff;--ui-primary:#315b99;--ui-danger:#900;--ui-danger-soft:#fee}'
        + '*{box-sizing:border-box}body{margin:0;font:14px Arial}'
        + '.calendar-shell{border:1px solid #ccc;border-radius:8px}' + styles });
    await page.evaluate(() => {
        window.wspace = { page: { register: (_file, handler) => handler({
            onInit: callback => callback(),
            listen: (target, event, callback) => target.addEventListener(event, callback),
            fetch: (...args) => window.fetch(...args),
        }) } };
    });
    for (const script of scripts) await page.addScriptTag({ content: script });
    await page.locator('[data-wp-organization] option').filter({ hasText: 'Электросети' })
        .waitFor({ state: 'attached', timeout: 10000 });
    return { page, context };
}
async function layout(page, name) {
    const state = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        width: document.documentElement.scrollWidth,
        visibleHidden: [...document.querySelectorAll('.wp-journal [hidden]')]
            .filter(x => getComputedStyle(x).display !== 'none').length,
        controlsOutside: [...document.querySelectorAll('.wp-journal input,.wp-journal select,'
            + '.wp-journal textarea,.wp-journal button')].filter(x => {
            if (x.getClientRects().length === 0) return false;
            const b = x.getBoundingClientRect();
            return b.left < -4 || b.right > innerWidth + 4;
        }).length,
    }));
    assert.ok(state.width <= state.viewport + 5, name + ': прокрутка ' + JSON.stringify(state));
    assert.equal(state.visibleHidden, 0, name + ': видны скрытые формы');
    assert.equal(state.controlsOutside, 0, name + ': поле за экраном');
}
try {
    const { page } = await launch(1280);
    await layout(page, 'Компьютер');
    await page.locator('[data-wp-list]').getByText('Наряд-допуск №100').waitFor();
    await page.locator('[data-wp-list]').getByRole('button', { name: 'Открыть и изменить' }).click();
    await page.locator('[data-wp-editor]').waitFor({ state: 'visible' });
    const editor = page.locator('[data-wp-edit-form]');
    await editor.locator('select[name=role_issuer]').selectOption('21');
    await editor.locator('select[name=role_producer]').selectOption('21');
    await editor.locator('select[name=member_ids]').selectOption(['21']);
    await editor.locator('button[type=submit]').click();
    await page.locator('[data-wp-editor]').waitFor({ state: 'hidden' });

    await page.locator('[data-wp-management-list]').getByRole('button', { name: 'Выдать' }).first().click();
    const issue = page.locator('[data-wp-issue-form]');
    await issue.waitFor({ state: 'visible' });
    await issue.locator('input[name=planned_start]').fill('2026-10-30T08:00');
    await issue.locator('input[name=planned_finish]').fill('2026-11-01T18:00');
    const conditions = issue.locator('[data-wp-issue-template=conditions]');
    await conditions.locator('select').selectOption('1');
    await conditions.locator('button').click();
    assert.match(await issue.locator('textarea[name=work_conditions]').inputValue(), /ограждение/);
    await issue.locator('textarea[name=special_instructions]').fill('Особые указания');
    for (const [name, value] of Object.entries({
        equipment: 'КРУН', isolation: 'Отключить', remaining_live: 'Смежная ячейка',
    })) await issue.locator('textarea[name=' + name + ']').fill(value);
    state.failNext = true;
    await issue.locator('button[type=submit]').click();
    await page.locator('[data-wp-feedback][data-state=error]').waitFor({ state: 'visible' });
    assert.equal(await issue.locator('button[type=submit]').isDisabled(), false);
    await issue.locator('button[type=submit]').click();
    await issue.waitFor({ state: 'hidden' });

    await page.locator('[data-wp-management-state]').selectOption('issued');
    await page.locator('[data-wp-management-list]').getByRole('button', { name: 'Оформить первый допуск' }).click();
    const admit = page.locator('[data-wp-admission-form]');
    await admit.waitFor({ state: 'visible' });
    await admit.locator('input[name=started_at]').fill('2026-10-30T09:00');
    await admit.locator('select[name=admitted_employee_id]').selectOption('21');
    await admit.locator('select[name=briefed_by_employee_id]').selectOption('22');
    await admit.locator('select[name=briefing_recipient_id]').selectOption('21');
    await admit.locator('input[name=workplace_prepared]').check();
    await admit.locator('input[name=briefing_confirmed]').check();
    await admit.locator('button[type=submit]').click();
    await admit.waitFor({ state: 'hidden' });

    const managerList = page.locator('[data-wp-management-list]');
    await managerList.getByRole('button', { name: 'Рабочие смены и перерывы' }).click();
    const stop = page.locator('[data-wp-period-stop]');
    await stop.waitFor({ state: 'visible' });
    await stop.locator('input[name=ended_at]').fill('2026-10-30T12:00');
    await stop.locator('select[name=end_kind]').selectOption('break');
    await stop.locator('input[name=work_stopped]').check();
    await stop.locator('button[type=submit]').click();
    const resume = page.locator('[data-wp-period-start]');
    await resume.waitFor({ state: 'visible' });
    await resume.locator('input[name=started_at]').fill('2026-10-30T13:00');
    await resume.locator('input[name=workplace_prepared]').check();
    await resume.locator('input[name=briefing_confirmed]').check();
    await resume.locator('button[type=submit]').click();
    await stop.waitFor({ state: 'visible' });
    await stop.locator('input[name=ended_at]').fill('2026-10-30T17:00');
    await stop.locator('select[name=end_kind]').selectOption('day_end');
    await stop.locator('input[name=work_stopped]').check();
    await stop.locator('button[type=submit]').click();
    assert.equal(state.docs[0].lifecycle.periods.length, 2);
    await page.locator('[data-wp-period-close]').click();

    await managerList.getByRole('button', { name: 'Окончание работ' }).click();
    const complete = page.locator('[data-wp-complete-form]');
    await complete.waitFor({ state: 'visible' });
    await complete.locator('input[name=completed_at]').fill('2026-10-30T17:10');
    await complete.locator('input[name=workplace_safe]').check();
    await complete.locator('input[name=crew_withdrawn]').check();
    await complete.locator('button[type=submit]').click();
    const close = page.locator('[data-wp-final-close-form]');
    await close.waitFor({ state: 'visible' });
    await close.locator('input[name=closed_at]').fill('2026-10-30T17:20');
    await close.locator('input[name=workplace_accepted]').check();
    await close.locator('input[name=documents_handed_over]').check();
    await close.locator('button[type=submit]').click();
    await page.locator('[data-wp-closure]').waitFor({ state: 'hidden' });
    assert.equal(state.docs[0].state, 'closed');
    await page.locator('[data-wp-management-state]').selectOption('closed');
    await managerList.getByText('Наряд №100').waitFor();
    assert.equal(await managerList.locator('a[download]').count(), 1);
    assert.ok(state.calls.some(x => x.path === '100/issue' && x.data.work_conditions.includes('ограждение')));

    for (const width of [320, 360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await layout(page, 'Ширина ' + width);
    }
    const { page: regular, context } = await launch(390, false);
    assert.equal(await regular.locator('[data-wp-management]').count(), 0);
    assert.equal(await regular.evaluate(() => fetch('/api/work-permits/management')
        .then(r => r.status)), 403);
    await layout(regular, 'Работник 390');
    await context.close();
    assert.deepEqual(failures, [], 'Ошибки JavaScript');
    console.log('Наряды 1.2: весь интерфейс, отказ и восстановление, 320–1440 px — успешно');
} catch (e) {
    await writeFile('/tmp/work-permits-browser-error.json', JSON.stringify({
        error: String(e), failures, state: state.docs.map(x => ({
            id: x.id, version: x.version, status: x.state,
        })),
    }, null, 2));
    for (const [index, page] of pages.entries()) {
        await page.screenshot({ path: '/tmp/work-permits-browser-' + index + '.png',
            fullPage: true }).catch(() => {});
    }
    throw e;
} finally {
    await browser.close();
}
