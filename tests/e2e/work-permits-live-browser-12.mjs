import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { writeFile } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';

const origin = process.env.E2E_ORIGIN;
const base = origin + '/' + (process.env.E2E_BASE_PATH || 'workspace').replace(/^\/+|\/+$/g, '');
const required = name => {
    if (!process.env[name]) throw new Error('Не задан параметр CI: ' + name);
    return process.env[name];
};
const browser = await chromium.launch({ headless: true });
const pages = [];
const browserErrors = [];
const apiPath = '/api/work-permits';
const cmd = () => randomBytes(16).toString('hex');
const day = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
const dateAt = time => day + ' ' + time + ':00';
const check = (result, label, status = 200) => {
    assert.equal(result.status, status, label + ' HTTP: ' + JSON.stringify(result));
    if (status < 300) assert.equal(result.json?.status, 'ok', label + ': ' + JSON.stringify(result.json));
    return result.json;
};

async function login(name, width = 1280) {
    const context = await browser.newContext({
        viewport: { width, height: 860 },
        deviceScaleFactor: 1,
        isMobile: width <= 390,
        hasTouch: width <= 390,
    });
    const page = await context.newPage();
    pages.push(page);
    page.on('pageerror', error => browserErrors.push(error.message));
    const response = await page.goto(base + '/auth/login/', { waitUntil: 'domcontentloaded' });
    assert.equal(response?.status(), 200, 'Форма входа не открылась');
    await page.locator('#login').fill(required(name));
    await page.locator('#password').fill(required('E2E_PASSWORD'));
    await Promise.all([
        page.waitForURL(url => !url.pathname.includes('/auth/login'), { timeout: 20000 }),
        page.getByRole('button', { name: 'Войти' }).click(),
    ]);
    const workspace = await page.goto(base + '/work-permits/', { waitUntil: 'domcontentloaded' });
    assert.equal(workspace?.status(), 200, 'Не открывается настоящий модуль нарядов');
    await page.locator('[data-work-permits]').waitFor({ state: 'visible' });
    return { page, context };
}

async function http(page, method, path, body = undefined, withCsrf = true) {
    return page.evaluate(async ({ base, apiPath, method, path, body, withCsrf }) => {
        const token = document.querySelector('[data-work-permits] input[name=csrf_token]')?.value;
        const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            if (withCsrf) options.headers['X-CSRF-Token'] = token || '';
            options.body = JSON.stringify(body);
        }
        const response = await fetch(base + apiPath + path, options);
        const raw = await response.text();
        let json;
        try { json = JSON.parse(raw); } catch { json = { raw: raw.slice(0, 450) }; }
        return { status: response.status, json };
    }, { base, apiPath, method, path, body, withCsrf });
}

async function layout(page, width) {
    await page.setViewportSize({ width, height: 860 });
    const measured = await page.locator('[data-work-permits]').evaluate(root => ({
        pageWidth: document.documentElement.scrollWidth,
        viewport: document.documentElement.clientWidth,
        shownHidden: [...root.querySelectorAll('[hidden]')].filter(node =>
            getComputedStyle(node).display !== 'none').length,
        outOfBounds: [...root.querySelectorAll('input,select,textarea,button')]
            .filter(node => {
                if (!node.getClientRects().length) return false;
                const bounds = node.getBoundingClientRect();
                return bounds.left < -4 || bounds.right > innerWidth + 4;
            }).length,
    }));
    assert.ok(measured.pageWidth <= measured.viewport + 5,
        'Горизонтальная прокрутка на ' + width + ': ' + JSON.stringify(measured));
    assert.equal(measured.shownHidden, 0, 'Видны скрытые формы: ' + width);
    assert.equal(measured.outOfBounds, 0, 'Элементы за экраном: ' + width);
}

