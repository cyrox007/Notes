import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';

const required = (name) => {
    const value = process.env[name];
    if (!value) throw new Error('Не задана переменная стенда: ' + name);
    return value;
};

const origin = required('E2E_ORIGIN');
const base = origin + '/' + required('E2E_BASE_PATH').replace(/^\/+|\/+$/g, '');
const password = required('E2E_PASSWORD');
const browser = await chromium.launch({ headless: true });
const errors = [];
const pages = [];

const check = (condition, message) => {
    if (!condition) throw new Error(message);
};

const observe = (page) => {
    pages.push(page);
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('request', (request) => {
        if (!request.url().startsWith(base + '/') && request.url().startsWith(origin + '/')) {
            errors.push('Запрос вышел за BASE_PATH: ' + request.url());
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500) {
            errors.push('Ошибка сервера: ' + response.status() + ' ' + response.url());
        }
    });
};

async function login(page, username) {
    const response = await page.goto(base + '/auth/login/', { waitUntil: 'domcontentloaded' });
    check(response?.status() === 200, 'Не открывается штатная форма входа');
    await page.locator('#login').fill(username);
    await page.locator('#password').fill(password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 20000 }),
        page.getByRole('button', { name: 'Войти' }).click(),
    ]);
}

async function openWorkspace(page) {
    const response = await page.goto(base + '/defects/', { waitUntil: 'domcontentloaded' });
    check(response?.status() === 200, 'Страница Дефектов вернула HTTP ' + response?.status());
    await page.locator('#defects-workspace [data-defects-count-open]').waitFor({ state: 'visible' });
    await page.locator('#defects-workspace [data-defects-loading]').waitFor({ state: 'hidden', timeout: 20000 });
    const token = await page.locator('#defects-workspace input[name="csrf_token"]').inputValue();
    check(Boolean(token), 'Не передан штатный CSRF-токен');
}

const ajaxResponse = (page, fragment, method = 'POST') => page.waitForResponse(
    response => response.url().includes(fragment) && response.request().method() === method,
    { timeout: 20000 }
);

async function createDefect(page, description) {
    const urlBefore = page.url();
    const root = page.locator('#defects-workspace');
    await root.evaluate(node => { node.dataset.browserSentinel = 'ajax-mounted'; });

    await page.locator('[data-defects-create-open]').click();
    const form = page.locator('form[data-defects-create]');
    await form.waitFor({ state: 'visible' });
    await form.locator('[name="area"]').selectOption({ label: 'Северный РЭС' });
    await form.locator('[name="category"] option', { hasText: 'Подстанция' }).waitFor({ state: 'attached' });
    await form.locator('[name="category"]').selectOption({ label: 'Подстанция' });
    await form.locator('[name="object_id"] option', { hasText: 'ТП-17' }).waitFor({ state: 'attached' });
    await form.locator('[name="object_id"]').selectOption({ label: 'ТП-17' });
    await form.locator('[name="priority"]').selectOption('normal');
    const detected = new Date(Date.now() - 60 * 60 * 1000).toISOString().slice(0, 16);
    await form.locator('[name="detected"]').fill(detected);
    await form.locator('[name="registered_by_name"]').fill('Браузерный инспектор');
    await form.locator('[name="description"]').fill(description);
    await form.locator('input[name="service_ids"]').first().check();

    const validation = await form.evaluate(node => ({
        valid: node.checkValidity(),
        missing: [...node.elements].filter(field => field.willValidate && !field.checkValidity())
            .map(field => ({ name: field.name, value: field.value, message: field.validationMessage })),
        submitDisabled: node.querySelector('button[type="submit"]').disabled,
    }));
    check(validation.valid && !validation.submitDisabled,
        'Форма повторной регистрации не готова: ' + JSON.stringify(validation));
    const pending = ajaxResponse(page, '/api/defects/');
    await form.locator('button[type="submit"]').click();
    const response = await pending.catch(async (error) => {
        const feedback = await page.locator('[data-defects-create-error]').innerText();
        throw new Error('POST регистрации не получен: ' + error.message + '; ошибка формы='
            + feedback + '; браузер=' + errors.join('; '));
    });
    check(response.status() === 201, 'Регистрация вернула HTTP ' + response.status());
    const payload = await response.json();
    const id = Number(payload.defect?.id);
    check(id > 0, 'Сервер не вернул идентификатор дефекта');

    await page.locator('[data-defects-create-overlay]').waitFor({ state: 'hidden' });
    await page.locator('.defects-row[data-defect-id="' + id + '"]').waitFor({ state: 'visible', timeout: 15000 });
    check(page.url() === urlBefore, 'Регистрация перезагрузила страницу');
    check(await root.getAttribute('data-browser-sentinel') === 'ajax-mounted', 'Сломана локальная AJAX-панель');
    return id;
}

