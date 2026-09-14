import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePathRaw = requiredEnv('E2E_BASE_PATH');
const username = requiredEnv('E2E_USER');
const password = requiredEnv('E2E_PASSWORD');
const targetUsername = requiredEnv('E2E_TARGET_USER');
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

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  const adminResponse = await page.goto(`${baseUrl}/admin/`, { waitUntil: 'domcontentloaded' });
  if (!adminResponse || adminResponse.status() !== 200) throw new Error(`Admin page returned ${adminResponse?.status()}`);

  // Prove the Admin JS asset loads under BASE_PATH by exercising its dynamic field UI.
  await page.locator('#add-field-btn').click();
  const transientField = page.locator('#custom-fields-container .custom-field').last();
  await transientField.waitFor({ state: 'visible', timeout: 5000 });
  await transientField.locator('.remove-field').click();
  await transientField.waitFor({ state: 'detached', timeout: 5000 });

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
  const settingsLink = page.getByRole('link', { name: /Настройки и квоты/ });
  const settingsHref = await settingsLink.getAttribute('href');
  if (!settingsHref || !new URL(settingsHref, origin).pathname.startsWith(`${basePath}/admin/settings`)) {
    throw new Error(`Admin settings URL escaped BASE_PATH: ${settingsHref}`);
  }
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    settingsLink.click(),
  ]);

  const storageRow = page.locator('.admin-users-table tbody tr').filter({ hasText: `@${targetUsername}` });
  await storageRow.waitFor({ state: 'visible', timeout: 10000 });
  const quotaInput = storageRow.locator('input[name="quota_mb"]');
  await quotaInput.fill('25');
  await submitAndWait(page, storageRow.getByRole('button', { name: 'Применить', exact: true }));

  await page.locator('.admin-page__flash').filter({ hasText: 'Персональный лимит обновлён' })
    .waitFor({ state: 'visible', timeout: 10000 });
  const updatedStorageRow = page.locator('.admin-users-table tbody tr').filter({ hasText: `@${targetUsername}` });
  if ((await updatedStorageRow.locator('input[name="quota_mb"]').inputValue()) !== '25') {
    throw new Error('Personal quota value did not round-trip through the admin form');
  }
  await updatedStorageRow.getByText('25 МБ', { exact: true }).waitFor({ state: 'visible', timeout: 10000 });

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) {
    throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  }
  if (unexpectedHttpErrors.length) {
    throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log('Admin status/quota browser lifecycle: OK');
  await context.close();
} finally {
  await browser.close();
}
