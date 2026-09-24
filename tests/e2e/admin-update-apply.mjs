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
const expectedVersion = requiredEnv('E2E_TARGET_VERSION');
const expectedVersionCode = requiredEnv('E2E_TARGET_VERSION_CODE');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const unexpectedHttpErrors = [];

function instrument(page) {
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin === origin && response.status() >= 400) {
      unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
    }
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

async function installUpdate(page) {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 180000 });
  await page.getByRole('button', { name: 'Установить обновление', exact: true }).click();

  const confirm = page.getByRole('button', { name: 'Установить', exact: true });
  const visible = await confirm.isVisible({ timeout: 800 }).catch(() => false);
  if (visible) {
    await confirm.click();
  }

  await navigation;
}

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  let response = await page.goto(`${baseUrl}/admin/updates`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Раздел обновлений вернул HTTP ${response?.status()}`);
  }

  await page.getByRole('heading', { name: 'Обновления Workspace', exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });
  await page.getByText('1.0.2 (10002)', { exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
    page.getByRole('link', { name: 'Проверить обновления', exact: true }).click(),
  ]);

  await page.getByRole('heading', { name: 'Доступно обновление', exact: true })
    .waitFor({ state: 'visible', timeout: 15000 });
  await page.getByText(`${expectedVersion} (${expectedVersionCode})`, { exact: true })
    .waitFor({ state: 'visible', timeout: 15000 });
  await page.getByRole('button', { name: 'Установить обновление', exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });

  await installUpdate(page);

  const flash = page.locator('.admin-page__flash').first();
  await flash.waitFor({ state: 'visible', timeout: 15000 });
  const flashText = ((await flash.textContent()) || '').trim();
  if (!flashText.includes('Обновление установлено. Workspace Organizer работает на новой версии.')) {
    throw new Error(`Установка из Admin UI завершилась без подтверждения успеха: ${flashText}`);
  }

  await page.getByRole('heading', { name: 'Обновление установлено', exact: true })
    .waitFor({ state: 'visible', timeout: 15000 });
  await page.getByText(`${expectedVersion} (${expectedVersionCode})`, { exact: true })
    .first()
    .waitFor({ state: 'visible', timeout: 15000 });

  const installedCard = page.locator('.admin-status-card').filter({ hasText: 'Установленная версия' }).first();
  await installedCard.getByText(expectedVersion, { exact: false })
    .waitFor({ state: 'visible', timeout: 10000 });

  const transaction = page.locator('.admin-status-card').filter({ hasText: 'Транзакция' }).locator('code');
  const transactionId = (await transaction.textContent())?.trim() || '';
  if (!/^update-[A-Za-z0-9_-]{8,}$/.test(transactionId)) {
    throw new Error(`Некорректный идентификатор транзакции: ${transactionId}`);
  }

  if (pageErrors.length) throw pageErrors[0];
  if (unexpectedHttpErrors.length) {
    throw new Error(`Неожиданные HTTP-ошибки: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log(`Admin update E2E: OK (${expectedVersion}, ${transactionId})`);
  await context.close();
} finally {
  await browser.close();
}
