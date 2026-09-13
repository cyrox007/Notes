import { chromium } from 'playwright';

const baseUrl = process.env.E2E_BASE_URL || 'https://127.0.0.1:8443';
const aliceUser = process.env.E2E_ALICE_USER || 'e2e_alice';
const alicePassword = process.env.E2E_ALICE_PASSWORD || 'E2eAlicePass123!';
const bobUser = process.env.E2E_BOB_USER || 'e2e_bob';
const bobPassword = process.env.E2E_BOB_PASSWORD || 'E2eBobPass123!';
const bobUid = process.env.E2E_BOB_UID || '22222222-2222-4222-8222-222222222222';

const browser = await chromium.launch({ headless: true });

async function createSession(username, password) {
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(error));

  const loginResponse = await page.goto(`${baseUrl}/auth/login`, { waitUntil: 'domcontentloaded' });
  if (!loginResponse || loginResponse.status() !== 200) {
    throw new Error(`Login page unavailable for ${username}: ${loginResponse?.status()}`);
  }

  await page.locator('#login').fill(username);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForURL(url => !url.pathname.includes('/auth/login'), { timeout: 15000 }),
    page.getByRole('button', { name: 'Войти' }).click(),
  ]);

  await page.locator('#main-content').waitFor({ state: 'visible' });
  if (pageErrors.length > 0) {
    throw pageErrors[0];
  }

  return { context, page, pageErrors };
}

async function assertModuleLoads(page, path, selector) {
  const response = await page.goto(`${baseUrl}${path}`, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) {
    throw new Error(`${path} returned ${response?.status()}`);
  }
  await page.locator(selector).waitFor({ state: 'visible', timeout: 15000 });
}

try {
  const alice = await createSession(aliceUser, alicePassword);
  const bob = await createSession(bobUser, bobPassword);

  // Basic server-rendered modules must survive a real HTTPS browser session.
  await assertModuleLoads(alice.page, '/notes/', '#main-content');
  await assertModuleLoads(alice.page, '/tasks/', '#main-content');
  await assertModuleLoads(alice.page, '/files/', '#main-content');
  await assertModuleLoads(alice.page, '/profile/', '.profile');

  // Messenger pages must establish authenticated WSS through the TLS proxy.
  await assertModuleLoads(alice.page, '/messenger/', '#messenger-app');
  await assertModuleLoads(bob.page, '/messenger/', '#messenger-app');

  await alice.page.locator('#messenger-connection[data-state="online"]').waitFor({ timeout: 20000 });
  await bob.page.locator('#messenger-connection[data-state="online"]').waitFor({ timeout: 20000 });

  // Create a private dialog entirely through the new-chat modal. Contacts also
  // exist in the group-management dialog, so keep every selector modal-scoped.
  await alice.page.locator('#new-chat-button').click();
  const newChatDialog = alice.page.locator('#new-chat-dialog');
  await newChatDialog.waitFor({ state: 'visible', timeout: 10000 });
  const bobCheckbox = newChatDialog.locator(`.messenger-contact__checkbox[value="${bobUid}"]`);
  await bobCheckbox.waitFor({ state: 'visible' });
  await bobCheckbox.check();
  await newChatDialog.locator('#create-chat-button').click();
  await alice.page.locator('#chat-active').waitFor({ state: 'visible', timeout: 15000 });

  const message = `Browser WSS E2E ${Date.now()}`;
  await alice.page.locator('#message-input').fill(message);
  await alice.page.locator('#message-send-button').click();
  await alice.page.getByText(message, { exact: true }).waitFor({ timeout: 15000 });

  // Bob receives the new dialog over WSS, opens it and sees the same message.
  const bobDialog = bob.page.locator('.messenger-dialog-item').first();
  await bobDialog.waitFor({ state: 'visible', timeout: 15000 });
  await bobDialog.click();
  await bob.page.getByText(message, { exact: true }).waitFor({ timeout: 15000 });

  if (alice.pageErrors.length > 0) throw alice.pageErrors[0];
  if (bob.pageErrors.length > 0) throw bob.pageErrors[0];

  console.log('Browser HTTPS + authenticated WSS + realtime message smoke: OK');

  await alice.context.close();
  await bob.context.close();
} finally {
  await browser.close();
}
