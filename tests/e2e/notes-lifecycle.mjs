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

  const saveEditorRoot = ownerPage.locator('[data-note-editor-013]');
  const saveSentinel = `save-ajax-${stamp}`;
  await saveEditorRoot.evaluate((element, value) => { element.dataset.ajaxSentinel = value; }, saveSentinel);
  const updateAction = await ownerPage.locator('.note__edit').getAttribute('action');
  if (!updateAction) throw new Error('У формы редактирования отсутствует маршрут сохранения');
  const updatePath = new URL(updateAction, editUrl).pathname;

  await ownerPage.locator('.note__edit input[name="notename"]').fill(updatedName);
  await ownerPage.locator('.note__edit textarea[name="content"]').fill(updatedContent);
  const saveResponsePromise = ownerPage.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.origin === origin && url.pathname === updatePath && response.request().method() === 'POST';
  }, { timeout: 15000 });
  await ownerPage.getByRole('button', { name: 'Сохранить текст' }).click();
  const saveResponse = await saveResponsePromise;
  if (saveResponse.status() !== 200) {
    throw new Error(`AJAX-сохранение заметки завершилось с HTTP ${saveResponse.status()}`);
  }
  const savePayload = await saveResponse.json();
  if (!savePayload?.success || savePayload?.note?.notename !== updatedName) {
    throw new Error(`Сервер вернул некорректный JSON после сохранения: ${JSON.stringify(savePayload)}`);
  }
  await ownerPage.getByRole('heading', { name: updatedName, exact: true }).waitFor({ state: 'visible', timeout: 10000 });
  await ownerPage.getByText('Все изменения сохранены на сервере', { exact: true }).waitFor({ state: 'visible', timeout: 10000 });
  if ((await saveEditorRoot.getAttribute('data-ajax-sentinel')) !== saveSentinel) {
    throw new Error('Сохранение текста вызвало полную перезагрузку редактора');
  }
  if (ownerPage.url() !== editUrl) {
    throw new Error(`AJAX-сохранение изменило URL редактора: ${ownerPage.url()}`);
  }

  await ownerPage.reload({ waitUntil: 'domcontentloaded' });
  await assertEditPage(ownerPage, 'after AJAX save reload');
  if ((await ownerPage.locator('.note__edit input[name="notename"]').inputValue()) !== updatedName) {
    throw new Error('Updated note name did not round-trip through storage');
  }
  if ((await ownerPage.locator('.note__edit textarea[name="content"]').inputValue()) !== updatedContent) {
    throw new Error('Updated note content did not round-trip through encrypted storage');
  }

  const ajaxSentinel = `ajax-${stamp}`;
  const editorRoot = ownerPage.locator('[data-note-editor-013]');
  await editorRoot.evaluate((element, value) => { element.dataset.ajaxSentinel = value; }, ajaxSentinel);
  const assertEditorStayedMounted = async (stage) => {
    const value = await editorRoot.getAttribute('data-ajax-sentinel');
    if (value !== ajaxSentinel) throw new Error(`${stage}: редактор был полностью перезагружен`);
    if (ownerPage.url() !== editUrl) throw new Error(`${stage}: изменился URL редактора`);
  };

  await ownerPage.locator('#attachmentInput').setInputFiles({
    name: attachmentName,
    mimeType: 'text/plain',
    buffer: Buffer.from(attachmentContent, 'utf8'),
  });
  await ownerPage.locator('#uploadForm button[type="submit"]').click();
  const attachment = ownerPage.locator('.attachment-item').filter({ hasText: attachmentName });
  await attachment.waitFor({ state: 'visible', timeout: 15000 });
  await assertEditorStayedMounted('после загрузки вложения');

  const privateAttachmentHref = await attachment.locator('a').getAttribute('href');
  if (!privateAttachmentHref || !privateAttachmentHref.startsWith(`${basePath}/notes/attachment/`)) {
    throw new Error(`Private attachment URL is not BASE_PATH-aware: ${privateAttachmentHref}`);
  }
  const privateDownload = await ownerContext.request.get(origin + privateAttachmentHref);
  if (privateDownload.status() !== 200 || (await privateDownload.text()) !== attachmentContent) {
    throw new Error(`Authenticated attachment download failed: HTTP ${privateDownload.status()}`);
  }

  await ownerPage.locator('#shareForm button[type="submit"]').click();
  await ownerPage.locator('#shareUrl').waitFor({ state: 'visible', timeout: 15000 });
  await assertEditorStayedMounted('после создания публичной ссылки');
  const shareValue = await ownerPage.locator('#shareUrl').inputValue();
  const resolvedShare = new URL(shareValue, ownerPage.url());
  const shareUrl = resolvedShare.href;
  if (resolvedShare.origin !== origin || !resolvedShare.pathname.startsWith(`${basePath}/notes/shared/`)) {
    throw new Error(`Share URL is not BASE_PATH-aware: ${shareUrl}`);
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

  await ownerPage.evaluate(() => {
    if (window.wspace?.feedback) window.wspace.feedback.confirm = async () => true;
  });
  await ownerPage.locator('#unshareNote').click();
  await ownerPage.locator('#shareForm').waitFor({ state: 'visible', timeout: 15000 });
  await assertEditorStayedMounted('после отключения публичной ссылки');

  sharedLinkRevoked = true;
  const revokedResponse = await publicPage.goto(shareUrl, { waitUntil: 'domcontentloaded' });
  if (!revokedResponse || revokedResponse.status() !== 404) {
    throw new Error(`Revoked share must return 404, got ${revokedResponse?.status()}`);
  }
  await publicPage.getByText('Заметка не найдена, ссылка отключена или срок её действия истёк', { exact: true })
    .waitFor({ state: 'visible', timeout: 10000 });

  await ownerPage.goto(`${baseUrl}/notes/`, { waitUntil: 'domcontentloaded' });
  const listUrl = ownerPage.url();
  const notesRoot = ownerPage.locator('.notes');
  const listSentinel = `list-ajax-${stamp}`;
  await notesRoot.evaluate((element, value) => { element.dataset.ajaxSentinel = value; }, listSentinel);

  const deleteRow = ownerPage.locator('.notes__list_item').filter({ hasText: updatedName });
  await deleteRow.waitFor({ state: 'visible', timeout: 10000 });
  const deleteAction = await deleteRow.locator('.notes__delete-form').getAttribute('action');
  if (!deleteAction) throw new Error('У формы удаления отсутствует маршрут');
  const deletePath = new URL(deleteAction, listUrl).pathname;
  const deleteResponsePromise = ownerPage.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.origin === origin && url.pathname === deletePath && response.request().method() === 'POST';
  }, { timeout: 15000 });
  const deleteDialogPromise = acceptNextDialog(ownerPage, 'Вы уверены, что хотите удалить эту заметку?');
  await deleteRow.locator('.notes__btn--delete').click();
  await deleteDialogPromise;
  const deleteResponse = await deleteResponsePromise;
  if (deleteResponse.status() >= 400) {
    throw new Error(`AJAX-удаление заметки завершилось с HTTP ${deleteResponse.status()}`);
  }
  await deleteRow.waitFor({ state: 'detached', timeout: 15000 });

  if ((await notesRoot.getAttribute('data-ajax-sentinel')) !== listSentinel) {
    throw new Error('Удаление заметки вызвало полную перезагрузку списка Notes');
  }
  if (ownerPage.url() !== listUrl) {
    throw new Error(`AJAX-удаление изменило URL списка Notes: ${ownerPage.url()}`);
  }
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

  console.log('Жизненный цикл Notes и AJAX-действия редактора и списка: OK');

  await publicContext.close();
  await ownerContext.close();
} finally {
  await browser.close();
}
