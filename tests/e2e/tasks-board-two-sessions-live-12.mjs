import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const origin = process.env.E2E_ORIGIN;
const base = process.env.E2E_BASE_PATH;
const password = process.env.E2E_PASSWORD;
const ownerUser = process.env.E2E_USER;
const memberUser = process.env.E2E_SECOND_USER;
for (const [label, value] of Object.entries({ origin, base, password, ownerUser, memberUser })) {
    if (!value) throw new Error('Не задан параметр браузерной приёмки: ' + label);
}

const boardUid = 'a'.repeat(32);
const taskUid = 'b'.repeat(32);
const boardUrl = origin + base + '/tasks/boards?board=' + boardUid;
const browser = await chromium.launch({ headless: true });
const errors = [];

async function login(user, width) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push({ user, message: error.message }));
    const result = await page.goto(origin + base + '/auth/login/', { waitUntil: 'domcontentloaded' });
    assert.equal(result?.status(), 200, 'Не открылась форма входа');
    await page.locator('#login').fill(user);
    await page.locator('#password').fill(password);
    await Promise.all([
        page.waitForURL(url => !url.pathname.includes('/auth/login'), { timeout: 20000 }),
        page.getByRole('button', { name: 'Войти' }).click(),
    ]);
    const boardResponse = await page.goto(boardUrl, { waitUntil: 'domcontentloaded' });
    assert.equal(boardResponse?.status(), 200, 'Не открылась общая доска для ' + user);
    return { context, page };
}

async function taskCard(page) {
    const card = page.locator('.task-board-card[data-task-uid="' + taskUid + '"]');
    await card.waitFor({ state: 'visible', timeout: 15000 });
    return card;
}
async function changeStatus(page, status) {
    const card = await taskCard(page);
    const response = page.waitForResponse(result =>
        result.request().method() === 'POST'
        && result.url().includes('/tasks/boards/task/' + taskUid + '/update'), { timeout: 12000 });
    await card.locator('form[data-board-task-mutation] select[name="status"]').selectOption(status);
    return response;
}
async function changeMembership(owner, memberId, add) {
    const form = owner.locator('form[action*="/tasks/boards/"][action$="/members"]');
    await form.waitFor({ state: 'visible', timeout: 10000 });
    const checkbox = form.locator('input[name="member_ids[]"][value="' + memberId + '"]');
    await checkbox.waitFor({ state: 'attached' });
    if (add) await checkbox.check(); else await checkbox.uncheck();
    const response = owner.waitForResponse(result => result.request().method() === 'POST'
        && result.url().endsWith('/members'), { timeout: 15000 });
    await form.getByRole('button', { name: 'Сохранить состав' }).click();
    return response;
}

let owner;
let member;
try {
    owner = await login(ownerUser, 1366);
    member = await login(memberUser, 390);
    const ownerCard = await taskCard(owner.page);
    const memberCard = await taskCard(member.page);
    assert.equal(await ownerCard.getAttribute('data-version'), '1');
    assert.equal(await memberCard.getAttribute('data-version'), '1');

    const changed = await changeStatus(member.page, 'in_progress');
    assert.equal(changed.status(), 200, 'Второй пользователь не смог изменить задачу');
    await member.page.waitForFunction(uid =>
        document.querySelector('.task-board-card[data-task-uid="' + uid + '"]')?.dataset.version === '2',
        taskUid, { timeout: 12000 });

    const stale = await changeStatus(owner.page, 'review');
    assert.equal(stale.status(), 409, 'Конфликт двух открытых вкладок не обнаружен');

    // Владелец отзывает доступ через реальную форму управления доской и CSRF.
    const memberId = process.env.E2E_SECOND_ID;
    assert.ok(memberId, 'Не передан идентификатор второго пользователя');
    const removed = await changeMembership(owner.page, memberId, false);
    assert.ok([200, 302, 303].includes(removed.status()), 'Форма отзыва прав не обработана');
    const revokedChange = await changeStatus(member.page, 'completed');
    assert.ok([403, 404].includes(revokedChange.status()),
        'Открытая страница позволила сохранить задачу после отзыва доступа');

    const refreshed = await member.page.goto(boardUrl, { waitUntil: 'domcontentloaded' });
    assert.ok([200, 302, 303, 403, 404].includes(refreshed?.status() ?? 0));
    assert.equal(await member.page.locator('.task-board-card[data-task-uid="' + taskUid + '"]').count(), 0,
        'Отозванный участник сохранил доступ к карточке после обновления страницы');

    await owner.page.goto(boardUrl, { waitUntil: 'domcontentloaded' });
    const finalOwnerCard = await taskCard(owner.page);
    assert.equal(await finalOwnerCard.getAttribute('data-version'), '2',
        'Неудавшиеся действия перезаписали задачу');
    assert.equal(await finalOwnerCard.locator('select[name="status"]').first().inputValue(), 'in_progress',
        'После отзыва доступа статус изменился неверно');

    // Повторное приглашение проводится тем же штатным HTML-формуляром.
    const returned = await changeMembership(owner.page, memberId, true);
    assert.ok([200, 302, 303].includes(returned.status()));
    await member.page.goto(boardUrl, { waitUntil: 'domcontentloaded' });
    assert.equal(await (await taskCard(member.page)).getAttribute('data-version'), '2',
        'Восстановление доступа не вернуло актуальную карточку');

    assert.deepEqual(errors, [], 'Необработанные ошибки браузера');
    console.log('Приёмка двух сессий: вход, конфликт 409, отзыв через форму, запрет старой страницы и восстановление — успешно');
} finally {
    await owner?.context.close();
    await member?.context.close();
    await browser.close();
}
