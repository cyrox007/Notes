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
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });

try {
  const context = await browser.newContext();
  const page = await context.newPage();

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

  const checkResponse = await page.goto(`${baseUrl}/admin/updates/check`, {
    waitUntil: 'domcontentloaded',
  });
  if (!checkResponse || checkResponse.status() !== 200) {
    throw new Error(`Проверка обновления завершилась неожиданным HTTP ${checkResponse?.status()}`);
  }

  const errorMessages = (await page.locator('.admin-page__flash--error[role="status"]').allTextContents())
    .map((value) => String(value || '').trim())
    .filter(Boolean);
  const diagnostic = errorMessages.find((value) => /подпис|signature|ключ/i.test(value)) || errorMessages[0] || '';
  if (!diagnostic) {
    throw new Error('Отказ проверки подписи не показал безопасную диагностику в интерфейсе');
  }

  if (!/подпис|signature|ключ/i.test(diagnostic)) {
    throw new Error(`Получена диагностика, не подтверждающая отказ подписи: ${diagnostic}`);
  }

  console.log(
    `Пакет с повреждённой подписью отклонён до updater-транзакции: ${diagnostic}`
  );
  await context.close();
} finally {
  await browser.close();
}
