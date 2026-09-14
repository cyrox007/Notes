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
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

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
  await page.getByText(`Телефон: ${phone}`, { exact: true }).waitFor({ state: 'visible', timeout: 10000 });
  await page.getByText(`Email: ${email}`, { exact: true }).waitFor({ state: 'visible', timeout: 10000 });

  const avatar = page.locator('.profile__card-avatar img');
  const avatarSrc = await avatar.getAttribute('src');
  if (!avatarSrc) throw new Error('Profile avatar has no src after upload');
  const avatarUrl = new URL(avatarSrc, origin);
  if (avatarUrl.origin !== origin || !avatarUrl.pathname.startsWith(`${basePath}/profile/avatar/`)) {
    throw new Error(`Avatar URL escaped BASE_PATH: ${avatarSrc}`);
  }
  const avatarResponse = await context.request.get(avatarUrl.href);
  if (avatarResponse.status() !== 200) {
    throw new Error(`Uploaded avatar returned HTTP ${avatarResponse.status()}`);
  }
  if (avatarResponse.headers()['content-type'] !== 'image/jpeg') {
    throw new Error(`Unexpected avatar content type: ${avatarResponse.headers()['content-type']}`);
  }
  if ((await avatarResponse.body()).length < 100) {
    throw new Error('Uploaded avatar response is unexpectedly small');
  }

  const deleteForm = page.locator('.profile__avatar-delete');
  await deleteForm.waitFor({ state: 'visible', timeout: 5000 });
  const deleteDialog = acceptDialog(page, 'Удалить фото профиля?');
  const deleteNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await deleteForm.getByRole('button', { name: 'Удалить фото', exact: true }).click();
  await deleteDialog;
  await deleteNavigation;

  if (new URL(page.url()).pathname.replace(/\/+$/, '') !== `${basePath}/profile`) {
    throw new Error(`Avatar delete redirect escaped BASE_PATH: ${page.url()}`);
  }
  if (await page.locator('.profile__avatar-delete').count()) {
    throw new Error('Avatar delete form is still present after removal');
  }

  const defaultSrc = await page.locator('.profile__card-avatar img').getAttribute('src');
  if (!defaultSrc) throw new Error('Default avatar has no src after removal');
  const defaultUrl = new URL(defaultSrc, origin);
  if (defaultUrl.origin !== origin || defaultUrl.pathname !== `${basePath}/assets/img/default_avatar.png`) {
    throw new Error(`Default avatar URL escaped BASE_PATH: ${defaultSrc}`);
  }

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) {
    throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  }
  if (unexpectedHttpErrors.length) {
    throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log('Profile edit/avatar upload/remove browser lifecycle: OK');
  await context.close();
} finally {
  await browser.close();
}
