import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

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
let externalSteps = 0;

function instrument(page) {
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin === origin && url.pathname.startsWith(`${basePath}/update-continuations/`)
        && response.status() === 200) externalSteps += 1;
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

async function installUpdateFromAdmin(page) {
  const response = await page.goto(`${baseUrl}/admin/updates/`, { waitUntil: 'domcontentloaded' });
  if (response?.status() !== 200) throw new Error(`Экран обновлений вернул HTTP ${response?.status()}`);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.getByRole('link', { name: 'Проверить обновления', exact: false }).click(),
  ]);
  const targetCard = page.locator('.admin-status-card').filter({ hasText: 'Целевая версия' }).first();
  await targetCard.getByText(`${expectedVersion} (${expectedVersionCode})`, { exact: true }).waitFor({ state: 'visible' });
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 180000 });
  await page.getByRole('button', { name: 'Установить обновление', exact: false }).click();
  return await navigation;
}

try {
  const context = await browser.newContext();
  if (process.env.E2E_ADMIN_PREPARATION === '1') {
    const denied = await context.request.get(`${baseUrl}/prepare-1.1.php`);
    if (denied.status() !== 403) throw new Error('Preparation must deny anonymous users');
  }
  const page = await context.newPage();
  instrument(page);
  await login(page);

  if (process.env.E2E_ADMIN_PREPARATION === '1') {
    const root = requiredEnv('E2E_APP_ROOT');
    const protectedFiles = ['.env', 'core/Version.php', 'config/update_trusted_keys.php', 'config/license_trusted_keys.php'];
    const hashes = () => protectedFiles.map((file) => createHash('sha256').update(readFileSync(`${root}/${file}`)).digest('hex'));
    const before = hashes();
    const denied = await context.request.post(`${baseUrl}/prepare-1.1.php`, { form: { csrf: 'invalid' } });
    if (denied.status() !== 403) throw new Error('Preparation must enforce CSRF');
    await page.goto(`${baseUrl}/prepare-1.1.php`);
    await page.getByText('Минимальные требования подготовки выполнены', { exact: true }).waitFor();
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.getByRole('button', { name: 'Установить подготовительный патч', exact: true }).click(),
    ]);
    await page.getByText('Подготовительный патч установлен.', { exact: false }).waitFor();
    if (JSON.stringify(before) !== JSON.stringify(hashes())) throw new Error('Preparation modified protected installation identity');
  }

  let response = await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Главная страница вернула HTTP ${response?.status()}`);
  }

  const bodyBeforeUpdate = ((await page.locator('body').innerText().catch(() => '')) || '').trim();
  if (!bodyBeforeUpdate.includes(sourceVersion)) {
    throw new Error(`До обновления интерфейс не подтверждает исходную версию ${sourceVersion} (${sourceVersionCode})`);
  }

  installationWindow = true;
  const installResponse = process.env.E2E_UPDATE_ENTRY === 'admin'
    ? await installUpdateFromAdmin(page)
    : await installUpdateFromNotification(page);
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
  if (process.env.E2E_REQUIRE_EXTERNAL === '1' && externalSteps === 0) {
    throw new Error('Обновление не использовало внешний HTTP runtime');
  }
  if (unexpectedHttpErrors.length) {
    throw new Error(`Неожиданные HTTP-ошибки: ${unexpectedHttpErrors.join(', ')}`);
  }

  if (maintenanceHttpErrors.length) {
    console.log(
      `Во время maintenance ожидаемо отклонено фоновых socket-ticket запросов: ${maintenanceHttpErrors.length}`
    );
  }
  console.log(`Обновление через ${process.env.E2E_UPDATE_ENTRY === 'admin' ? 'админку' : 'уведомление'}: OK (${expectedVersion})`);
  await context.close();
} finally {
  await browser.close();
}
