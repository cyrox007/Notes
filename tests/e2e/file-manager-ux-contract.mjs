import { chromium } from 'playwright';

function env(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing ${name}`);
  return value;
}

const origin = env('E2E_ORIGIN');
const basePath = '/' + env('E2E_BASE_PATH').replace(/^\/+|\/+$/g, '');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();

try {
  await page.goto(`${origin}${basePath}/auth/login/`, { waitUntil: 'domcontentloaded' });
  await page.locator('#login').fill(env('E2E_USER'));
  await page.locator('#password').fill(env('E2E_PASSWORD'));
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);

  await page.goto(`${origin}${basePath}/files/`, { waitUntil: 'domcontentloaded' });
  await page.locator('#file-manager-search').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#file-manager-sort').waitFor({ state: 'visible' });

  await page.getByRole('button', { name: 'Список' }).click();
  if (await page.locator('.file-manager').getAttribute('data-view') !== 'list') throw new Error('List view was not applied');
  if (await page.evaluate(() => localStorage.getItem('wspace:file-manager:view')) !== 'list') throw new Error('List view preference was not persisted');

  const stamp = Date.now();
  const folderName = `UX search ${stamp}`;
  await page.locator('#btn-create-folder').click();
  await page.locator('#folder-name-input').fill(folderName);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    page.locator('#modal-create-folder .modal-ok').click(),
  ]);

  const item = page.locator('.file-manager__item[data-type="folder"]').filter({ hasText: folderName });
  await item.waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#file-manager-search').fill('no-such-folder');
  await item.waitFor({ state: 'hidden', timeout: 5000 });
  await page.locator('#file-manager-search').fill('UX search');
  await item.waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#file-manager-sort').selectOption('name-desc');

  await item.locator('.btn-delete').click();
  const dialog = page.locator('.wspace-dialog');
  await dialog.waitFor({ state: 'visible', timeout: 5000 });
  await dialog.getByRole('button', { name: 'Удалить', exact: true }).click();
  await item.waitFor({ state: 'detached', timeout: 10000 });

  console.log('File Manager 0.13 search/sort/view contract: OK');
} finally {
  await context.close();
  await browser.close();
}
