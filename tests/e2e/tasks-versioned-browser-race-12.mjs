import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { addWorkspaceScript } from './page-runtime-fixture.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({
    headless: true,
    ...(process.env.E2E_BROWSER_EXECUTABLE
        ? { executablePath: process.env.E2E_BROWSER_EXECUTABLE }
        : { channel: 'chromium' }),
});
const errors = [];
const php = [
    'class V {',
    'function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8");}',
    'function route($name,$p=[]){return "/tasks/".($p["uid"]??"a").($name==="delete_task"?"/delete":"/update");}',
    'function csrfInput(){return \'<input type="hidden" name="csrf_token" value="fixture">\';}',
    '}',
    '$view=new V();',
    '$task=["uid"=>str_repeat("a",32),"title"=>"Исходная задача","status"=>"pending","priority"=>"medium","version"=>1];',
    'ob_start();require "modules/tasks/views/elements/task_item/index.php";echo ob_get_clean();'
].join('');
const personalHtml = execFileSync(process.env.E2E_PHP_BINARY || 'php', ['-r', php], {encoding:'utf8'});
const personalJs = await readFile('modules/tasks/assets/tasks-versioned-mutations.js', 'utf8');
const boardJs = await readFile('modules/tasks/assets/task-boards-versioned.js', 'utf8');

