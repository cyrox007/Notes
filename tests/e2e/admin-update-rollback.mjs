import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Не задана обязательная переменная ${name}`);
  return value;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePathRaw = requiredEnv('E2E_BASE_PATH');
const username = requiredEnv('E2E_USER');
const password = requiredEnv('E2E_PASSWORD');
const sourceVersion = requiredEnv('E2E_SOURCE_VERSION');
const sourceVersionCode = requiredEnv('E2E_SOURCE_VERSION_CODE');
const brokenVersion = requiredEnv('E2E_BROKEN_TARGET_VERSION');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const unexpectedHttpErrors = [];
let installationWindow = false;

function instrument(page) {
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin !== origin || response.status() < 400) return;

    const socketTicketPath = `${basePath}/messenger/socket-ticket`;
    if (installationWindow && response.status() === 503 && url.pathname === socketTicketPath) {
      return;
    }
    unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
  });
  page.on('dialog', async (dialog) => {
    await dialog.accept();
  });
}

async function login(page) {
  const response = await page.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Страница входа вернула HTTP ${response?.status()}`);
  }

  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);
}

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  let response = await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Главная страница вернула HTTP ${response?.status()}`);
  }

  const bodyBefore = ((await page.locator('body').innerText().catch(() => '')) || '').trim();
  if (!bodyBefore.includes(sourceVersion)) {
    throw new Error(`Перед отказоустойчивым тестом не подтверждена версия ${sourceVersion} (${sourceVersionCode})`);
  }

  const bell = page.locator('[data-update-notifications-toggle]');
  const badge = page.locator('[data-update-badge]');
  await bell.waitFor({ state: 'visible', timeout: 10000 });
  await badge.waitFor({ state: 'visible', timeout: 30000 });
  await bell.click();

  const item = page.locator('[data-update-item]');
  await item.waitFor({ state: 'visible', timeout: 10000 });
  await item.locator('[data-update-title]').getByText(brokenVersion, { exact: false })
    .waitFor({ state: 'visible', timeout: 10000 });

  const button = page.locator('[data-update-action]');
  await button.waitFor({ state: 'visible', timeout: 10000 });

  installationWindow = true;
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 180000 });
  await button.click();
  const installResponse = await navigation;
  installationWindow = false;

  if (!installResponse || installResponse.status() >= 400 || unexpectedHttpErrors.length) {
    throw new Error(
      `Неудачное обновление не вернулось штатно в интерфейс: HTTP=${installResponse?.status()}; `
      + `ошибки=${unexpectedHttpErrors.join(', ') || 'нет'}`
    );
  }

  const flash = page.locator('.admin-page__flash').first();
  await flash.waitFor({ state: 'visible', timeout: 15000 });
  const flashText = ((await flash.textContent()) || '').trim();
  if (!flashText.includes('Рабочая версия автоматически восстановлена и проверена')) {
    throw new Error(`Интерфейс не подтвердил автоматическое восстановление: ${flashText}`);
  }

  const installedCard = page.locator('.admin-status-card').filter({ hasText: 'Установленная версия' }).first();
  await installedCard.getByText(sourceVersion, { exact: false })
    .waitFor({ state: 'visible', timeout: 10000 });

  if (pageErrors.length) throw pageErrors[0];
  if (unexpectedHttpErrors.length) {
    throw new Error(`Неожиданные HTTP-ошибки: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log(`Автоматический откат после ошибки обновления: OK (${brokenVersion} → ${sourceVersion})`);
  await context.close();
} finally {
  await browser.close();
}
