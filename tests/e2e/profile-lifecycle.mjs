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
const avatarPath = requiredEnv('E2E_AVATAR_PATH');
const ownUid = requiredEnv('E2E_OWN_UID');
const otherUid = requiredEnv('E2E_OTHER_UID');
const otherEmail = requiredEnv('E2E_OTHER_EMAIL');
const otherPhone = requiredEnv('E2E_OTHER_PHONE');
const privateMarker = requiredEnv('E2E_OTHER_PRIVATE_MARKER');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const publicNote = 'PUBLIC-NOTE-013';
const privateNote = 'PRIVATE-NOTE-013';
const publicTask = 'PUBLIC-TASK-013';
const privateTask = 'PRIVATE-TASK-013';
const publicFile = 'public-file-013.txt';
const privateFile = 'private-file-013.txt';
const privateStorageMarker = 'PRIVATE-STORAGE-PATH-013';
const ownPublicationNote = 'PROFILE-OWN-PUBLISH-013';

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const escapedRequests = [];
const unexpectedHttpErrors = [];

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
    if (url.origin === origin && response.status() >= 400) {
      unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
    }
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

async function acceptDialog(page, expectedText) {
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
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  const response = await page.goto(`${baseUrl}/profile/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error(`Profile page returned ${response?.status()}`);

  for (const [label, suffix] of [
    ['Мои заметки', '/notes/'],
    ['Мои задачи', '/tasks/'],
    ['Мои файлы', '/files/'],
  ]) {
    const href = await page.getByRole('link', { name: new RegExp(label) }).getAttribute('href');
    if (!href) throw new Error(`${label} hub card has no href`);
    const url = new URL(href, origin);
    if (url.origin !== origin || !url.pathname.startsWith(basePath) || !url.pathname.endsWith(suffix)) {
      throw new Error(`${label} hub card escaped BASE_PATH: ${href}`);
    }
  }

  const previewHref = await page.getByRole('link', { name: 'Посмотреть как другой пользователь' }).getAttribute('href');
  if (!previewHref || !new URL(previewHref, origin).pathname.startsWith(`${basePath}/profile/user/`)) {
    throw new Error(`Public-profile preview link is invalid: ${previewHref}`);
  }

  // Explicit publication is owner-controlled and defaults to private. Locator.click()
  // already waits for a form-triggered navigation, so a second waitForNavigation on
  // the same-page redirect is both redundant and flaky. The visible state below plus
  // the durable DB assertion in the workflow prove that the mutation really happened.
  const ownPublishItem = page.locator('.profile-publication__item').filter({ hasText: ownPublicationNote });
  await ownPublishItem.waitFor({ state: 'visible', timeout: 5000 });
  await ownPublishItem.getByRole('button', { name: 'Опубликовать', exact: true }).click();
  await page.locator('.profile-publication__item').filter({ hasText: ownPublicationNote })
    .getByRole('button', { name: 'Скрыть', exact: true })
    .waitFor({ state: 'visible', timeout: 5000 });

  const stamp = Date.now();
  const firstname = `Browser${String(stamp).slice(-6)}`;
  const patronymic = 'Lifecycle';
  const lastname = 'Profile';
  const email = `profile-${stamp}@example.test`;
  const phone = '+37061234567';

  await page.locator('.profile__edit_user-info').click();
  const editPanel = page.locator('.profile__card-info--edit');
  await editPanel.waitFor({ state: 'visible', timeout: 5000 });

  await editPanel.locator('#user-name').fill(firstname);
  await editPanel.locator('#user-patronymic').fill(patronymic);
  await editPanel.locator('#user-surname').fill(lastname);
  await editPanel.locator('#user-phone').fill(phone);
  await editPanel.locator('#user-email').fill(email);
  await editPanel.locator('#user-avatar').setInputFiles(avatarPath);

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
    editPanel.getByRole('button', { name: 'Сохранить изменения', exact: true }).click(),
  ]);

  if (new URL(page.url()).pathname.replace(/\/+$/, '') !== `${basePath}/profile`) {
    throw new Error(`Profile update redirect escaped BASE_PATH: ${page.url()}`);
  }

  await page.locator('.profile__user-fio').filter({ hasText: `${firstname} ${lastname}` })
    .waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('.profile__user-other-info').filter({ hasText: phone })
    .waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('.profile__user-other-info').filter({ hasText: email })
    .waitFor({ state: 'visible', timeout: 10000 });

  const avatar = page.locator('.profile__card-avatar img');
  const avatarSrc = await avatar.getAttribute('src');
  if (!avatarSrc) throw new Error('Profile avatar has no src after upload');
  const avatarUrl = new URL(avatarSrc, origin);
  if (avatarUrl.origin !== origin || !avatarUrl.pathname.startsWith(`${basePath}/profile/avatar/`)) {
    throw new Error(`Avatar URL escaped BASE_PATH: ${avatarSrc}`);
  }
  const avatarResponse = await context.request.get(avatarUrl.href);
  if (avatarResponse.status() !== 200) throw new Error(`Uploaded avatar returned HTTP ${avatarResponse.status()}`);
  if (avatarResponse.headers()['content-type'] !== 'image/jpeg') throw new Error(`Unexpected avatar content type: ${avatarResponse.headers()['content-type']}`);
  if ((await avatarResponse.body()).length < 100) throw new Error('Uploaded avatar response is unexpectedly small');

  const deleteForm = page.locator('.profile__avatar-delete');
  await deleteForm.waitFor({ state: 'visible', timeout: 5000 });
  const deleteDialog = acceptDialog(page, 'Удалить фото профиля?');
  const deleteNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await deleteForm.getByRole('button', { name: 'Удалить фото', exact: true }).click();
  await deleteDialog;
  await deleteNavigation;

  if (new URL(page.url()).pathname.replace(/\/+$/, '') !== `${basePath}/profile`) throw new Error(`Avatar delete redirect escaped BASE_PATH: ${page.url()}`);
  if (await page.locator('.profile__avatar-delete').count()) throw new Error('Avatar delete form is still present after removal');

  const defaultSrc = await page.locator('.profile__card-avatar img').getAttribute('src');
  if (!defaultSrc) throw new Error('Default avatar has no src after removal');
  const defaultUrl = new URL(defaultSrc, origin);
  if (defaultUrl.origin !== origin || defaultUrl.pathname !== `${basePath}/assets/img/default_avatar.png`) {
    throw new Error(`Default avatar URL escaped BASE_PATH: ${defaultSrc}`);
  }

  const publicResponse = await page.goto(`${baseUrl}/profile/user/${otherUid}`, { waitUntil: 'domcontentloaded' });
  if (!publicResponse || publicResponse.status() !== 200) throw new Error(`Other-user profile returned ${publicResponse?.status()}`);
  await page.getByRole('heading', { name: 'Public Viewer' }).waitFor({ state: 'visible', timeout: 5000 });
  await page.getByText('@profile-public-user', { exact: true }).waitFor({ state: 'visible', timeout: 5000 });

  for (const title of [publicNote, publicTask, publicFile]) {
    await page.getByText(title, { exact: true }).waitFor({ state: 'visible', timeout: 5000 });
  }

  const publicBody = await page.locator('main').innerText();
  for (const secretValue of [otherEmail, otherPhone, privateMarker, privateNote, privateTask, privateFile, privateStorageMarker]) {
    if (publicBody.includes(secretValue)) throw new Error(`Other-user profile leaked private value: ${secretValue}`);
  }
  if (await page.locator('.profile__edit_user-info').count()) throw new Error('Other-user profile exposed own-profile edit control');
  if (await page.locator('.profile-public__item a').count()) throw new Error('Public profile exposed direct content/storage links');

  await page.goto(`${baseUrl}/profile/user/${ownUid}`, { waitUntil: 'domcontentloaded' });
  if (new URL(page.url()).pathname.replace(/\/+$/, '') !== `${basePath}/profile`) {
    throw new Error(`Own public-profile route did not redirect to profile hub: ${page.url()}`);
  }
  await page.getByRole('link', { name: /Мои заметки/ }).waitFor({ state: 'visible', timeout: 5000 });

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  if (unexpectedHttpErrors.length) throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);

  console.log('Profile hub/edit/avatar/explicit-public-content lifecycle: OK');
  await context.close();
} finally {
  await browser.close();
}