const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(test, description) {
    const start = Date.now();
    while (!test()) {
        assert.ok(Date.now() - start < 8000, 'Нет события: ' + description);
        await delay(20);
    }
}
async function setup(html, source) {
    const page = await browser.newPage({ viewport: { width: 390, height: 860 } });
    page.on('pageerror', e => errors.push(e.message));
    const requests = [];
    const pending = [];
    await page.route('http://tasks.test/**', async route => {
        if (route.request().method() === 'GET') {
            await route.fulfill({ status: 200, body: 'fixture' });
            return;
        }
        requests.push(new URLSearchParams(route.request().postData() || ''));
        pending.push(route);
    });
    await page.goto('http://tasks.test/');
    await page.setContent('<main>' + html + '</main>');
    await page.evaluate(() => {
        window.__alerts = [];
        window.alert = message => window.__alerts.push(message);
        window.confirm = () => true;
        window.wspace = {path: p => 'http://tasks.test' + p,
            navigation: {visit: async () => true}};
    });
    await addWorkspaceScript(page, {content: source});
    async function respond(status, body) {
        await until(() => pending.length > 0, 'ответ сервера');
        await pending.shift().fulfill({status, json: body});
    }
    return {page, requests, pending, respond};
}
function current(title, version, status='pending') {
    return {title, description:'', status, priority:'medium',
        due_date:null, archived_at:null, version};
}
async function personal() {
    const {page, requests, respond} = await setup('<section class="tasks">' +
        personalHtml + '</section>', personalJs);
    const task = page.locator('.task-item');
    const form = task.locator('.task-edit-form');
    await page.evaluate(() => {document.querySelector('.task-edit-panel').hidden=false;});
    async function submit(times=1) {
        await page.evaluate(n => {
            const form = document.querySelector('.task-edit-form');
            for(let i=0;i<n;i++) form.dispatchEvent(
                new Event('submit',{bubbles:true,cancelable:true}));
        }, times);
    }
    await form.locator('input[name="title"]').fill('Первое сохранение');
    await submit(2);
    await until(() => requests.length === 1, 'первое сохранение');
    assert.equal(await task.getAttribute('aria-busy'),'true');
    assert.equal(requests[0].get('expected_version'),'1');
    const firstCommand = requests[0].get('command_id');
    assert.ok(firstCommand);
    await delay(120);
    assert.equal(requests.length,1,'двойная отправка создала дубликат');
    await respond(200,{success:true,version:2,current:current('Первое сохранение',2)});
    await page.waitForFunction(() => document.querySelector('.task-item').dataset.version==='2' &&
        !document.querySelector('.task-item').hasAttribute('aria-busy'));
    assert.notEqual(await form.locator('input[name="command_id"]').inputValue(),firstCommand);

    await form.locator('input[name="title"]').fill('Устаревшие изменения');
    await submit(2);
    await until(() => requests.length === 2, 'версионный конфликт');
    await respond(409,{success:false,error:'version_conflict',
        message:'Задача изменена в другой вкладке',
        current:current('Данные из второй вкладки',3,'in_progress')});
    await page.waitForFunction(() => document.querySelector('.task-item').dataset.version==='3' &&
        !document.querySelector('.task-item').hasAttribute('aria-busy'));
    assert.equal(await task.locator('.task-title').textContent(),'Данные из второй вкладки');
    assert.equal(await form.locator('input[name="title"]').inputValue(),'Данные из второй вкладки');
    assert.equal(await form.locator('input[name="expected_version"]').inputValue(),'3');
    assert.ok((await page.evaluate(() => window.__alerts)).length > 0);

    await form.locator('input[name="title"]').fill('Повтор после ошибки');
    await submit();
    await until(() => requests.length === 3, 'временная ошибка');
    const repeatedCommand = requests[2].get('command_id');
    await respond(503,{success:false,message:'Сеть недоступна'});
    await page.waitForFunction(() => !document.querySelector('.task-item').hasAttribute('aria-busy'));
    assert.equal(await form.locator('input[name="command_id"]').inputValue(),repeatedCommand);
    assert.equal(await form.locator('input[name="title"]').isDisabled(),false);
    await submit();
    await until(() => requests.length === 4, 'безопасный повтор');
    assert.equal(requests[3].get('command_id'),repeatedCommand);
    assert.equal(requests[3].get('expected_version'),'3');
    await respond(200,{success:true,version:4,current:current('Повтор после ошибки',4,'in_progress')});
    await page.waitForFunction(() => document.querySelector('.task-item').dataset.version==='4' &&
        !document.querySelector('.task-item').hasAttribute('aria-busy'));

    await task.locator('.task-status-toggle').selectOption('completed');
    await until(() => requests.length === 5, 'изменение статуса');
    await submit();
    await delay(120);
    assert.equal(requests.length,5,'из формы обошли занятую карточку');
    await respond(200,{success:true,version:5,current:current('Повтор после ошибки',5,'completed')});
    await page.waitForFunction(() => document.querySelector('.task-item').dataset.version==='5' &&
        !document.querySelector('.task-item').hasAttribute('aria-busy'));
    assert.equal(await task.locator('.task-complete-toggle').isChecked(),true);

    await task.locator('.task-status-toggle').selectOption('pending');
    await until(() => requests.length === 6, 'отказ изменения статуса');
    await respond(503,{success:false,message:'Сбой транспорта'});
    await page.waitForFunction(() => !document.querySelector('.task-item').hasAttribute('aria-busy'));
    assert.equal(await task.locator('.task-status-toggle').inputValue(),'completed');
    assert.equal(await task.getAttribute('data-version'),'5');
    await page.close();
}
async function shared() {
    const html = '<section class="task-boards-page">' +
        '<section class="task-board-column" data-status="pending"><div class="task-board-column__items">' +
        '<article class="task-board-card" data-version="1">' +
        '<div class="task-board-card__title"><strong>Общая задача</strong></div>' +
        '<form action="http://tasks.test/board/update" data-board-task-mutation>' +
        '<input type="hidden" name="expected_version" value="1">' +
        '<input type="hidden" name="command_id" value="initial">' +
        '<select name="status"><option value="pending">Запланировано</option>' +
        '<option value="in_progress">В работе</option><option value="completed">Завершено</option></select>' +
        '</form><form action="http://tasks.test/board/update" data-board-task-mutation>' +
        '<input type="hidden" name="command_id" value="other">' +
        '<input type="hidden" name="expected_version" value="1">' +
        '<input type="hidden" name="archived" value="1"></form></article>' +
        '</div></section><section class="task-board-column" data-status="in_progress">' +
        '<div class="task-board-column__items"></div></section>' +
        '<section class="task-board-column" data-status="completed">' +
        '<div class="task-board-column__items"></div></section></section>';
    const {page,requests,respond} = await setup(html,boardJs);
    const card = page.locator('.task-board-card');
    const form = card.locator('form').first();
    await form.locator('select[name="status"]').selectOption('in_progress');
    await until(() => requests.length===1,'изменение на общей доске');
    await page.evaluate(() => document.querySelectorAll('[data-board-task-mutation]')[1]
        .dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
    await delay(120);
    assert.equal(requests.length,1,'вторая форма обошла блокировку общей задачи');
    await respond(200,{success:true,version:2,current:{version:2,status:'in_progress'}});
    await page.waitForFunction(() => document.querySelector('.task-board-card').dataset.version==='2' &&
        !document.querySelector('.task-board-card').hasAttribute('aria-busy'));
    assert.equal(await page.locator('.task-board-column[data-status="in_progress"] .task-board-card').count(),1);

    await form.locator('select[name="status"]').selectOption('completed');
    await until(() => requests.length===2,'конфликт в общей доске');
    await respond(409,{success:false,error:'version_conflict',
        message:'Изменено другим участником',
        current:{version:3,status:'pending',title:'Изменена коллегой'}});
    await page.waitForFunction(() => document.querySelector('.task-board-card').dataset.version==='3' &&
        !document.querySelector('.task-board-card').hasAttribute('aria-busy'));
    assert.equal(await form.locator('select[name="status"]').inputValue(),'pending');
    assert.equal(await card.locator('.task-board-card__title strong').textContent(),'Изменена коллегой');
    assert.equal(await form.locator('input[name="expected_version"]').inputValue(),'3');
    await page.close();
}
try {
    await personal();
    await shared();
    assert.deepEqual(errors,[], 'ошибки JS в браузере');
    console.log('Проверены двойные действия, конфликт вкладок, временный сбой и восстановление Tasks');
} finally {
    await browser.close();
}
