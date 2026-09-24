import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePathRaw = requiredEnv('E2E_BASE_PATH');
const username = requiredEnv('E2E_USER');
const targetUsername = requiredEnv('E2E_TARGET_USER');
const password = requiredEnv('E2E_PASSWORD');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const escapedRequests = [];
const unexpectedHttpErrors = [];

function instrument(page) {
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.origin === origin && url.pathname !== basePath && !url.pathname.startsWith(basePath + '/')) {
      escapedRequests.push(url.pathname);
    }
  });
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin === origin && response.status() >= 400) {
      unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
    }
  });
}

async function login(page) {
  const response = await page.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error(`Login page returned ${response?.status()}`);
  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);
}

async function submitAndWait(page, button) {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await button.click();
  await navigation;
}

function targetRow(page) {
  return page.locator('.admin-users-table tbody tr').filter({ hasText: `@${targetUsername}` });
}

function targetQuotaRow(page) {
  return page.locator('.admin-users-table tbody tr').filter({
    has: page.locator('input[name="quota_mb"]'),
    hasText: `@${targetUsername}`,
  });
}

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  const adminResponse = await page.goto(`${baseUrl}/admin/`, { waitUntil: 'domcontentloaded' });
  if (!adminResponse || adminResponse.status() !== 200) throw new Error(`Admin page returned ${adminResponse?.status()}`);

  // Admin owns its user-list controls server-side; generic findability JS must not build this surface.
  await page.locator('.admin-toolbar').waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#admin-search').waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#admin-sort').waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#admin-direction').waitFor({ state: 'visible', timeout: 10000 });
  if (await page.locator('[data-findability-slot]').count()) {
    throw new Error('Admin still exposes a generic findability slot');
  }

  // Prove the Admin JS asset loads under BASE_PATH by exercising its dynamic field UI.
  await page.locator('#add-field-btn').click();
  const transientField = page.locator('#custom-fields-container .custom-field[data-field-key^="new_"]').last();
  await transientField.waitFor({ state: 'visible', timeout: 5000 });
  const transientKey = await transientField.getAttribute('data-field-key');
  if (!transientKey) throw new Error('Dynamic Admin field has no stable data-field-key');
  const transientByKey = page.locator(`#custom-fields-container .custom-field[data-field-key="${transientKey}"]`);
  await transientByKey.locator('.remove-field').click();
  await transientByKey.waitFor({ state: 'detached', timeout: 5000 });

  let row = targetRow(page);
  await row.waitFor({ state: 'visible', timeout: 10000 });
  await row.locator('.admin-status').filter({ hasText: 'Активен' }).waitFor({ state: 'visible' });

  // Block the target user through the real admin form.
  await submitAndWait(page, row.getByRole('button', { name: 'Блокировать', exact: true }));
  await page.locator('.admin-page__flash').filter({ hasText: 'Пользователь заблокирован' })
    .waitFor({ state: 'visible', timeout: 10000 });
  row = targetRow(page);
  await row.locator('.admin-status').filter({ hasText: 'Заблокирован' }).waitFor({ state: 'visible' });

  // Reactivate through the server-rendered state transition.
  await submitAndWait(page, row.getByRole('button', { name: 'Активировать', exact: true }));
  await page.locator('.admin-page__flash').filter({ hasText: 'Пользователь активирован' })
    .waitFor({ state: 'visible', timeout: 10000 });
  row = targetRow(page);
  await row.locator('.admin-status').filter({ hasText: 'Активен' }).waitFor({ state: 'visible' });

  // Open quota settings using the real generated link.
  const settingsLink = page
    .getByRole('navigation', { name: 'Разделы админпанели' })
    .getByRole('link', { name: 'Системные настройки', exact: true });
  const settingsHref = await settingsLink.getAttribute('href');
  if (!settingsHref?.startsWith(`${basePath}/admin/settings`)) {
    throw new Error(`Admin settings link escaped BASE_PATH: ${settingsHref}`);
  }
  await Promise.all([
    page.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/admin/settings`, { timeout: 15000 }),
    settingsLink.click(),
  ]);

  const quotaRow = targetQuotaRow(page);
  await quotaRow.waitFor({ state: 'visible', timeout: 10000 });
  const quotaInput = quotaRow.locator('input[name="quota_mb"]');
  await quotaInput.fill('25');
  await submitAndWait(page, quotaRow.getByRole('button', { name: 'Применить', exact: true }));
  await page.locator('.admin-page__flash').filter({ hasText: 'Персональный лимит обновлён' })
    .waitFor({ state: 'visible', timeout: 10000 });

  const updatedQuotaRow = targetQuotaRow(page);
  await updatedQuotaRow.waitFor({ state: 'visible', timeout: 10000 });
  if ((await updatedQuotaRow.locator('input[name="quota_mb"]').inputValue()) !== '25') {
    throw new Error('Updated storage quota did not persist in Admin UI');
  }
  await updatedQuotaRow.getByText('25 МБ', { exact: false }).waitFor({ state: 'visible', timeout: 10000 });

  // Страница обновлений должна сохранять BASE_PATH и показывать штатный
  // автоматический доступ без сетевого запроса при простом открытии.
  const updatesLink = page
    .getByRole('navigation', { name: 'Разделы админпанели' })
    .getByRole('link', { name: 'Обновления', exact: true });
  const updatesHref = await updatesLink.getAttribute('href');
  if (!updatesHref?.startsWith(`${basePath}/admin/updates`)) {
    throw new Error(`Ссылка обновлений вышла за BASE_PATH: ${updatesHref}`);
  }
  await Promise.all([
    page.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/admin/updates`, { timeout: 15000 }),
    updatesLink.click(),
  ]);
  await page.getByRole('heading', { name: 'Обновления Workspace' }).waitFor({ state: 'visible', timeout: 10000 });
  await page.getByText('jsinteractive.ru/api/notes/v1/stable/feed.json', { exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });
  await page.getByText('Настроится автоматически', { exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });
  await page.getByRole('link', { name: 'Проверить обновления' })
    .waitFor({ state: 'visible', timeout: 10000 });
  if (await page.getByText('Обновлятор пока не готов:', { exact: false }).count()) {
    throw new Error('Штатный автоматический доступ ошибочно показан как неготовый');
  }
  if (await page.locator('.admin-status-grid .admin-status-card').count() < 8) {
    throw new Error('Локальное состояние обновлятора отображается не полностью');
  }
  if (await page.locator('form[action*="/admin/updates/stage"]').count()) {
    throw new Error('Форма подготовки пакета показана без подтверждённого update_available');
  }

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) {
    throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  }
  if (unexpectedHttpErrors.length) {
    throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log(`Admin lifecycle browser flow: OK (${targetUsername})`);
  await context.close();
} finally {
  await browser.close();
}
