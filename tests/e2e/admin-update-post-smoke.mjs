import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = String(process.env[name] || '').trim();
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

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  const pageErrors = [];
  const serverErrors = [];

  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin !== origin || response.status() < 500) return;
    serverErrors.push(`${response.status()} ${url.pathname}`);
  });

  const loginResponse = await page.goto(`${baseUrl}/auth/login/`, {
    waitUntil: 'domcontentloaded',
  });
  if (!loginResponse || loginResponse.status() !== 200) {
    throw new Error(`Страница входа вернула HTTP ${loginResponse?.status()}`);
  }

  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), {
      timeout: 15000,
    }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);

  for (const path of ['/notes/', '/tasks/', '/files/', '/profile/', '/admin/updates/']) {
    const response = await page.goto(`${baseUrl}${path}`, {
      waitUntil: 'domcontentloaded',
    });
    if (!response || response.status() !== 200) {
      throw new Error(`После обновления ${path} вернул HTTP ${response?.status()}`);
    }
  }

  await page.goto(`${baseUrl}/profile/`, { waitUntil: 'domcontentloaded' });

  const editPanel = page.locator('.profile__card-info--edit');
  if (await editPanel.isVisible()) {
    throw new Error('После обновления панель редактирования Profile видна без действия пользователя');
  }

  const avatar = page.locator('.profile__card-avatar img');
  const avatarBox = await avatar.boundingBox();
  if (!avatarBox || avatarBox.width > 120 || avatarBox.height > 120) {
    throw new Error(
      `После обновления стили Profile не применились: аватар ${avatarBox?.width}x${avatarBox?.height}`
    );
  }

  await page.goto(`${baseUrl}/messenger/`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const app = window.wspace?.messenger;
    const status = document.getElementById('messenger-connection');
    return app?.longPollActive === true
      && app?.socketAuthorized !== true
      && status?.dataset.state === 'online'
      && status?.dataset.transport === 'long-poll';
  }, null, { timeout: 20000 });

  const transport = await page.evaluate(() => {
    const connection = document.getElementById('messenger-connection');
    const dot = connection?.querySelector('.messenger-connection__dot');
    return {
      state: connection?.dataset.state || '',
      transport: connection?.dataset.transport || '',
      dotColor: dot ? getComputedStyle(dot).backgroundColor : '',
    };
  });

  if (transport.state !== 'online' || transport.transport !== 'long-poll') {
    throw new Error(
      `Messenger после обновления не остался online через Long Poll: ${JSON.stringify(transport)}`
    );
  }
  if (transport.dotColor !== 'rgb(22, 135, 255)') {
    throw new Error(
      `Long Poll использует неожиданный цвет маркера после обновления: ${transport.dotColor}`
    );
  }

  const adminResponse = await page.goto(`${baseUrl}/admin/updates/`, {
    waitUntil: 'domcontentloaded',
  });
  if (!adminResponse || adminResponse.status() !== 200) {
    throw new Error(`Admin Updates после обновления вернул HTTP ${adminResponse?.status()}`);
  }

  const installedCard = page.locator('.admin-status-card')
    .filter({ hasText: 'Установленная версия' })
    .first();
  await installedCard.getByText(expectedVersion, { exact: false })
    .waitFor({ state: 'visible', timeout: 10000 });
  await installedCard.getByText(expectedVersionCode, { exact: false })
    .waitFor({ state: 'visible', timeout: 10000 });

  if (pageErrors.length) throw pageErrors[0];
  if (serverErrors.length) {
    throw new Error(`После обновления обнаружены HTTP 5xx: ${serverErrors.join(', ')}`);
  }

  console.log(
    `Post-upgrade smoke: OK; Profile=styled; Messenger=Long Poll online; version=${expectedVersion}`
  );
  await context.close();
} finally {
  await browser.close();
}
