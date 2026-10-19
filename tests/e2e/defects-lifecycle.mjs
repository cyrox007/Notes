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

    const marker = Date.now();
    const first = await createDefect(page, 'Браузерная проверка ' + marker + ' — повреждён изолятор');
    await detail(page, first);
    check((await page.locator('[data-defects-history-list]').innerText()).includes('Зарегистрирован'),
        'Регистрация отсутствует в истории');
    await closeDefect(page, first, 'Заменён повреждённый изолятор');
    await page.locator('[data-defects-tab="closed"]').click();
    await page.locator('.defects-row[data-defect-id="' + first + '"]').waitFor({ state: 'visible' });
    await detail(page, first);
    check((await page.locator('[data-defects-detail-fields]').innerText()).includes('Заменён повреждённый изолятор'),
        'Выполненные работы не сохранились');
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
    await secondService.getByRole('button', { name: 'Подтвердить' }).click();
    await manager.locator('[data-defects-manage-archived]').check();
    const archived = manager.locator('.defects-reference-row').filter({ hasText: 'Диспетчерская' });
    await archived.waitFor({ state: 'visible' });
    await archived.getByRole('button', { name: 'Восстановить' }).click();
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
