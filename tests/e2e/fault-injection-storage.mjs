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
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const escapedRequests = [];
const unexpectedHttpErrors = [];
let expectedUploadFailure = false;

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
    if (url.origin !== origin || response.status() < 400) return;
    if (expectedUploadFailure && response.status() === 500 && url.pathname === `${basePath}/files/upload/`) return;
    unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
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

function waitForDialog(page, expectedText) {
  return new Promise((resolve, reject) => {
    page.once('dialog', async (dialog) => {
      try {
        if (!dialog.message().includes(expectedText)) {
          throw new Error(`Unexpected dialog: ${dialog.message()}`);
        }
        const message = dialog.message();
        await dialog.accept();
        resolve(message);
      } catch (error) {
        reject(error);
      }
    });
  });
}

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  const response = await page.goto(`${baseUrl}/files/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error(`File Manager returned ${response?.status()}`);

  const stamp = Date.now();
  const fileName = `fault-injection-${stamp}.txt`;
  const content = `Forced metadata failure ${stamp}\n`;

  expectedUploadFailure = true;
  const dialogPromise = waitForDialog(page, 'Ошибка при загрузке файла');
  await page.locator('#file-input').setInputFiles({
    name: fileName,
    mimeType: 'text/plain',
    buffer: Buffer.from(content, 'utf8'),
  });
  await dialogPromise;
  expectedUploadFailure = false;

  // A failed durable metadata write must not be represented as a successful item.
  if (await page.locator('.file-manager__item').filter({ hasText: fileName }).count()) {
    throw new Error('DB-failed upload appeared as a successful File Manager item');
  }

  // The current page must remain usable after the failed XHR upload.
  await page.locator('#btn-upload-file').waitFor({ state: 'visible', timeout: 5000 });
  if (new URL(page.url()).pathname.replace(/\/+$/, '') !== `${basePath}/files`) {
    throw new Error(`Fault injection escaped File Manager route: ${page.url()}`);
  }

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) {
    throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  }
  if (unexpectedHttpErrors.length) {
    throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log(`Storage fault injection browser flow: OK (${fileName})`);
  await context.close();
} finally {
  await browser.close();
}
