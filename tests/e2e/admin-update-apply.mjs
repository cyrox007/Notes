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
const expectedVersion = requiredEnv('E2E_TARGET_VERSION');
const expectedVersionCode = requiredEnv('E2E_TARGET_VERSION_CODE');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const unexpectedHttpErrors = [];
const maintenanceHttpErrors = [];
let installationWindow = false;

function instrument(page) {
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin !== origin || response.status() < 400) {
      return;
    }

    const failure = `${response.status()} ${url.pathname}`;
    const socketTicketPath = `${basePath}/messenger/socket-ticket`;
    if (installationWindow && response.status() === 503 && url.pathname === socketTicketPath) {
      maintenanceHttpErrors.push(failure);
      return;
    }

    unexpectedHttpErrors.push(failure);
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

async function installUpdateFromNotification(page) {
  const bell = page.locator('[data-update-notifications-toggle]');
  await bell.waitFor({ state: 'visible', timeout: 10000 });

  const badge = page.locator('[data-update-badge]');
  await badge.waitFor({ state: 'visible', timeout: 30000 });
  await bell.click();

  const item = page.locator('[data-update-item]');
  await item.waitFor({ state: 'visible', timeout: 10000 });
  const updateTitle = item.locator('[data-update-title]');
  await updateTitle.getByText(expectedVersion, { exact: false })
    .waitFor({ state: 'visible', timeout: 10000 });

  const updateButton = page.locator('[data-update-action]');
  await updateButton.waitFor({ state: 'visible', timeout: 10000 });
  const label = ((await updateButton.textContent()) || '').trim();
  if (!label.includes(expectedVersion)) {
    throw new Error(`Уведомление предлагает неожиданную версию: ${label}`);
  }

  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 180000 });
  await updateButton.click();
  return await navigation;
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

  const bodyBeforeUpdate = ((await page.locator('body').innerText().catch(() => '')) || '').trim();
  if (!bodyBeforeUpdate.includes(sourceVersion)) {
    throw new Error(`До обновления интерфейс не подтверждает исходную версию ${sourceVersion} (${sourceVersionCode})`);
  }

  installationWindow = true;
  const installResponse = await installUpdateFromNotification(page);
  const installStatus = installResponse?.status() ?? 0;
  const installUrl = page.url();

  if (installStatus >= 400 || unexpectedHttpErrors.length) {
    const body = ((await page.locator('body').innerText().catch(() => '')) || '').trim().slice(0, 2000);
    throw new Error(
      `POST установки завершился HTTP ${installStatus}; URL=${installUrl}; `
      + `HTTP-ошибки=${unexpectedHttpErrors.join(', ') || 'нет'}; страница=${body}`
    );
  }

  const flash = page.locator('.admin-page__flash').first();
  const flashVisible = await flash.isVisible({ timeout: 15000 }).catch(() => false);
  if (!flashVisible) {
    const body = ((await page.locator('body').innerText().catch(() => '')) || '').trim().slice(0, 2000);
    throw new Error(
      `После POST установки нет сообщения результата; HTTP=${installStatus}; URL=${installUrl}; страница=${body}`
    );
  }
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

  installationWindow = false;
  await page.waitForTimeout(1500);

  if (pageErrors.length) throw pageErrors[0];
  if (unexpectedHttpErrors.length) {
    throw new Error(`Неожиданные HTTP-ошибки: ${unexpectedHttpErrors.join(', ')}`);
  }

  if (maintenanceHttpErrors.length) {
    console.log(
      `Во время maintenance ожидаемо отклонено фоновых socket-ticket запросов: ${maintenanceHttpErrors.length}`
    );
  }
  console.log(`Автоматическое уведомление и обновление в один клик: OK (${expectedVersion}, ${transactionId})`);
  await context.close();
} finally {
  await browser.close();
}
