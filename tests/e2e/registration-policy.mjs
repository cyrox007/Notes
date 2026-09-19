import { chromium } from 'playwright';

function requiredEnv(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

const origin = requiredEnv('E2E_ORIGIN');
const basePathRaw = requiredEnv('E2E_BASE_PATH');
const adminUsername = requiredEnv('E2E_ADMIN_USER');
const adminPassword = requiredEnv('E2E_ADMIN_PASSWORD');
const basePath = '/' + basePathRaw.replace(/^\/+|\/+$/g, '');
const baseUrl = origin + basePath;

const browser = await chromium.launch({ headless: true });
const pageErrors = [];
const unexpectedHttpErrors = [];

function instrument(page) {
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin === origin && response.status() >= 400) {
      unexpectedHttpErrors.push(`${response.status()} ${url.pathname}`);
    }
  });
}

async function login(page, username, password) {
  const response = await page.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error(`Login page returned ${response?.status()}`);
  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);
}

async function submitAndWait(page, button) {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await button.click();
  await navigation;
}

async function fillRegistration(page, suffix, inviteCode = null) {
  if (inviteCode !== null) await page.locator('#invite_code').fill(inviteCode);
  await page.locator('#login').fill(`browser-${suffix}`);
  await page.locator('#email').fill(`browser-${suffix}@example.test`);
  await page.locator('#password').fill(`BrowserPass-${suffix}-2026`);
  await page.locator('#first_name').fill('Browser');
  await page.locator('#surname').fill('Registration');
}

try {
  const adminContext = await browser.newContext();
  const admin = await adminContext.newPage();
  instrument(admin);
  await login(admin, adminUsername, adminPassword);

  const adminResponse = await admin.goto(`${baseUrl}/admin/`, { waitUntil: 'domcontentloaded' });
  if (!adminResponse || adminResponse.status() !== 200) throw new Error(`Admin page returned ${adminResponse?.status()}`);

  // Open the account-creation disclosure before exercising provisioning.
  await admin.locator('.admin-create-user > summary').click();
  // Admin-side provisioning works regardless of public registration mode.
  await admin.locator('#new_user_login').fill('browser-admin-created');
  await admin.locator('#new_user_email').fill('browser-admin-created@example.test');
  await admin.locator('#new_user_password').fill('BrowserAdminCreated-2026');
  await admin.locator('#new_user_first_name').fill('Created');
  await admin.locator('#new_user_surname').fill('ByAdmin');
  await submitAndWait(admin, admin.getByRole('button', { name: 'Создать пользователя', exact: true }));
  await admin.locator('.admin-page__flash').filter({ hasText: 'Пользователь создан' })
    .waitFor({ state: 'visible', timeout: 10000 });
  await admin.locator('.admin-users-table tbody tr').filter({ hasText: '@browser-admin-created' })
    .waitFor({ state: 'visible', timeout: 10000 });

  const registrationLink = admin.getByRole('link', { name: 'Регистрация', exact: true });
  const registrationHref = await registrationLink.getAttribute('href');
  if (!registrationHref?.startsWith(`${basePath}/admin/registration`)) {
    throw new Error(`Admin registration link escaped BASE_PATH: ${registrationHref}`);
  }
  await Promise.all([
    admin.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/admin/registration`, { timeout: 15000 }),
    registrationLink.click(),
  ]);

  // Enable open registration from the real admin settings page.
  await admin.locator('#registration_mode').selectOption('open');
  await submitAndWait(admin, admin.getByRole('button', { name: 'Сохранить режим', exact: true }));
  await admin.locator('.admin-page__flash').filter({ hasText: 'Режим регистрации обновлён' })
    .waitFor({ state: 'visible', timeout: 10000 });

  const publicContext = await browser.newContext();
  const publicPage = await publicContext.newPage();
  instrument(publicPage);
  await publicPage.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  const openLink = publicPage.getByRole('link', { name: 'Зарегистрироваться', exact: true });
  await openLink.waitFor({ state: 'visible', timeout: 10000 });
  await Promise.all([
    publicPage.waitForURL((url) => url.pathname.includes('/auth/registration'), { timeout: 15000 }),
    openLink.click(),
  ]);
  await fillRegistration(publicPage, 'open');
  await submitAndWait(publicPage, publicPage.getByRole('button', { name: 'Создать аккаунт', exact: true }));
  await publicPage.waitForURL((url) => url.pathname.includes('/auth/login'), { timeout: 10000 });
  await login(publicPage, 'browser-open', 'BrowserPass-open-2026');
  await publicContext.close();

  // Switch to invite-only, create a one-use invite, and consume it from a
  // separate anonymous browser context.
  await admin.goto(`${baseUrl}/admin/registration`, { waitUntil: 'domcontentloaded' });
  await admin.locator('#registration_mode').selectOption('invite');
  await submitAndWait(admin, admin.getByRole('button', { name: 'Сохранить режим', exact: true }));
  await admin.locator('#invite_label').fill('Browser invite');
  await admin.locator('#invite_max_uses').fill('1');
  await submitAndWait(admin, admin.getByRole('button', { name: 'Создать инвайт', exact: true }));
  const inviteCode = await admin.locator('#new_invite_code').inputValue();
  if (!inviteCode || inviteCode.length < 20) throw new Error('Admin UI did not reveal the one-time invite code');

  const inviteContext = await browser.newContext();
  const invitePage = await inviteContext.newPage();
  instrument(invitePage);
  await invitePage.goto(`${baseUrl}/auth/login/`, { waitUntil: 'domcontentloaded' });
  const inviteLink = invitePage.getByRole('link', { name: 'Регистрация по инвайту', exact: true });
  await inviteLink.waitFor({ state: 'visible', timeout: 10000 });
  await Promise.all([
    invitePage.waitForURL((url) => url.pathname.includes('/auth/registration'), { timeout: 15000 }),
    inviteLink.click(),
  ]);
  await fillRegistration(invitePage, 'invite', inviteCode);
  await submitAndWait(invitePage, invitePage.getByRole('button', { name: 'Создать аккаунт', exact: true }));
  await invitePage.waitForURL((url) => url.pathname.includes('/auth/login'), { timeout: 10000 });
  await login(invitePage, 'browser-invite', 'BrowserPass-invite-2026');
  await inviteContext.close();

  if (pageErrors.length) throw pageErrors[0];
  if (unexpectedHttpErrors.length) {
    throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);
  }

  console.log('Registration/admin provisioning browser flow: OK');
  await adminContext.close();
} finally {
  await browser.close();
}