async function detail(page, id) {
    await page.locator('.defects-row[data-defect-id="' + id + '"]').click();
    await page.locator('[data-defects-detail-content]').waitFor({ state: 'visible', timeout: 15000 });
    await page.locator('[data-defects-detail-loading]').waitFor({ state: 'hidden' });
    check((await page.locator('[data-defects-detail-fields]').innerText()).includes('ТП-17'),
        'В карточке отсутствует объект');
    await page.locator('[data-defects-history-list] li').first().waitFor({ state: 'visible' });
}

async function closeDefect(page, id, work, expectStatus = 200) {
    const form = page.locator('form[data-defects-close]');
    await form.locator('[name="completed_work"]').fill(work);
    await form.locator('[name="completed_by_name"]').fill('Иванов Иван Иванович');
    await form.locator('[name="closed_at"]').fill(new Date(Date.now() - 60000).toISOString().slice(0, 16));
    await form.locator('[name="close_comment"]').fill('Испытательное закрытие');
    const pending = ajaxResponse(page, '/api/defects/' + id + '/close');
    await form.locator('button[type="submit"]').click();
    const response = await pending;
    check(response.status() === expectStatus, 'Закрытие вернуло HTTP ' + response.status());
    if (expectStatus === 200) {
        await page.locator('[data-defects-detail-overlay]').waitFor({ state: 'hidden', timeout: 15000 });
    } else {
        await page.locator('[data-defects-close-error]').waitFor({ state: 'visible', timeout: 15000 });
        check((await form.locator('[name="completed_work"]').inputValue()) === work,
            'Ошибка закрытия очистила введённые работы');
    }
}

