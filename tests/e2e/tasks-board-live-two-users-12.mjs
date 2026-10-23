import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const origin = process.env.E2E_TASKS_ORIGIN || 'http://127.0.0.1:18861';
const secondary = process.env.E2E_TASKS_SECONDARY || 'http://127.0.0.1:18862';
const boardUid = 'a'.repeat(32);
const taskUid = 'b'.repeat(32);
const boardPath = '/api/v1/task-boards/' + boardUid;
const taskPath = '/api/v1/task-board-items/' + taskUid;
const browser = await chromium.launch({
    headless: true,
    ...(process.env.E2E_BROWSER_EXECUTABLE
        ? { executablePath: process.env.E2E_BROWSER_EXECUTABLE } : { channel: 'chromium' }),
});
const errors = [];
const contexts = [];

async function openUser(url, tokenDigit) {
    const context = await browser.newContext({ viewport: { width: 390, height: 850 } });
    contexts.push(context);
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(url + '/api/v1/task-boards');
    await page.setContent('<main><h1>Испытание общей доски</h1><output id="result"></output></main>');
    const token = 'ntk1_' + String(tokenDigit).repeat(64);
    async function call(method, path, body) {
        return page.evaluate(async ({ method, path, body, token }) => {
            const response = await fetch(path, {
                method,
                headers: {
                    Authorization: 'Bearer ' + token,
                    Accept: 'application/json',
                    ...(body === undefined ? {} : {'Content-Type': 'application/json'})
                },
                ...(body === undefined ? {} : {body: JSON.stringify(body)})
            });
            const data = await response.json();
            document.getElementById('result').textContent = String(response.status);
            return {status: response.status, data};
        }, {method, path, body, token});
    }
    return {context, page, call};
}
function expectStatus(result, status, description) {
    assert.equal(result.status, status, description + ': ' + JSON.stringify(result.data));
    return result.data;
}
function versioned(id, version, field, value) {
    return { command_id: id, expected_version: version, [field]: value };
}
function memberChange(id, before, after) {
    return {command_id: id, expected_role: before, role: after};
}

