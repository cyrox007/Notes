import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePathRaw = requiredEnv('E2E_BASE_PATH');
const username = requiredEnv('E2E_USER');
const password = requiredEnv('E2E_PASSWORD');
const dbName = process.env.E2E_DB_NAME || 'file_manager_browser_lifecycle';
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });

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

async function createFolder(page, name) {
  await page.locator('#btn-create-folder').click();
  await page.locator('#modal-create-folder').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#folder-name-input').fill(name);
  await waitForNavigation(page, () => page.locator('#modal-create-folder .modal-ok').click());
  const item = page.locator('.file-manager__item[data-type="folder"]').filter({ hasText: name });
  await item.waitFor({ state: 'visible', timeout: 10000 });
  return item;
}

async function createShare(page, item, mode = '0', custom = '') {
  await item.locator('.btn-share').click();
  const modal = page.locator('#modal-share');
  await modal.waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#share-expiry-mode').selectOption(mode);
  if (mode === 'custom') {
    await page.locator('#share-expiry-custom').fill(custom);
  }
  await page.locator('#share-create-button').click();
  await modal.waitFor({ state: 'hidden', timeout: 10000 });
  await page.locator('.file-manager-share-toast').waitFor({ state: 'visible', timeout: 5000 });
}

function expireShare(shareId) {
  if (!/^\d+$/.test(String(shareId))) throw new Error('Unsafe share id');
  execFileSync('mysql', [
    '-h', '127.0.0.1',
    '-uroot',
    '-proot',
    dbName,
    '-e', `UPDATE file_shares SET expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE), is_active=1 WHERE id=${shareId}`,
  ], { stdio: 'pipe' });
}

try {
  const ownerContext = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
  const page = await ownerContext.newPage();
  await login(page);
  await page.goto(`${baseUrl}/files/`, { waitUntil: 'domcontentloaded' });

  const stamp = Date.now();
  const folderName = `Shared folder ${stamp}`;
  const nestedName = `Nested ${stamp}`;
  const fileName = `shared-${stamp}.txt`;
  const fileText = `public share lifecycle ${stamp}`;

  let rootFolder = await createFolder(page, folderName);
  const rootFolderId = await rootFolder.getAttribute('data-id');
  if (!rootFolderId) throw new Error('Shared folder has no id');

  await Promise.all([
    page.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/files/folder/${rootFolderId}`, { timeout: 15000 }),
    rootFolder.click(),
  ]);

  const nestedFolder = await createFolder(page, nestedName);
  const nestedFolderId = await nestedFolder.getAttribute('data-id');
  if (!nestedFolderId) throw new Error('Nested folder has no id');

  await Promise.all([
    page.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/files/folder/${nestedFolderId}`, { timeout: 15000 }),
    nestedFolder.click(),
  ]);

  const uploadNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.locator('#file-input').setInputFiles({
    name: fileName,
    mimeType: 'text/plain',
    buffer: Buffer.from(fileText, 'utf8'),
  });
  await uploadNavigation;

  let fileItem = page.locator('.file-manager__item').filter({ hasText: fileName });
  await fileItem.waitFor({ state: 'visible', timeout: 10000 });

  // Возвращаемся к корню и публикуем всю папку одной ссылкой.
  await page.goto(`${baseUrl}/files/`, { waitUntil: 'domcontentloaded' });
  rootFolder = page.locator('.file-manager__item[data-type="folder"]').filter({ hasText: folderName });
  await createShare(page, rootFolder, '168');

  await page.goto(`${baseUrl}/files/shares/`, { waitUntil: 'domcontentloaded' });
  let shareRow = page.locator('.file-shares__table tbody tr').filter({ hasText: folderName }).first();
  await shareRow.waitFor({ state: 'visible', timeout: 10000 });
  await shareRow.getByText('Активна', { exact: true }).waitFor({ state: 'visible' });
  const publicHref = await shareRow.getByRole('link', { name: 'Открыть' }).getAttribute('href');
  if (!publicHref) throw new Error('Folder share has no public URL');

  const anonymous = await browser.newContext();
  const publicPage = await anonymous.newPage();
  let response = await publicPage.goto(new URL(publicHref, origin).href, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error(`Public folder returned ${response?.status()}`);
  await publicPage.getByRole('heading', { name: folderName }).waitFor({ state: 'visible' });
  await publicPage.getByRole('link', { name: new RegExp(nestedName) }).click();
  await publicPage.getByRole('link', { name: new RegExp(fileName) }).waitFor({ state: 'visible' });
  const nestedFileHref = await publicPage.getByRole('link', { name: new RegExp(fileName) }).getAttribute('href');
  const fileResponse = await anonymous.request.get(new URL(nestedFileHref, origin).href);
  if (fileResponse.status() !== 200 || (await fileResponse.text()) !== fileText) {
    throw new Error(`Public nested file failed: HTTP ${fileResponse.status()}`);
  }

  // Отзыв не удаляет папку, но старая ссылка перестаёт работать.
  page.once('dialog', (dialog) => dialog.accept());
  await waitForNavigation(page, () => shareRow.getByRole('button', { name: 'Отозвать' }).click());
  response = await anonymous.request.get(new URL(publicHref, origin).href);
  if (response.status() !== 404) throw new Error(`Revoked share still works: HTTP ${response.status()}`);

  // Создаём отдельную ссылку на файл с произвольным сроком и проверяем истечение.
  await page.goto(`${baseUrl}/files/folder/${nestedFolderId}/`, { waitUntil: 'domcontentloaded' });
  fileItem = page.locator('.file-manager__item').filter({ hasText: fileName });
  const customDate = new Date(Date.now() + 2 * 60 * 60 * 1000);
  const localDate = new Date(customDate.getTime() - customDate.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  await createShare(page, fileItem, 'custom', localDate);

  await page.goto(`${baseUrl}/files/shares/`, { waitUntil: 'domcontentloaded' });
  shareRow = page.locator('.file-shares__table tbody tr').filter({ hasText: fileName }).first();
  const action = await shareRow.locator('form[data-revoke-share]').getAttribute('action');
  const match = String(action || '').match(/\/shares\/(\d+)\/revoke/);
  if (!match) throw new Error(`Cannot resolve share id from ${action}`);
  const filePublicHref = await shareRow.getByRole('link', { name: 'Открыть' }).getAttribute('href');
  if (!filePublicHref) throw new Error('File share has no public URL');

  let activeFileResponse = await anonymous.request.get(new URL(filePublicHref, origin).href);
  if (activeFileResponse.status() !== 200) throw new Error(`Active file share failed: ${activeFileResponse.status()}`);

  expireShare(match[1]);
  const expiredResponse = await anonymous.request.get(new URL(filePublicHref, origin).href);
  if (expiredResponse.status() !== 404) throw new Error(`Expired share still works: HTTP ${expiredResponse.status()}`);

  await page.reload({ waitUntil: 'domcontentloaded' });
  shareRow = page.locator('.file-shares__table tbody tr').filter({ hasText: fileName }).first();
  await shareRow.getByText('Истекла', { exact: true }).waitFor({ state: 'visible', timeout: 5000 });

  // Исходные объекты после отзыва/истечения остаются на месте.
  await page.goto(`${baseUrl}/files/folder/${nestedFolderId}/`, { waitUntil: 'domcontentloaded' });
  await page.locator('.file-manager__item').filter({ hasText: fileName }).waitFor({ state: 'visible', timeout: 5000 });

  console.log('[OK] File Manager share lifecycle: файл, папка, вложенная навигация, отзыв и истечение');
  await anonymous.close();
  await ownerContext.close();
} finally {
  await browser.close();
}
