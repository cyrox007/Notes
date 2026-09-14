import { chromium } from 'playwright';

const origin = process.env.E2E_ORIGIN || 'http://127.0.0.1:18088';
const basePath = (process.env.E2E_BASE_PATH || '/workspace').replace(/\/$/, '');
const username = process.env.E2E_USER || 'base-path-user';
const password = process.env.E2E_PASSWORD || 'BasePathPassword123!';

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const escapedRequests = [];

page.on('pageerror', error => pageErrors.push(error));
page.on('request', request => {
  const url = new URL(request.url());
  if (url.origin !== origin) return;

  const appRoots = ['/assets/', '/files/', '/notes/', '/tasks/', '/profile/', '/messenger/', '/auth/'];
  if (appRoots.some(prefix => url.pathname.startsWith(prefix))) {
    escapedRequests.push(`${request.method()} ${url.pathname}`);
  }
});

const appUrl = (path = '/') => `${origin}${basePath}${path.startsWith('/') ? path : `/${path}`}`;

async function assertPage(path, selector) {
  const response = await page.goto(appUrl(path), { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`${path} returned ${response?.status()}`);
  }
  await page.locator(selector).waitFor({ state: 'visible', timeout: 15000 });
}

try {
  const loginResponse = await page.goto(appUrl('/auth/login'), { waitUntil: 'domcontentloaded' });
  if (!loginResponse || loginResponse.status() !== 200) {
    throw new Error(`Subdirectory login returned ${loginResponse?.status()}`);
  }

  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL(url => url.pathname === `${basePath}/` || url.pathname === basePath, { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);

  await page.locator('#main-content').waitFor({ state: 'visible' });

  const homeHref = await page.locator('.navbar__home').getAttribute('href');
  if (homeHref !== `${basePath}/`) {
    throw new Error(`Header home link escaped BASE_PATH: ${homeHref}`);
  }

  const sidebarAvatar = await page.locator('.sidebar__user-image img').getAttribute('src');
  if (!sidebarAvatar?.includes(`${basePath}/assets/img/default_avatar.png`)) {
    throw new Error(`Sidebar avatar escaped BASE_PATH: ${sidebarAvatar}`);
  }

  const helperResult = await page.evaluate(() => window.wspace?.path?.('/files/upload/'));
  if (helperResult !== `${basePath}/files/upload/`) {
    throw new Error(`wspace.path returned ${helperResult}`);
  }

  await assertPage('/notes/', '#main-content');
  await assertPage('/tasks/', '#main-content');
  await assertPage('/profile/', '.profile');

  const profileAvatar = await page.locator('.profile__card-avatar img').getAttribute('src');
  if (!profileAvatar?.includes(`${basePath}/assets/img/default_avatar.png`)) {
    throw new Error(`Profile avatar escaped BASE_PATH: ${profileAvatar}`);
  }

  await assertPage('/files/', '.file-manager');
  await page.locator('[data-quota-status]').waitFor({ state: 'visible' });
  await page.waitForFunction(() => {
    const node = document.querySelector('[data-quota-status]');
    return node && !node.textContent.includes('Загрузка данных');
  }, null, { timeout: 15000 });

  const quotaState = await page.locator('#file-manager-quota').getAttribute('class');
  if (!quotaState?.includes('file-manager__quota--ready')) {
    throw new Error(`Quota endpoint did not become ready: ${quotaState}`);
  }

  // Exercise a real JS mutation path. This proves fetch/XHR requests inherit the
  // application prefix rather than accidentally posting to the host root.
  await page.locator('#btn-create-folder').click();
  await page.locator('#folder-name-input').fill('Base Path Folder');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#modal-create-folder .modal-ok').click(),
  ]);
  await page.getByText('Base Path Folder', { exact: true }).waitFor({ timeout: 15000 });

  await page.locator('#file-input').setInputFiles({
    name: 'base-path.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('base path upload contract'),
  });
  await page.waitForLoadState('domcontentloaded');
  const fileItem = page.locator('.file-manager__item[data-name="base-path"]');
  await fileItem.waitFor({ state: 'visible', timeout: 15000 });

  const fileOpenHref = await fileItem.locator('.file-manager__action-btn').first().getAttribute('href');
  if (!fileOpenHref?.startsWith(`${basePath}/files/get/`)) {
    throw new Error(`File link escaped BASE_PATH: ${fileOpenHref}`);
  }

  page.once('dialog', dialog => dialog.accept());
  await fileItem.locator('.btn-delete').click();
  await fileItem.waitFor({ state: 'detached', timeout: 15000 });

  // Messenger must at least render cleanly under the prefix. A separate HTTPS/WSS
  // workflow owns realtime transport validation.
  await assertPage('/messenger/', '#messenger-app');

  if (pageErrors.length > 0) {
    throw pageErrors[0];
  }
  if (escapedRequests.length > 0) {
    throw new Error(`Requests escaped BASE_PATH:\n${escapedRequests.join('\n')}`);
  }

  console.log('BASE_PATH browser flow: login + modules + quota + File Manager mutations OK');
} finally {
  await context.close();
  await browser.close();
}