try {
    // Два полностью независимых окна Chromium обращаются к двум PHP-процессам
    // с разными собственными действующими ключами Tasks API.
    const owner = await openUser(origin, 1);
    const colleague = await openUser(secondary, 2);
    const ownerList = expectStatus(await owner.call('GET', boardPath + '/tasks'), 200,
        'Владелец не видит задачу');
    const colleagueList = expectStatus(await colleague.call('GET', boardPath + '/tasks'), 200,
        'Руководитель не видит задачу');
    assert.equal(ownerList.items.length, 1);
    assert.equal(colleagueList.items.length, 1);
    assert.equal(ownerList.items[0].version, 1);
    assert.equal(colleagueList.items[0].version, 1);

    const initialCommand = 'browser-colleague-write-001';
    const colleagueEdit = expectStatus(await colleague.call('PATCH', taskPath,
        versioned(initialCommand, 1, 'status', 'in_progress')), 200,
        'Вторая учётная запись не изменила задачу');
    assert.equal(colleagueEdit.version, 2);

    // Сервер повторяет квитанцию, а не применяет изменение дважды.
    const colleagueRepeat = expectStatus(await colleague.call('PATCH', taskPath,
        versioned(initialCommand, 1, 'status', 'in_progress')), 200,
        'Повтор той же команды не обработан');
    assert.equal(colleagueRepeat.replayed, true);
    assert.equal(colleagueRepeat.version, 2);

    const stale = expectStatus(await owner.call('PATCH', taskPath,
        versioned('browser-owner-stale-001', 1, 'title', 'Устаревшее изменение')), 409,
        'Владелец незаметно перезаписал изменение второго пользователя');
    assert.equal(stale.error, 'version_conflict');
    assert.equal(stale.current_version, 2);

    const ownerEdit = expectStatus(await owner.call('PATCH', taskPath,
        versioned('browser-owner-current-001', 2, 'title', 'Актуальная редакция')), 200,
        'Владелец не смог исправить конфликт');
    assert.equal(ownerEdit.version, 3);
    const refreshed = expectStatus(await colleague.call('GET', boardPath + '/tasks'), 200,
        'Второй пользователь не получил актуальные данные');
    assert.equal(refreshed.items[0].title, 'Актуальная редакция');
    assert.equal(refreshed.items[0].version, 3);

    // Одновременно посылаем две разные команды с одной ожидаемой версией.
    const races = await Promise.all([
        owner.call('PATCH', taskPath, versioned('browser-race-owner-001', 3, 'title', 'Победил владелец')),
        colleague.call('PATCH', taskPath, versioned('browser-race-colleague-001', 3, 'title', 'Победил коллега')),
    ]);
    assert.deepEqual(races.map(x => x.status).sort(), [200, 409],
        'Конкурентное изменение породило потерю версии: ' + JSON.stringify(races));
    const winning = races.find(x => x.status === 200);
    assert.equal(winning.data.version, 4);

    // Открытое окно коллеги не получает особого права сохранять по старой роли.
    await owner.call('GET', '/api/v1/task-boards');
    const viewer = expectStatus(await owner.call('PUT', boardPath + '/members/12',
        memberChange('browser-to-viewer-001', 'manager', 'viewer')), 200,
        'Владелец не понизил роль');
    assert.equal(viewer.role, 'viewer');
    const viewerRead = expectStatus(await colleague.call('GET', boardPath + '/tasks'), 200,
        'Наблюдатель потерял разрешённое чтение');
    assert.equal(viewerRead.access_role, 'viewer');
    expectStatus(await colleague.call('PATCH', taskPath,
        versioned('browser-viewer-denied-001', 4, 'status', 'review')), 403,
        'Наблюдатель сохранил право записи');

    const removed = expectStatus(await owner.call('PUT', boardPath + '/members/12',
        memberChange('browser-remove-colleague-001', 'viewer', null)), 200,
        'Отзыв доступа не применён');
    assert.equal(removed.role, null);

    // Ни переоткрытие списка, ни сохранённая ссылка, ни старый command_id
    // не должны давать доступ после отзыва; Bearer остаётся действующим.
    const noBoards = expectStatus(await colleague.call('GET', '/api/v1/task-boards'), 200,
        'Прекратил действовать ключ API вместо ACL доски');
    assert.deepEqual(noBoards.items, []);
    const deniedRead = expectStatus(await colleague.call('GET', boardPath + '/tasks'), 404,
        'Отозванный пользователь прочитал закрытую доску');
    assert.equal(deniedRead.error, 'not_found');
    expectStatus(await colleague.call('PATCH', taskPath,
        versioned('browser-after-revoke-001', 4, 'status', 'completed')), 404,
        'Открытая вкладка позволила запись после отзыва доступа');
    expectStatus(await colleague.call('PATCH', taskPath,
        versioned(initialCommand, 1, 'status', 'in_progress')), 404,
        'Старый command_id позволил обойти отзыв доступа');

    // После явного повторного добавления нужна актуальная версия.
    expectStatus(await owner.call('PUT', boardPath + '/members/12',
        memberChange('browser-readd-colleague-001', null, 'member')), 200,
        'Повторное приглашение не действует');
    const returned = expectStatus(await colleague.call('GET', boardPath + '/tasks'), 200,
        'После приглашения нельзя снова прочитать доску');
    assert.equal(returned.items[0].version, 4);
    expectStatus(await colleague.call('PATCH', taskPath,
        versioned('browser-readd-stale-001', 1, 'status', 'completed')), 409,
        'После повторного приглашения принята старая версия');

    const recovered = expectStatus(await colleague.call('PATCH', taskPath,
        versioned('browser-readd-current-001', 4, 'status', 'review')), 200,
        'После восстановления доступа новая команда не прошла');
    assert.equal(recovered.version, 5);
    const final = expectStatus(await owner.call('GET', boardPath + '/tasks'), 200,
        'Владелец потерял доступ после изменений');
    assert.equal(final.items[0].status, 'review');
    assert.equal(final.items[0].version, 5);

    assert.deepEqual(errors, [], 'Ошибки при реальных запросах из Chromium');
    console.log('Два браузерных контекста: версии, 409, роль viewer, отзыв доступа, старый command_id и восстановление проверены');
} finally {
    for (const context of contexts) await context.close();
    await browser.close();
}