try {
    const admin = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await admin.newPage();
    observe(page);
    await login(page, required('E2E_USER'));
    await openWorkspace(page);
    await page.locator('[data-defects-display="table"]').click();
    await page.locator('.defects-table th', { hasText: 'Плановый срок' }).waitFor({ state: 'visible' });
    const sortedRequest = ajaxResponse(page, 'sort=due_desc', 'GET');
    await page.locator('[data-defects-sort]').selectOption('due_desc');
    check((await sortedRequest).status() === 200, 'Сортировка реестра завершилась ошибкой');
    await page.locator('[data-defects-loading]').waitFor({ state: 'hidden', timeout: 15000 });
    await page.locator('[data-defects-display="cards"]').click();
    await page.locator('.defects-table').waitFor({ state: 'detached', timeout: 15000 });

    const marker = Date.now();
    const first = await createDefect(page, 'Браузерная проверка ' + marker + ' — повреждён изолятор');
    await detail(page, first);
    check((await page.locator('[data-defects-history-list]').innerText()).includes('Зарегистрирован'),
        'Регистрация отсутствует в истории');
    const editServices = page.locator('form[data-defects-services-edit]');
    await editServices.waitFor({ state: 'visible' });
    const entries = editServices.locator('[data-service-id]');
    check(await entries.count() >= 2, 'Редактор не загрузил доступные службы');
    await entries.nth(0).locator('input[type="text"]').fill('Иванов И. И.');
    await entries.nth(1).locator('input[type="checkbox"]').check();
    await entries.nth(1).locator('input[type="text"]').fill('Петров П. П.');
    const pendingAssignments = ajaxResponse(page, '/api/defects/' + first + '/services');
    await editServices.locator('button[type="submit"]').click();
    const assignmentsResponse = await pendingAssignments;
    check(assignmentsResponse.status() === 200, 'Не сохранились назначения исполнителей');
    await page.locator('[data-defects-history-list]').getByText('Изменены службы и исполнители').waitFor({ state: 'visible' });
    check((await editServices.locator('input[type="text"]').nth(1).inputValue()) === 'Петров П. П.',
        'После сохранения потеряно назначение второго исполнителя');
    const token = await page.locator('#defects-workspace input[name="csrf_token"]').inputValue();
    const conflict = await page.evaluate(async ({baseUrl, identifier, csrfToken}) => {
        const response = await fetch(baseUrl + '/api/defects/' + identifier + '/services', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                version: 1, command_id: 'assign_browser_conflict_123456',
                assignments: [{ service_id: 1, executor_name: 'Конфликт' }],
            }),
        });
        return response.status;
    }, { baseUrl: base, identifier: first, csrfToken: token });
    check(conflict === 409, 'Устаревшее назначение не отклонено конфликтом 409');
    await closeDefect(page, first, 'Заменён повреждённый изолятор');
    await page.locator('[data-defects-tab="closed"]').click();
    await page.locator('.defects-row[data-defect-id="' + first + '"]').waitFor({ state: 'visible' });
    await detail(page, first);
    check((await page.locator('[data-defects-detail-fields]').innerText()).includes('Заменён повреждённый изолятор'),
        'Выполненные работы не сохранились');
    const adminCorrection = page.locator('form[data-defects-admin-correct]');
    await adminCorrection.waitFor({ state: 'visible' });
    await adminCorrection.locator('[name="completed_work"]').fill('Заменён повреждённый изолятор, составлен акт');
    await adminCorrection.locator('[name="reason"]').fill('Уточнение по акту выполненных работ');
    const correctResponse = ajaxResponse(page, '/api/defects/' + first + '/correct');
    await adminCorrection.locator('button[type="submit"]').click();
    check((await correctResponse).status() === 200, 'Административное исправление вернуло ошибку');
    await page.locator('[data-defects-history-list]').getByText('Исправлена карточка администратором')
        .waitFor({ state: 'visible', timeout: 15000 });
    const archivePanel = page.locator('[data-defects-admin-archive]');
    await archivePanel.locator('[data-defects-admin-reason]').fill('Проверка контролируемого архива');
    const defectArchiveResponse = ajaxResponse(page, '/api/defects/' + first + '/archive');
    await archivePanel.locator('[data-defects-admin-archive-action]').click();
    check((await defectArchiveResponse).status() === 200, 'Архивирование вернуло ошибку');
    await archivePanel.locator('[data-defects-admin-archive-action]')
        .filter({ hasText: 'Восстановить из архива' }).waitFor({ state: 'visible', timeout: 15000 });
    const archivedList = await page.evaluate(async ({url, identifier}) => {
        const response = await fetch(url + '/api/defects/?status=all&archived=1', { credentials: 'same-origin' });
        const data = await response.json();
        return response.status === 200 && data.items.some(item => Number(item.id) === identifier);
    }, {url: base, identifier: first});
    check(archivedList, 'Архивная карточка отсутствует в реестре');
    await archivePanel.locator('[data-defects-admin-reason]').fill('Восстановление после проверки');
    const defectRestoreResponse = ajaxResponse(page, '/api/defects/' + first + '/restore');
    await archivePanel.locator('[data-defects-admin-archive-action]').click();
    check((await defectRestoreResponse).status() === 200, 'Восстановление архива завершилось ошибкой');
    await page.locator('[data-defects-history-list]').getByText('Карточка восстановлена из архива')
        .waitFor({ state: 'visible', timeout: 15000 });
    await archivePanel.locator('[data-defects-admin-reopen-action]').waitFor({ state: 'visible' });
    await archivePanel.locator('[data-defects-admin-reason]').fill('Ошибочное закрытие — работы требуют продолжения');
    const reopenResponse = ajaxResponse(page, '/api/defects/' + first + '/reopen');
    await archivePanel.locator('[data-defects-admin-reopen-action]').click();
    check((await reopenResponse).status() === 200, 'Повторное открытие дефекта не выполнено');
    await page.locator('[data-defects-history-list]')
        .getByText('Закрытие отменено администратором, дефект возвращён в работу')
        .waitFor({ state: 'visible', timeout: 15000 });
    check((await page.locator('[data-defects-detail-fields]').innerText()).includes('Открыт'),
        'Дефект после отмены закрытия не находится в работе');
    check((await page.locator('[data-defects-history-list]').innerText()).includes('Дефект закрыт'),
        'После повторного открытия потеряно доказательство первоначального закрытия');
    await page.locator('[data-defects-detail-close]').first().click();

    await page.locator('[data-defects-tab="open"]').click();
    const concurrent = await createDefect(page, 'Браузерная проверка ' + marker + ' — второй дефект');
    await detail(page, concurrent);

    const peer = await admin.newPage();
    observe(peer);
    await openWorkspace(peer);
    await detail(peer, concurrent);
    await closeDefect(peer, concurrent, 'Устранено другой сессией');
    await closeDefect(page, concurrent, 'Устаревшая попытка закрытия', 409);
    check((await page.locator('[data-defects-close-error]').innerText()).includes('изменилась'),
        'Конфликт версии не показан пользователю');
    await page.locator('[data-defects-detail-close]').first().click();

    const manager = page.locator('[data-defects-reference-manager]');
    await page.locator('.defects-administration summary').click();
    await manager.locator('[data-defects-manage-kind]').selectOption('services');
    await manager.locator('.defects-reference-row').first().waitFor({ state: 'visible', timeout: 15000 });
    const secondService = manager.locator('.defects-reference-row').filter({ hasText: 'Диспетчерская' });
    await secondService.getByRole('button', { name: 'В архив' }).click();
    const observed = [];
    const recordMutation = request => {
        if (request.url().includes('/api/defects/references/')) {
            observed.push(request.method() + ' ' + request.url());
        }
    };
    page.on('request', recordMutation);
    const archiveResponse = ajaxResponse(page, '/api/defects/references/services/');
    await secondService.getByRole('button', { name: 'Подтвердить' }).click();
    const archivedHttp = await archiveResponse.catch(async error => {
        throw new Error('Команда архивации не получила ответ: ' + error.message
            + '; запросы=' + JSON.stringify(observed)
            + '; сообщение=' + await page.locator('[data-defects-feedback]').innerText()
            + '; ошибки браузера=' + errors.join('; '));
    });
    page.off('request', recordMutation);
    check(archivedHttp.url().endsWith('/archive'),
        'Вместо архивирования вызван другой маршрут: ' + archivedHttp.url());
    check(archivedHttp.status() === 200,
        'Не удалось архивировать службу: HTTP ' + archivedHttp.status() + ', '
        + JSON.stringify(await archivedHttp.json()));
    await secondService.waitFor({ state: 'hidden', timeout: 15000 });
    await manager.locator('[data-defects-manage-archived]').check();
    const archived = manager.locator('.defects-reference-row').filter({ hasText: 'Диспетчерская' });
    await archived.getByRole('button', { name: 'Восстановить' }).waitFor({ state: 'visible', timeout: 15000 });
    const restoreResponse = ajaxResponse(page, '/api/defects/references/services/');
    await archived.getByRole('button', { name: 'Восстановить' }).click();
    const restoredHttp = await restoreResponse;
    check(restoredHttp.status() === 200, 'Восстановление вернуло HTTP ' + restoredHttp.status());
    await archived.waitFor({ state: 'hidden', timeout: 15000 });

    // Сетевой сбой не должен оставлять интерфейс заблокированным.
    await page.route('**/api/defects/?*', route => route.abort('failed'));
    await page.locator('[data-defects-refresh]').click();
    await page.locator('[data-defects-feedback][data-error="true"]').waitFor({ state: 'visible' });
    await page.unroute('**/api/defects/?*');
    await page.locator('[data-defects-refresh]').click();
    await page.locator('[data-defects-loading]').waitFor({ state: 'hidden', timeout: 15000 });
    check(!(await page.locator('[data-defects-refresh]').isDisabled()), 'После сбоя зависло обновление');

    const regularContext = await browser.newContext();
    const regular = await regularContext.newPage();
    observe(regular);
    await login(regular, required('E2E_REGULAR_USER'));
    await openWorkspace(regular);
    check(!(await regular.locator('[data-defects-reference-manager]').isVisible()),
        'Обычный пользователь получил административное управление');
    const denied = await regular.evaluate(async url =>
        (await fetch(url + '/api/defects/references/manage?kind=services', { credentials: 'same-origin' })).status, base);
    check(denied === 403, 'Сервер разрешил обычному пользователю доступ к управлению справочниками');
    const regularCsrf = await regular.locator('#defects-workspace input[name="csrf_token"]').inputValue();
    const forbiddenAssignment = await regular.evaluate(async ({baseUrl, csrfToken, identifier}) => {
        const response = await fetch(baseUrl + '/api/defects/' + identifier + '/services', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({
                version: 2, command_id: 'assign_forbidden_user_123456',
                assignments: [{ service_id: 1, executor_name: 'Без прав' }],
            }),
        });
        return response.status;
    }, { baseUrl: base, csrfToken: regularCsrf, identifier: first });
    check(forbiddenAssignment === 403, 'Обычному пользователю разрешили редактировать службы');
    const forbiddenCorrection = await regular.evaluate(async ({url,csrfToken,identifier}) => {
        const response = await fetch(url + '/api/defects/' + identifier + '/correct', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
            body: JSON.stringify({
                version: 1, command_id: 'correct_user_forbidden_123456',
                reason: 'Без права администратора',
                fields: {description: 'Недопустимая правка'},
            }),
        });
        return response.status;
    }, {url: base, csrfToken: regularCsrf, identifier: first});
    check(forbiddenCorrection === 403, 'Обычный пользователь смог исправлять дефекты');
    const forbiddenArchive = await regular.evaluate(async ({url,csrfToken,identifier}) => {
        const response = await fetch(url + '/api/defects/' + identifier + '/archive', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
            body: JSON.stringify({
                version: 1, command_id: 'archive_user_forbidden_123456',
                reason: 'Без права администратора',
            }),
        });
        return response.status;
    }, {url: base, csrfToken: regularCsrf, identifier: first});
    check(forbiddenArchive === 403, 'Обычный пользователь смог архивировать дефект');
    const forbiddenReopen = await regular.evaluate(async ({url,csrfToken,identifier}) => {
        const response = await fetch(url + '/api/defects/' + identifier + '/reopen', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
            body: JSON.stringify({
                version: 1, command_id: 'reopen_user_forbidden_123456',
                reason: 'Без права администратора',
            }),
        });
        return response.status;
    }, {url: base, csrfToken: regularCsrf, identifier: first});
    check(forbiddenReopen === 403, 'Обычный пользователь смог отменить закрытие');

    const mobileContext = await browser.newContext({
        viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 1,
    });
    const mobile = await mobileContext.newPage();
    observe(mobile);
    await login(mobile, required('E2E_USER'));
    await openWorkspace(mobile);
    await mobile.locator('[data-defects-create-open]').click();
    await mobile.locator('[data-defects-create-overlay] .defects-panel').waitFor({ state: 'visible' });
    const dimensions = await mobile.evaluate(() => ({
        width: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
        panel: document.querySelector('[data-defects-create-overlay] .defects-panel').getBoundingClientRect().width,
    }));
    check(dimensions.panel <= dimensions.viewport + 2, 'Панель шире мобильного экрана');
    check(dimensions.width <= dimensions.viewport + 8,
        'Горизонтальная прокрутка на мобильном экране: ' + JSON.stringify(dimensions));
    await mobile.screenshot({ path: '/tmp/defects-browser-mobile.png', fullPage: true });
    await mobile.locator('[data-defects-create-cancel]').last().click();
    await mobile.locator('[data-defects-create-overlay]').waitFor({ state: 'hidden' });

    await page.screenshot({ path: '/tmp/defects-browser-desktop.png', fullPage: true });
    check(errors.length === 0, 'Ошибки браузера: ' + errors.join('; '));
    console.log('Дефекты: Chromium, AJAX, конкурентность, ошибки сети, права и мобильный экран проверены');
} catch (error) {
    writeFileSync('/tmp/defects-browser-failure.json', JSON.stringify({
        error: String(error), browserErrors: errors,
        urls: pages.map(page => page.url()),
    }, null, 2));
    for (const [index, page] of pages.entries()) {
        await page.screenshot({ path: '/tmp/defects-browser-failure-' + index + '.png', fullPage: true }).catch(() => {});
    }
    throw error;
} finally {
    await browser.close();
}
