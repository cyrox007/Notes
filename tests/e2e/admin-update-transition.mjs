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
const sourceVersion = requiredEnv('TRANSITION_SOURCE_VERSION');
const sourceVersionCode = requiredEnv('TRANSITION_SOURCE_VERSION_CODE');
const targetVersion = requiredEnv('TRANSITION_TARGET_VERSION');
const targetVersionCode = requiredEnv('TRANSITION_TARGET_VERSION_CODE');
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

  let response = await page.goto(`${baseUrl}/admin/updates`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Раздел обновлений вернул HTTP ${response?.status()}`);
  }

  await page.getByText(`${sourceVersion} (${sourceVersionCode})`, { exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
    page.getByRole('link', { name: 'Проверить обновления', exact: true }).click(),
  ]);

  await page.getByRole('heading', { name: 'Доступно обновление', exact: true })
    .waitFor({ state: 'visible', timeout: 15000 });
  await page.getByText(`${targetVersion} (${targetVersionCode})`, { exact: true })
    .waitFor({ state: 'visible', timeout: 15000 });

  installationWindow = true;
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 180000 });
  await page.getByRole('button', { name: 'Установить обновление', exact: true }).click();

  const confirm = page.getByRole('button', { name: 'Установить', exact: true });
  if (await confirm.isVisible({ timeout: 800 }).catch(() => false)) {
    await confirm.click();
  }
  const installResponse = await navigation;
  installationWindow = false;

  if (!installResponse || installResponse.status() >= 400 || unexpectedHttpErrors.length) {
    throw new Error(
      `Переход ${sourceVersion} → ${targetVersion} завершился ошибкой: HTTP=${installResponse?.status()}; `
      + `ошибки=${unexpectedHttpErrors.join(', ') || 'нет'}`
    );
  }

  const flash = page.locator('.admin-page__flash').first();
  await flash.waitFor({ state: 'visible', timeout: 15000 });
  const flashText = ((await flash.textContent()) || '').trim();
  if (!flashText.includes('Обновление установлено. Workspace Organizer работает на новой версии.')) {
    throw new Error(`Нет подтверждения успешной установки: ${flashText}`);
  }

  await page.getByText(`${targetVersion} (${targetVersionCode})`, { exact: true })
    .first()
    .waitFor({ state: 'visible', timeout: 15000 });

  if (pageErrors.length) throw pageErrors[0];
  if (unexpectedHttpErrors.length) {
    throw new Error(`Неожиданные HTTP-ошибки: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log(`Переходная установка из Admin UI: OK (${sourceVersion} → ${targetVersion})`);
  await context.close();
} finally {
  await browser.close();
}
