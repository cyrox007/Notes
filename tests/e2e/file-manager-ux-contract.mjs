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

async function confirmDelete(page) {
  const dialog = page.locator('.wspace-dialog');
  await dialog.waitFor({ state: 'visible', timeout: 5000 });
  await dialog.getByRole('button', { name: 'Удалить', exact: true }).click();
  await dialog.waitFor({ state: 'detached', timeout: 5000 });
}

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
  await page.locator('.file-manager-dropzone').waitFor({ state: 'attached', timeout: 5000 });

  await page.getByRole('button', { name: 'Список' }).click();
  if (await page.locator('.file-manager').getAttribute('data-view') !== 'list') throw new Error('List view was not applied');
  if (await page.evaluate(() => localStorage.getItem('wspace:file-manager:view')) !== 'list') throw new Error('List view preference was not persisted');

  // Exercise the browser's real drag/drop surface. The bridge transfers this FileList
  // into the existing hidden file input, so the hardened upload handler/endpoint remains
  // the only upload implementation.
  const stamp = Date.now();
  const droppedName = `drop-contract-${stamp}.txt`;
  await page.evaluate((name) => {
    const root = document.querySelector('.file-manager');
    if (!root) throw new Error('File Manager root is missing');
    const transfer = new DataTransfer();
    transfer.items.add(new File(['drag-drop-contract'], name, { type: 'text/plain' }));
    window.__fileManagerDropTransfer = transfer;
    root.dispatchEvent(new DragEvent('dragenter', {
      bubbles: true,
      cancelable: true,
      dataTransfer: transfer,
    }));
  }, droppedName);
  await page.locator('.file-manager-dropzone').waitFor({ state: 'visible', timeout: 5000 });
  await page.getByText('Перетащите файлы сюда', { exact: true }).waitFor({ state: 'visible' });

  const dropNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.evaluate(() => {
    const root = document.querySelector('.file-manager');
    const transfer = window.__fileManagerDropTransfer;
    if (!root || !transfer) throw new Error('Drag/drop fixture was lost');
    root.dispatchEvent(new DragEvent('dragover', {
      bubbles: true,
      cancelable: true,
      dataTransfer: transfer,
    }));
    root.dispatchEvent(new DragEvent('drop', {
      bubbles: true,
      cancelable: true,
      dataTransfer: transfer,
    }));
  });
  await dropNavigation;

  const droppedItem = page.locator('.file-manager__item').filter({
    has: page.locator(`.file-manager__item-name:text-is("${droppedName}")`),
  });
  await droppedItem.waitFor({ state: 'visible', timeout: 10000 });
  if (await page.locator('.file-manager-dropzone').isVisible()) throw new Error('Dropzone remained visible after upload');

  // Clean up before the existing durable lifecycle runs: the workflow intentionally
  // leaves only 4 KiB free, and its final storage sum must remain unchanged.
  await droppedItem.locator('.btn-delete').click();
  await confirmDelete(page);
  await droppedItem.waitFor({ state: 'detached', timeout: 10000 });

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
  await confirmDelete(page);
  await item.waitFor({ state: 'detached', timeout: 10000 });

  console.log('File Manager 0.13 search/sort/view/drop-upload contract: OK');
} finally {
  await context.close();
  await browser.close();
}
