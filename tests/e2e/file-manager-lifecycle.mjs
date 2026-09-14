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
let quotaFailureExpected = false;

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
    if (quotaFailureExpected && response.status() === 413 && url.pathname === `${basePath}/files/upload/`) return;
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

async function waitForNavigation(page, action) {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await action();
  await navigation;
}

async function acceptDialog(page, expectedText) {
  return new Promise((resolve, reject) => {
    page.once('dialog', async (dialog) => {
      try {
        if (expectedText && !dialog.message().includes(expectedText)) throw new Error(`Unexpected dialog: ${dialog.message()}`);
        const message = dialog.message();
        await dialog.accept();
        resolve(message);
      } catch (error) { reject(error); }
    });
  });
}

async function confirmWorkspaceDialog(page, expectedText) {
  const dialog = page.locator('.wspace-dialog');
  await dialog.waitFor({ state: 'visible', timeout: 5000 });
  if (expectedText) {
    const text = await dialog.locator('.wspace-dialog__message').textContent();
    if (!String(text || '').includes(expectedText)) throw new Error(`Unexpected workspace dialog: ${text}`);
  }
  await dialog.getByRole('button', { name: 'Удалить', exact: true }).click();
  await dialog.waitFor({ state: 'detached', timeout: 5000 });
}

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  const filesResponse = await page.goto(`${baseUrl}/files/`, { waitUntil: 'domcontentloaded' });
  if (!filesResponse || filesResponse.status() !== 200) throw new Error(`File Manager returned ${filesResponse?.status()}`);
  await page.locator('#file-manager-quota [data-quota-status]').waitFor({ state: 'visible', timeout: 10000 });
  await page.waitForFunction(() => {
    const node = document.querySelector('#file-manager-quota [data-quota-status]');
    return node && !node.textContent.includes('Загрузка данных');
  }, null, { timeout: 10000 });

  const stamp = Date.now();
  const folderName = `FM lifecycle ${stamp}`;
  const fileStem = `fm-lifecycle-${stamp}`;
  const fileName = `${fileStem}.txt`;
  const renamedStem = `fm-renamed-${stamp}`;
  const overflowStem = `fm-overflow-${stamp}`;
  const overflowName = `${overflowStem}.txt`;
  const fileContent = `File Manager browser lifecycle ${stamp}\n` + 'A'.repeat(1024 - 40);
  const fileBuffer = Buffer.from(fileContent, 'utf8');
  if (fileBuffer.length > 2048) throw new Error('Success fixture unexpectedly large');

  await page.locator('#btn-create-folder').click();
  await page.locator('#modal-create-folder').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#folder-name-input').fill(folderName);
  await waitForNavigation(page, () => page.locator('#modal-create-folder .modal-ok').click());

  let folderItem = page.locator('.file-manager__item[data-type="folder"]').filter({ hasText: folderName });
  await folderItem.waitFor({ state: 'visible', timeout: 10000 });
  const folderId = await folderItem.getAttribute('data-id');
  if (!folderId) throw new Error('Created folder has no data-id');

  const folderHref = await folderItem.locator('a[title="Открыть"]').getAttribute('href');
  if (!folderHref || !folderHref.startsWith(`${basePath}/files/folder/`)) throw new Error(`Folder URL escaped BASE_PATH: ${folderHref}`);
  await Promise.all([
    page.waitForURL((url) => url.pathname === folderHref, { timeout: 15000 }),
    folderItem.locator('a[title="Открыть"]').click(),
  ]);

  const fileInput = page.locator('#file-input');
  const uploadNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await fileInput.setInputFiles({ name: fileName, mimeType: 'text/plain', buffer: fileBuffer });
  await uploadNavigation;

  let fileItem = page.locator('.file-manager__item').filter({ has: page.locator(`.file-manager__item-name:text-is("${fileName}")`) });
  await fileItem.waitFor({ state: 'visible', timeout: 10000 });
  const fileId = await fileItem.getAttribute('data-id');
  if (!fileId) throw new Error('Uploaded file has no data-id');

  await fileItem.dblclick();
  await page.locator('#text-preview-modal').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#text-preview-content').filter({ hasText: `File Manager browser lifecycle ${stamp}` }).waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#text-preview-modal .file-manager__modal-close').click();

  let downloadHref = await fileItem.locator('a[title="Открыть"]').getAttribute('href');
  if (!downloadHref || !downloadHref.startsWith(`${basePath}/files/get/`)) throw new Error(`Protected file URL escaped BASE_PATH: ${downloadHref}`);
  let download = await context.request.get(origin + downloadHref);
  if (download.status() !== 200 || (await download.text()) !== fileContent) throw new Error(`Authenticated download failed: HTTP ${download.status()}`);
  if (!String(download.headers()['content-disposition'] || '').includes(fileName)) throw new Error(`Unexpected download filename: ${download.headers()['content-disposition']}`);

  // Rename is now inline: no full-page reload is required.
  const renameUrl = page.url();
  await fileItem.locator('.btn-rename').click();
  await page.locator('#modal-rename').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#rename-input').fill(renamedStem);
  await page.locator('#modal-rename .modal-ok').click();
  fileItem = page.locator(`.file-manager__item[data-id="${fileId}"]`);
  await fileItem.locator('.file-manager__item-name').filter({ hasText: `${renamedStem}.txt` }).waitFor({ state: 'visible', timeout: 10000 });
  if (page.url() !== renameUrl) throw new Error('File rename unexpectedly navigated the page');
  downloadHref = await fileItem.locator('a[title="Открыть"]').getAttribute('href');
  download = await context.request.get(origin + downloadHref);
  if (download.status() !== 200 || (await download.text()) !== fileContent) throw new Error(`Renamed file download failed: HTTP ${download.status()}`);
  if (!String(download.headers()['content-disposition'] || '').includes(`${renamedStem}.txt`)) throw new Error(`Renamed download header is stale: ${download.headers()['content-disposition']}`);

  quotaFailureExpected = true;
  const quotaDialogPromise = acceptDialog(page, 'Недостаточно места в персональном хранилище');
  await page.locator('#file-input').setInputFiles({ name: overflowName, mimeType: 'text/plain', buffer: Buffer.alloc(4096, 66) });
  await quotaDialogPromise;
  quotaFailureExpected = false;
  if (await page.locator('.file-manager__item').filter({ hasText: overflowName }).count()) throw new Error('Quota-rejected file appeared in File Manager');

  // Shared confirmation handles delete; an empty folder still reloads to render its empty state.
  fileItem = page.locator(`.file-manager__item[data-id="${fileId}"]`);
  await fileItem.locator('.btn-delete').click();
  const deleteFileNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await confirmWorkspaceDialog(page, `Удалить «${renamedStem}»?`);
  await deleteFileNavigation;
  if (await page.locator(`.file-manager__item[data-id="${fileId}"]`).count()) throw new Error('Deleted file is still visible');

  const rootHref = await page.locator('.file-manager__breadcrumb-item').first().getAttribute('href');
  if (!rootHref || rootHref.replace(/\/+$/, '') !== `${basePath}/files`) throw new Error(`Root breadcrumb escaped BASE_PATH: ${rootHref}`);
  await page.goto(origin + rootHref, { waitUntil: 'domcontentloaded' });
  folderItem = page.locator(`.file-manager__item[data-id="${folderId}"]`);
  await folderItem.waitFor({ state: 'visible', timeout: 10000 });
  await folderItem.locator('.btn-delete').click();
  await confirmWorkspaceDialog(page, `Удалить «${folderName}»?`);
  await folderItem.waitFor({ state: 'detached', timeout: 10000 });

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  if (unexpectedHttpErrors.length) throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);

  console.log(`File Manager lifecycle browser flow: OK (folder=${folderId}, file=${fileId})`);
  await context.close();
} finally {
  await browser.close();
}
