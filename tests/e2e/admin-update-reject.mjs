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

  const result = await page.evaluate(async () => {
    const csrf = String(window.wspace?.security?.getCSRFToken?.() || '');
    const response = await fetch('/admin/updates/web-start-latest', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
    });
    const text = await response.text();
    let payload = null;
    try {
      payload = JSON.parse(text);
    } catch (_) {
      // Сырой ответ используется в диагностике ниже.
    }
    return {
      status: response.status,
      ok: response.ok,
      text,
      payload,
    };
  });

  if (result.ok || result.payload?.success === true) {
    throw new Error(
      'Updater принял пакет с повреждённой подписью: '
      + `HTTP=${result.status}; body=${result.text}`
    );
  }

  const errorCode = String(result.payload?.error || '');
  const message = String(result.payload?.message || '');
  if (!errorCode && !message) {
    throw new Error(
      `Отказ проверки подписи не вернул безопасную диагностику: HTTP=${result.status}; body=${result.text}`
    );
  }

  console.log(
    `Пакет с повреждённой подписью отклонён до updater-транзакции: HTTP=${result.status}; error=${errorCode || 'safe_failure'}`
  );
  await context.close();
} finally {
  await browser.close();
}
