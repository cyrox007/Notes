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
const browserErrors = [];
const escapedRequests = [];
const unexpectedHttpErrors = [];

function instrumentPage(page, { allow404 = () => false } = {}) {
  page.on('pageerror', (error) => browserErrors.push(error));
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.origin === origin && url.pathname !== basePath && !url.pathname.startsWith(basePath + '/')) {
      escapedRequests.push(url.pathname);
    }
  });
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin !== origin || response.status() < 400 || allow404(url, response)) return;
    unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
  });
}

async function login(page) {
  const response = await page.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`Login page returned ${response?.status()}`);
  }
  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);
  await page.locator('#main-content').waitFor({ state: 'visible', timeout: 15000 });
}

async function assertEditPage(page, stage) {
  await page.waitForLoadState('domcontentloaded');
  const editor = page.locator('.note__edit');
  if (await editor.count()) return;

  const url = page.url();
  const title = await page.title().catch(() => '');
  const body = (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').trim().slice(0, 1800);
  throw new Error(`${stage}: expected note editor at ${url}; title=${JSON.stringify(title)}; body=${JSON.stringify(body)}`);
}

async function acceptNextDialog(page, expectedText) {
  return new Promise((resolve, reject) => {
    page.once('dialog', async (dialog) => {
      try {
        if (expectedText && !dialog.message().includes(expectedText)) {
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
  const ownerContext = await browser.newContext();
  const ownerPage = await ownerContext.newPage();
  instrumentPage(ownerPage);
  await login(ownerPage);

  const stamp = Date.now();
  const originalName = `Notes lifecycle ${stamp}`;
  const updatedName = `${originalName} updated`;
  const updatedContent = `Browser lifecycle content ${stamp}`;
  const attachmentName = `lifecycle-${stamp}.txt`;
  const attachmentContent = `Notes lifecycle attachment ${stamp}\n`;

  await ownerPage.goto(`${baseUrl}/notes/`, { waitUntil: 'domcontentloaded' });
  await ownerPage.locator('.notes__create input[name="notename"]').fill(originalName);
  await Promise.all([
    ownerPage.waitForURL((url) => /\/notes\/[\w-]+\/edit\/?$/.test(url.pathname), { timeout: 15000 }),
    ownerPage.locator('.notes__create button[type="submit"]').click(),
  ]);

  const editUrl = ownerPage.url();
  if (!new URL(editUrl).pathname.startsWith(`${basePath}/notes/`)) {
    throw new Error(`Create redirect escaped BASE_PATH: ${editUrl}`);
  }
  await assertEditPage(ownerPage, 'after create');

  await ownerPage.locator('.note__edit input[name="notename"]').fill(updatedName);
  await ownerPage.locator('.note__edit textarea[name="content"]').fill(updatedContent);
  await Promise.all([
    ownerPage.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/notes`, { timeout: 15000 }),
    ownerPage.getByRole('button', { name: 'Сохранить текст' }).click(),
  ]);

  const noteRow = ownerPage.locator('.notes__list_item').filter({ hasText: updatedName });
  await noteRow.waitFor({ state: 'visible', timeout: 15000 });
  await Promise.all([
    ownerPage.waitForURL((url) => /\/notes\/[\w-]+\/edit\/?$/.test(url.pathname), { timeout: 15000 }),
    noteRow.locator('.notes__btn--edit').click(),
  ]);
  await assertEditPage(ownerPage, 'after reopen');
  if ((await ownerPage.locator('.note__edit textarea[name="content"]').inputValue()) !== updatedContent) {
    throw new Error('Updated note content did not round-trip through encrypted storage');
  }

  await ownerPage.locator('#attachmentInput').setInputFiles({
    name: attachmentName,
    mimeType: 'text/plain',
    buffer: Buffer.from(attachmentContent, 'utf8'),
  });
  await ownerPage.locator('#uploadForm button[type="submit"]').click();
  const attachment = ownerPage.locator('.attachment-item').filter({ hasText: attachmentName });
  await attachment.waitFor({ state: 'visible', timeout: 15000 });

  const privateAttachmentHref = await attachment.locator('a').getAttribute('href');
  if (!privateAttachmentHref || !privateAttachmentHref.startsWith(`${basePath}/notes/attachment/`)) {
    throw new Error(`Private attachment URL is not BASE_PATH-aware: ${privateAttachmentHref}`);
  }
  const privateDownload = await ownerContext.request.get(origin + privateAttachmentHref);
  if (privateDownload.status() !== 200 || (await privateDownload.text()) !== attachmentContent) {
    throw new Error(`Authenticated attachment download failed: HTTP ${privateDownload.status()}`);
  }

  const shareDialogPromise = acceptNextDialog(ownerPage, 'Ссылка создана:');
  await ownerPage.locator('#shareForm button[type="submit"]').click();
  const shareDialog = await shareDialogPromise;
  await ownerPage.locator('#shareUrl').waitFor({ state: 'visible', timeout: 15000 });
  const shareValue = await ownerPage.locator('#shareUrl').inputValue();
  const resolvedShare = new URL(shareValue, ownerPage.url());
  const shareUrl = resolvedShare.href;
  if (resolvedShare.origin !== origin || !resolvedShare.pathname.startsWith(`${basePath}/notes/shared/`)) {
    throw new Error(`Share URL is not BASE_PATH-aware: ${shareUrl}\nDialog: ${shareDialog}`);
  }

  if (shareDialog !== `Ссылка создана: ${shareUrl}`) {
    throw new Error('Share API URL and rendered public link disagree');
  }

  const publicContext = await browser.newContext();
  const publicPage = await publicContext.newPage();
  let sharedLinkRevoked = false;
  instrumentPage(publicPage, {
    allow404: (url, response) => sharedLinkRevoked && url.href === shareUrl && response.status() === 404,
  });
  const publicResponse = await publicPage.goto(shareUrl, { waitUntil: 'domcontentloaded' });
  if (!publicResponse || publicResponse.status() !== 200) {
    throw new Error(`Public share returned HTTP ${publicResponse?.status()}`);
  }
  await publicPage.getByRole('heading', { name: updatedName }).waitFor({ state: 'visible', timeout: 15000 });
  await publicPage.getByText(updatedContent, { exact: true }).waitFor({ state: 'visible', timeout: 15000 });

  const publicAttachment = publicPage.locator('.attachment-item').filter({ hasText: attachmentName }).locator('a');
  const publicAttachmentHref = await publicAttachment.getAttribute('href');
  if (!publicAttachmentHref || !publicAttachmentHref.startsWith(`${basePath}/notes/shared/`)) {
    throw new Error(`Public attachment URL is not BASE_PATH-aware: ${publicAttachmentHref}`);
  }
  const publicDownload = await publicContext.request.get(origin + publicAttachmentHref);
  if (publicDownload.status() !== 200 || (await publicDownload.text()) !== attachmentContent) {
    throw new Error(`Public attachment download failed: HTTP ${publicDownload.status()}`);
  }

  const unshareDialogPromise = acceptNextDialog(ownerPage, 'Деактивировать публичную ссылку?');
  await ownerPage.locator('#unshareNote').click();
  await unshareDialogPromise;
  await ownerPage.locator('#shareForm').waitFor({ state: 'visible', timeout: 15000 });

  sharedLinkRevoked = true;
  const revokedResponse = await publicPage.goto(shareUrl, { waitUntil: 'domcontentloaded' });
  if (!revokedResponse || revokedResponse.status() !== 404) {
    throw new Error(`Revoked share must return 404, got ${revokedResponse?.status()}`);
  }
  await publicPage.getByText('Заметка не найдена, ссылка отключена или срок её действия истёк', { exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });

  await ownerPage.goto(`${baseUrl}/notes/`, { waitUntil: 'domcontentloaded' });
  const deleteRow = ownerPage.locator('.notes__list_item').filter({ hasText: updatedName });
  await deleteRow.waitFor({ state: 'visible', timeout: 10000 });
  const deleteDialogPromise = acceptNextDialog(ownerPage, 'Вы уверены, что хотите удалить эту заметку?');
  await deleteRow.locator('.notes__btn--delete').click();
  await deleteDialogPromise;
  await ownerPage.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/notes`, { timeout: 15000 });
  if (await ownerPage.locator('.notes__list_item').filter({ hasText: updatedName }).count()) {
    throw new Error('Deleted note is still visible in the personal Notes list');
  }

  if (browserErrors.length) throw browserErrors[0];
  if (escapedRequests.length) {
    throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  }
  if (unexpectedHttpErrors.length) {
    throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log('Notes create/edit/attachment/share/unshare/delete browser lifecycle: OK');

  await publicContext.close();
  await ownerContext.close();
} finally {
  await browser.close();
}