try {
    const { page: first, context: firstContext } = await login('E2E_MANAGER_ONE');
    const { page: second } = await login('E2E_MANAGER_TWO');
    const { page: worker } = await login('E2E_WORKER', 390);
    assert.equal(await worker.locator('[data-wp-management]').count(), 0,
        'Работник получил администраторскую панель');
    check(await http(worker, 'GET', '/management'), 'Управление работнику', 403);

    const orgs = check(await http(first, 'GET', '/references/organizations'), 'Организации').items;
    const org = Number(orgs[0].id);
    const sites = check(await http(first, 'GET', '/references/facilities?organization_id=' + org), 'Объекты').items;
    const staff = check(await http(first, 'GET', '/references/employees?organization_id=' + org), 'Работники').items;
    assert.ok(org > 0 && sites.length && staff.length >= 2, 'Не загружены реальные справочники');
    const facility = Number(sites[0].id);
    const a = Number(staff.find(x => x.name.includes('Иванов')).id);
    const b = Number(staff.find(x => x.name.includes('Петров')).id);

    // Контекст Playwright разделяет cookie браузера, но не клиентскую обёртку fetch.
    const unprotected = await firstContext.request.post(base + apiPath + '/', {
        data: {
            command_id: cmd(), kind: 'permit', organization_id: org, facility_id: facility,
            work_description: 'Запрещённая запись без CSRF',
        },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    });
    assert.equal(unprotected.status(), 403,
        'Сервер принял запрос без токена CSRF, вне браузерной обёртки');
    const created = check(await http(first, 'POST', '/', {
        kind: 'permit', organization_id: org, facility_id: facility,
        work_description: 'Проверка автоматики РЗА в реальном браузере',
        command_id: cmd(),
    }), 'Создание черновика', 201).document;
    const id = Number(created.id);
    assert.ok(id > 0, 'Новый наряд не получил номер');
    const otherOwner = await http(worker, 'GET', '/' + id);
    assert.equal(otherOwner.status, 404, 'Чужой документ виден работнику');

    const updated = check(await http(first, 'POST', '/' + id + '/edit', {
        version: 1, command_id: cmd(),
        organization_id: org, facility_id: facility, department_id: null,
        work_description: 'Проверить цепи управления выключателем',
        roles: { issuer: a, producer: a, responsible_manager: '', admitter: '',
            observer: '' },
        member_ids: [a],
    }), 'Состав бригады').document;
    assert.equal(Number(updated.version), 2, 'Версия после изменения бригады');

    const issuing = {
        version: 2, planned_start: dateAt('08:00'), planned_finish: dateAt('19:00'),
        work_conditions: 'Проверить ограждение места работ',
        special_instructions: 'Связь с диспетчером',
        measures: [{ equipment: 'КРУН-10 кВ', isolation: 'Отключить ячейку',
            remaining_live: 'Соседняя ячейка под напряжением' }],
    };
    const concurrentIssue = await Promise.all([
        http(first, 'POST', '/' + id + '/issue', { ...issuing, command_id: cmd() }),
        http(second, 'POST', '/' + id + '/issue', { ...issuing, command_id: cmd() }),
    ]);
    assert.deepEqual(concurrentIssue.map(x => x.status).sort(), [200, 409],
        'Один из двух одновременных запросов выдачи не был отклонён');
    let detail = check(await http(first, 'GET', '/' + id), 'После выдачи').document;
    assert.equal(detail.state, 'issued');
    assert.equal(Number(detail.version), 3);

    const firstAdmission = {
        version: 3, started_at: dateAt('09:00'), admitted_employee_id: a,
        briefed_by_employee_id: b, briefing_recipient_id: a,
        workplace_prepared: true, briefing_confirmed: true,
    };
    const admission = await Promise.all([
        http(first, 'POST', '/' + id + '/first-admission', {
            ...firstAdmission, command_id: cmd(),
        }),
        http(second, 'POST', '/' + id + '/first-admission', {
            ...firstAdmission, command_id: cmd(),
        }),
    ]);
    assert.deepEqual(admission.map(x => x.status).sort(), [200, 409],
        'Конкурентный первый допуск создал дубль');
    detail = check(await http(first, 'GET', '/' + id), 'После первого допуска').document;
    assert.equal(detail.lifecycle.periods.length, 1);
    assert.equal(Number(detail.version), 4);

    const end = async (action, at, version) => check(
        await http(first, 'POST', '/' + id + '/work-period/' + action, {
            version, command_id: cmd(), ended_at: dateAt(at),
            work_stopped: true, note: action === 'break' ? 'Проверка завершена' : 'Конец рабочего дня',
        }),
        'Завершение периода ' + action
    ).document;
    const start = async (action, at, version) => check(
        await http(first, 'POST', '/' + id + '/work-period/' + action, {
            version, command_id: cmd(), started_at: dateAt(at),
            admitted_employee_id: a, briefed_by_employee_id: b,
            briefing_recipient_id: a, workplace_prepared: true, briefing_confirmed: true,
        }),
        'Возобновление ' + action
    ).document;
    assert.equal(Number((await end('break', '12:00', 4)).version), 5);
    assert.equal(Number((await start('resume', '13:00', 5)).version), 6);
    assert.equal(Number((await end('day_end', '17:00', 6)).version), 7);
    detail = check(await http(second, 'GET', '/' + id), 'После смены').document;
    assert.equal(detail.lifecycle.periods.length, 2);

    const completed = check(await http(second, 'POST', '/' + id + '/complete', {
        version: 7, command_id: cmd(), completed_at: dateAt('17:10'),
        producer_employee_id: a, workplace_safe: true, crew_withdrawn: true,
        note: 'Работы завершены, ограждения проверены',
    }), 'Полное окончание').document;
    assert.equal(Number(completed.version), 8);
    const lateAdmission = await http(first, 'POST', '/' + id + '/work-period/daily_admission', {
        version: 8, command_id: cmd(), started_at: dateAt('18:00'),
        admitted_employee_id: a, briefed_by_employee_id: b,
        briefing_recipient_id: a, workplace_prepared: true, briefing_confirmed: true,
    });
    assert.equal(lateAdmission.status, 409, 'После окончания разрешён новый допуск');

    const closed = check(await http(first, 'POST', '/' + id + '/close-permit', {
        version: 8, command_id: cmd(), closed_at: dateAt('17:20'),
        closer_employee_id: a, workplace_accepted: true, documents_handed_over: true,
        note: 'Документы переданы ответственному',
    }), 'Окончательное закрытие').document;
    assert.equal(closed.state, 'closed');
    detail = check(await http(second, 'GET', '/' + id), 'Закрытый наряд').document;
    assert.equal(detail.state, 'closed');
    assert.equal(detail.lifecycle.periods.length, 2);
    const pdf = await first.evaluate(async ({ base, id }) => {
        const response = await fetch(base + '/api/work-permits/' + id + '/pdf');
        const bytes = await response.arrayBuffer();
        return { status: response.status, type: response.headers.get('content-type'),
            firstBytes: [...new Uint8Array(bytes).slice(0, 8)].map(x =>
                String.fromCharCode(x)).join(''), size: bytes.byteLength };
    }, { base, id });
    assert.equal(pdf.status, 200, 'Скачивание настоящего PDF: ' + JSON.stringify(pdf));
    assert.ok(pdf.type?.includes('application/pdf') && pdf.firstBytes.startsWith('%PDF-')
        && pdf.size > 10000, 'Сервер вернул некорректный PDF');
    check(await http(second, 'GET', '/management?state=closed'), 'Журнал закрытых');

    for (const width of [320, 360, 390, 768, 1440]) await layout(first, width);
    await layout(worker, 390);
    assert.deepEqual(browserErrors, [], 'Ошибки браузера');
    console.log('Реальный HTTP/MySQL: две сессии, конкурентная выдача/допуск,'
        + ' весь цикл, права, мобильные экраны, PDF — успешно');
} catch (e) {
    await writeFile('/tmp/work-permits-live-error.json', JSON.stringify({
        error: String(e), browserErrors, pageUrls: pages.map(x => x.url()),
    }, null, 2));
    for (const [index, page] of pages.entries()) {
        await page.screenshot({ path: '/tmp/work-permits-live-' + index + '.png',
            fullPage: true }).catch(() => {});
    }
    throw e;
} finally {
    await browser.close();
}
