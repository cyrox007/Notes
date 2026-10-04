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

  // Публичная 1.0.11 после успешного web-updater commit делает обычный
  // reload текущей страницы и не создаёт flash синхронного Admin-контроллера.
  // Проверяем важный пользовательский результат: свежий HTTP-запрос уже должен
  // работать на целевой версии, а экран обновлений — подтверждать её установку.
  let versionConfirmed = false;
  let confirmationBody = '';
  for (let attempt = 0; attempt < 12; attempt += 1) {
    const separator = baseUrl.includes('?') ? '&' : '?';
    const confirmationUrl = `${baseUrl}/${separator}update_confirm=${Date.now()}-${attempt}`;
    const confirmationResponse = await page.goto(confirmationUrl, {
      waitUntil: 'domcontentloaded',
      timeout: 15000,
    });
    if (!confirmationResponse || confirmationResponse.status() >= 400) {
      throw new Error(`Свежий запрос после commit вернул HTTP ${confirmationResponse?.status()}`);
    }

    confirmationBody = ((await page.locator('body').innerText().catch(() => '')) || '').trim();
    if (confirmationBody.includes(expectedVersion)) {
      versionConfirmed = true;
      break;
    }
    await page.waitForTimeout(250);
  }

  if (!versionConfirmed) {
    throw new Error(
      `Обновление не подтвердило целевую версию ${expectedVersion} (${expectedVersionCode}); `
      + `первый URL=${installUrl}; страница=${confirmationBody.slice(0, 2000)}`
    );
  }

  const updatesResponse = await page.goto(`${baseUrl}/admin/updates/`, {
    waitUntil: 'domcontentloaded',
    timeout: 15000,
  });
  if (!updatesResponse || updatesResponse.status() !== 200) {
    throw new Error(`Экран обновлений после commit вернул HTTP ${updatesResponse?.status()}`);
  }

  const installedCard = page.locator('.admin-status-card').filter({ hasText: 'Установленная версия' }).first();
  await installedCard.getByText(expectedVersion, { exact: false })
    .waitFor({ state: 'visible', timeout: 10000 });

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
  console.log(`Автоматическое уведомление и обновление в один клик: OK (${expectedVersion})`);
  await context.close();
} finally {
  await browser.close();
}
