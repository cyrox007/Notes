import { chromium } from 'playwright';

const origin = process.env.E2E_ORIGIN || 'http://127.0.0.1:18088';
const basePath = (process.env.E2E_BASE_PATH || '/workspace').replace(/\/$/, '');
const username = process.env.E2E_USER || 'base-path-user';
const password = process.env.E2E_PASSWORD || 'BasePathPassword123!';
const expectedOfflineWebSocket = `ws://127.0.0.1:27801${basePath}/ws`;

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const consoleErrors = [];
const failedResponses = [];
const escapedRequests = [];

page.on('pageerror', error => pageErrors.push(error));
page.on('console', message => {
  if (message.type() !== 'error') return;

  const text = message.text();
  // This gate validates HTTP rendering and BASE_PATH behaviour only. Realtime
  // transport is intentionally owned by the dedicated WSS browser workflow, so
  // the local WSS endpoint is not started here. Ignore only that exact expected
  // connection-refused diagnostic; every other browser console error remains fatal.
  if (
    text.includes('WebSocket connection to')
    && text.includes(expectedOfflineWebSocket)
    && text.includes('ERR_CONNECTION_REFUSED')
  ) {
    return;
  }

  consoleErrors.push(text);
});
page.on('response', response => {
  if (response.status() >= 400) {
    failedResponses.push(`${response.status()} ${new URL(response.url()).pathname}`);
  }
});
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

async function quotaDiagnostics() {
  return page.evaluate(async () => {
    const root = document.querySelector('#file-manager-quota');
    const status = document.querySelector('[data-quota-status]')?.textContent || '';
    const endpoint = root?.dataset.url || '';
    const scripts = Array.from(document.scripts)
      .map(script => script.src)
      .filter(src => src.includes('file_manager'));
    let endpointStatus = null;
    let endpointBody = '';
    let endpointError = '';
    try {
      const response = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      endpointStatus = response.status;
      endpointBody = await response.text();
    } catch (error) {
      endpointError = String(error);
    }
    return { status, endpoint, scripts, endpointStatus, endpointBody, endpointError };
  });
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
  await page.locator('#workspaceSidebar').waitFor({ state: 'attached' });
  await page.locator('.sidebar__brand').waitFor({ state: 'visible' });

  const homeHref = await page.locator('.sidebar__brand').getAttribute('href');
  if (homeHref !== `${basePath}/`) {
    throw new Error(`Sidebar home link escaped BASE_PATH: ${homeHref}`);
  }

  const fontAwesomeHref = await page.locator('link[href*="font-awesome.min.css"]').getAttribute('href');
  if (!fontAwesomeHref?.includes(`${basePath}/assets/font-awesome/css/font-awesome.min.css`)) {
    throw new Error(`Shell asset escaped BASE_PATH: ${fontAwesomeHref}`);
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

  const quotaScriptSrc = await page
    .locator('script[src*="/module-assets"][src*="module=files"][src*="file=quota.js"]')
    .getAttribute('src');
  if (
    !quotaScriptSrc?.includes(`${basePath}/module-assets`)
    || !quotaScriptSrc.includes('module=files')
    || !quotaScriptSrc.includes('file=quota.js')
  ) {
    throw new Error(`Quota module asset escaped BASE_PATH: ${quotaScriptSrc}`);
  }

  try {
    await page.waitForFunction(() => {
      const node = document.querySelector('[data-quota-status]');
      return node && !node.textContent.includes('Загрузка данных');
    }, null, { timeout: 15000 });
  } catch (error) {
    const diagnostics = await quotaDiagnostics();
    throw new Error(
      `Quota UI did not settle. diagnostics=${JSON.stringify(diagnostics)} `
      + `consoleErrors=${JSON.stringify(consoleErrors)} failedResponses=${JSON.stringify(failedResponses)}`,
      { cause: error }
    );
  }

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

  await fileItem.locator('.btn-delete').click();
  const deleteDialog = page.locator('.wspace-dialog-backdrop:not([hidden])');
  await deleteDialog.waitFor({ state: 'visible', timeout: 5000 });
  await deleteDialog.getByRole('button', { name: 'Удалить', exact: true }).click();
  await fileItem.waitFor({ state: 'detached', timeout: 15000 });

  // Messenger must at least render cleanly under the prefix. A separate HTTPS/WSS
  // workflow owns realtime transport validation.
  await assertPage('/messenger/', '#messenger-app');

  if (pageErrors.length > 0) {
    throw pageErrors[0];
  }
  if (consoleErrors.length > 0) {
    throw new Error(`Browser console errors:\n${consoleErrors.join('\n')}`);
  }
  if (failedResponses.length > 0) {
    throw new Error(`HTTP failures observed:\n${failedResponses.join('\n')}`);
  }
  if (escapedRequests.length > 0) {
    throw new Error(`Requests escaped BASE_PATH:\n${escapedRequests.join('\n')}`);
  }

  console.log('BASE_PATH browser flow: login + modules + quota + File Manager mutations OK');
} finally {
  await context.close();
  await browser.close();
}
