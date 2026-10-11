import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Не задана обязательная переменная окружения: ${name}`);
  return value;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePath = '/' + requiredEnv('E2E_BASE_PATH').replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;
const aliceUser = requiredEnv('E2E_ALICE_USER');
const alicePassword = requiredEnv('E2E_ALICE_PASSWORD');
const bobUser = requiredEnv('E2E_BOB_USER');
const bobPassword = requiredEnv('E2E_BOB_PASSWORD');

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const escapedRequests = [];
const unexpectedHttpErrors = [];
let currentStage = 'инициализация';

function markStage(name) {
  currentStage = name;
  console.log(`[Calendar E2E] этап: ${name}`);
}

function instrument(page) {
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.origin !== origin) return;
    if (url.pathname === basePath || url.pathname.startsWith(basePath + '/')) return;
    escapedRequests.push(url.pathname);
  });
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin !== origin || response.status() < 400) return;
    if (url.pathname.includes('/api/calendar/events/')) return;
    unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
  });
}

async function login(page, username, password) {
  const response = await page.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Страница входа для ${username} вернула HTTP ${response?.status()}`);
  }
  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);
}

async function waitForCalendarIdle(page) {
  await page.locator('[data-calendar-loading]').waitFor({ state: 'hidden', timeout: 10000 });
}

async function openCalendar(page) {
  const response = await page.goto(`${baseUrl}/calendar/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Calendar вернул HTTP ${response?.status()}`);
  }
  await page.locator('[data-calendar-root]').waitFor({ state: 'visible', timeout: 10000 });
  await waitForCalendarIdle(page);
}

async function waitForEditorLoaded(page, expectedUid) {
  const editor = page.locator('[data-calendar-editor]');
  await editor.waitFor({ state: 'visible', timeout: 10000 });
  await page.waitForFunction((uid) => {
    const panel = document.querySelector('[data-calendar-editor]');
    const form = panel?.querySelector('[data-calendar-form]');
    const uidField = panel?.querySelector('[data-field="uid"]');
    return Boolean(
      panel
      && !panel.hidden
      && form
      && !form.classList.contains('is-saving')
      && uidField instanceof HTMLInputElement
      && uidField.value === uid
    );
  }, expectedUid, { timeout: 10000 });
  return editor;
}

function localDateParts(daysAhead = 1) {
  const value = new Date();
  value.setDate(value.getDate() + daysAhead);
  const pad = (part) => String(part).padStart(2, '0');
  const date = `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
  const until = new Date(value);
  until.setDate(until.getDate() + 21);
  const untilDate = `${until.getFullYear()}-${pad(until.getMonth() + 1)}-${pad(until.getDate())}`;
  return {
    start: `${date}T10:15`,
    end: `${date}T11:30`,
    until: untilDate,
    weekday: ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][value.getDay()],
  };
}

async function switchView(page, view) {
  await page.locator(`[data-calendar-view="${view}"]`).click();
  await waitForCalendarIdle(page);
  const pressed = await page.locator(`[data-calendar-view="${view}"]`).getAttribute('aria-pressed');
  if (pressed !== 'true') throw new Error(`Представление Calendar ${view} не стало активным`);
}

function reportFailure(error) {
  const message = String(error?.message || error).replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
  console.error(`::error title=Calendar E2E — ${currentStage}::${message}`);
  console.error(`[Calendar E2E] ошибка на этапе: ${currentStage}`);
  console.error(error?.stack || error);
}

try {
  markStage('вход Alice');
  const aliceContext = await browser.newContext({ locale: 'ru-RU', timezoneId: 'Europe/Stockholm' });
  const alicePage = await aliceContext.newPage();
  instrument(alicePage);
  await login(alicePage, aliceUser, alicePassword);

  markStage('открытие Calendar');
  await openCalendar(alicePage);

  const stamp = Date.now();
  const title = `Calendar lifecycle ${stamp}`;
  const updatedTitle = `${title} — изменено`;
  const description = `Описание браузерного сценария ${stamp}`;
  const updatedDescription = `${description}; сохранено повторно`;
  const location = 'Переговорная A';
  const updatedLocation = 'Переговорная B';
  const timing = localDateParts(1);

  markStage('переключение представлений');
  for (const view of ['week', 'day', 'month']) await switchView(alicePage, view);

  markStage('создание события');
  await alicePage.locator('[data-calendar-create]').click();
  const editor = alicePage.locator('[data-calendar-editor]');
  await editor.waitFor({ state: 'visible', timeout: 5000 });
  await editor.locator('[data-field="title"]').fill(title);
  await editor.locator('[data-field="description"]').fill(description);
  await editor.locator('[data-field="location"]').fill(location);
  await editor.locator('[data-field="starts_at"]').fill(timing.start);
  await editor.locator('[data-field="ends_at"]').fill(timing.end);
  await editor.locator('[data-field="timezone"]').fill('Europe/Stockholm');
  await editor.locator('[data-field="frequency"]').selectOption('weekly');
  await editor.locator('[data-field="interval"]').fill('1');
  await editor.locator('[data-field="until"]').fill(timing.until);
  const weekdayInput = editor.locator(`[data-weekday][value="${timing.weekday}"]`);
  await weekdayInput.locator('+ span').click();
  if (!(await weekdayInput.isChecked())) throw new Error('День недели не включился через видимый переключатель');
  const reminderInput = editor.locator('[data-reminder-offset][value="900"]');
  await reminderInput.locator('+ span').click();
  if (!(await reminderInput.isChecked())) throw new Error('Напоминание не включилось через видимый переключатель');

  const createRequest = alicePage.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === 'POST' && url.pathname.endsWith('/api/calendar/events/');
  }, { timeout: 10000 });
  await editor.locator('[data-calendar-save]').click();
  const createResponse = await createRequest;
  if (!createResponse.ok()) throw new Error(`Создание события вернуло HTTP ${createResponse.status()}`);
  const createdPayload = await createResponse.json();
  const eventUid = createdPayload?.event?.uid || '';
  if (!/^[0-9a-f-]{36}$/i.test(eventUid)) throw new Error('Созданное событие не вернуло корректный UID');

  markStage('проверка события после создания');
  await editor.waitFor({ state: 'hidden', timeout: 5000 });
  await waitForCalendarIdle(alicePage);
  await alicePage.locator('.calendar-event-chip').filter({ hasText: title }).first().waitFor({ state: 'visible', timeout: 10000 });
  for (const view of ['week', 'day']) {
    await switchView(alicePage, view);
    await alicePage.locator('.calendar-agenda-card').filter({ hasText: title }).first().waitFor({ state: 'visible', timeout: 10000 });
  }

  markStage('повторное открытие редактора');
  await alicePage.locator('.calendar-agenda-card').filter({ hasText: title }).first().click();
  await waitForEditorLoaded(alicePage, eventUid);
  if ((await editor.locator('[data-field="frequency"]').inputValue()) !== 'weekly') {
    throw new Error('Повторение события не восстановилось в редакторе');
  }
  if (!(await editor.locator('[data-reminder-offset][value="900"]').isChecked())) {
    throw new Error('Напоминание события не восстановилось в редакторе');
  }

  markStage('редактирование события');
  await editor.locator('[data-field="title"]').fill(updatedTitle);
  await editor.locator('[data-field="description"]').fill(updatedDescription);
  await editor.locator('[data-field="location"]').fill(updatedLocation);
  const updateRequest = alicePage.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === 'PUT' && url.pathname.endsWith(`/api/calendar/events/${eventUid}`);
  }, { timeout: 10000 });
  await editor.locator('[data-calendar-save]').click();
  const updateResponse = await updateRequest;
  if (!updateResponse.ok()) throw new Error(`Изменение события вернуло HTTP ${updateResponse.status()}`);
  await editor.waitFor({ state: 'hidden', timeout: 5000 });
  await waitForCalendarIdle(alicePage);
  await alicePage.locator('.calendar-agenda-card').filter({ hasText: updatedTitle }).first().waitFor({ state: 'visible', timeout: 10000 });

  markStage('проверка прямой ссылки Alice');
  const deepLinkResponse = await alicePage.goto(`${baseUrl}/calendar/?event=${eventUid}`, { waitUntil: 'domcontentloaded' });
  if (!deepLinkResponse || deepLinkResponse.status() !== 200) {
    throw new Error(`Глубокая ссылка Calendar вернула HTTP ${deepLinkResponse?.status()}`);
  }
  await waitForEditorLoaded(alicePage, eventUid);
  if ((await editor.locator('[data-field="title"]').inputValue()) !== updatedTitle) {
    throw new Error('Глубокая ссылка не восстановила изменённое название события');
  }
  if ((await editor.locator('[data-field="location"]').inputValue()) !== updatedLocation) {
    throw new Error('Глубокая ссылка не восстановила изменённое место события');
  }
  if ((await editor.locator('[data-field="description"]').inputValue()) !== updatedDescription) {
    throw new Error('Глубокая ссылка не восстановила описание события');
  }
  await editor.locator('[data-calendar-close]').last().click();
  await editor.waitFor({ state: 'hidden', timeout: 5000 });

  markStage('проверка изоляции Bob');
  const bobContext = await browser.newContext({ locale: 'ru-RU', timezoneId: 'Europe/Stockholm' });
  const bobPage = await bobContext.newPage();
  instrument(bobPage);
  await login(bobPage, bobUser, bobPassword);
  await openCalendar(bobPage);
  if (await bobPage.getByText(updatedTitle, { exact: true }).count()) {
    throw new Error('Calendar показал второму пользователю чужое событие');
  }
  const foreignRead = await bobPage.evaluate(async ({ uid, path }) => {
    const response = await fetch(`${path}/api/calendar/events/${encodeURIComponent(uid)}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    return { status: response.status, body: await response.json().catch(() => null) };
  }, { uid: eventUid, path: basePath });
  if (foreignRead.status !== 422 || foreignRead.body?.code !== 'invalid_calendar_request') {
    throw new Error(`Чтение чужого события завершилось неожиданно: ${JSON.stringify(foreignRead)}`);
  }
  await bobContext.close();

  markStage('удаление события Alice');
  await alicePage.goto(`${baseUrl}/calendar/?event=${eventUid}`, { waitUntil: 'domcontentloaded' });
  await waitForEditorLoaded(alicePage, eventUid);
  alicePage.once('dialog', (dialog) => dialog.accept());
  const deleteRequest = alicePage.waitForResponse((response) => {
    const url = new URL(response.url());
    return response.request().method() === 'DELETE' && url.pathname.endsWith(`/api/calendar/events/${eventUid}`);
  }, { timeout: 10000 });
  await editor.locator('[data-calendar-delete]').click();
  const deleteResponse = await deleteRequest;
  if (!deleteResponse.ok()) throw new Error(`Удаление события вернуло HTTP ${deleteResponse.status()}`);
  await editor.waitFor({ state: 'hidden', timeout: 5000 });
  await openCalendar(alicePage);
  if (await alicePage.getByText(updatedTitle, { exact: true }).count()) {
    throw new Error('Удалённое событие осталось видимым после перезагрузки');
  }

  markStage('финальная проверка');
  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) {
    throw new Error(`Запросы вышли за BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  }
  if (unexpectedHttpErrors.length) {
    throw new Error(`Неожиданные HTTP-ошибки: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log(`Браузерный жизненный цикл Calendar: OK (${eventUid})`);
  await aliceContext.close();
} catch (error) {
  reportFailure(error);
  throw error;
} finally {
  await browser.close();
}
