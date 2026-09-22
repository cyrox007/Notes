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

async function waitForMessageBubble(page, message) {
  await page.locator('#message-list').getByText(message, { exact: true }).waitFor({
    state: 'visible',
    timeout: 15000,
  });
}

async function assertRemoteActivity(senderPage, receiverPage, activity, expectedText) {
  const sent = await senderPage.evaluate((type) => {
    const app = window.wspace?.messenger;
    if (!app?.setLocalActivity || !app.currentDialog?.uid) return false;
    return app.setLocalActivity(type, true, app.currentDialog.uid);
  }, activity);
  if (!sent) throw new Error(`Unable to send Messenger activity: ${activity}`);

  await receiverPage.waitForFunction((text) => {
    const indicator = document.getElementById('typing-indicator');
    const label = document.getElementById('typing-text');
    return Boolean(indicator && !indicator.hidden && label?.textContent?.includes(text));
  }, expectedText, { timeout: 10000 });

  await senderPage.evaluate((type) => {
    const app = window.wspace?.messenger;
    if (!app?.setLocalActivity || !app.currentDialog?.uid) return;
    app.setLocalActivity(type, false, app.currentDialog.uid);
  }, activity);
  await receiverPage.locator('#typing-indicator').waitFor({ state: 'hidden', timeout: 10000 });
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

  // A dropped WebSocket must enter a recovery state, request a fresh short-lived
  // ticket over the authenticated HTTP session, and return to online without a
  // page reload or a second transport implementation. Observe the state mutation
  // directly because a healthy reconnect can make the visual banner too brief
  // for polling-based visibility assertions.
  await alice.page.evaluate(() => {
    const root = document.getElementById('messenger-app');
    const status = document.getElementById('messenger-connection');
    if (!root || !status) throw new Error('Messenger connection UI is missing');

    root.dataset.e2eSawRecovery = '0';
    window.__e2eReconnectObserver?.disconnect?.();
    window.__e2eReconnectObserver = new MutationObserver(() => {
      if (status.dataset.state && status.dataset.state !== 'online') {
        root.dataset.e2eSawRecovery = '1';
      }
    });
    window.__e2eReconnectObserver.observe(status, {
      attributes: true,
      attributeFilter: ['data-state'],
    });
  });

  const ticketRefresh = alice.page.waitForResponse(response => (
    response.url().includes('/messenger/socket-ticket')
    && response.request().method() === 'POST'
  ), { timeout: 15000 });

  await alice.page.evaluate(() => {
    const socket = window.wspace?.messenger?.socket;
    if (!socket || socket.readyState !== WebSocket.OPEN) {
      throw new Error('Alice WebSocket is not open before reconnect test');
    }
    socket.close(1000, 'e2e reconnect');
  });

  const refreshResponse = await ticketRefresh;
  if (refreshResponse.status() !== 200) {
    throw new Error(`Socket ticket refresh returned ${refreshResponse.status()}`);
  }

  await alice.page.waitForFunction(() => (
    window.wspace?.messenger?.socket?.readyState === WebSocket.OPEN
    && document.getElementById('messenger-connection')?.dataset.state === 'online'
  ), null, { timeout: 20000 });

  const sawRecovery = await alice.page.locator('#messenger-app').getAttribute('data-e2e-saw-recovery');
  if (sawRecovery !== '1') {
    throw new Error('Messenger UI did not enter a reconnecting/offline state after socket close');
  }

  await alice.page.locator('#messenger-network-banner').waitFor({ state: 'hidden', timeout: 5000 });
  await alice.page.evaluate(() => {
    window.__e2eReconnectObserver?.disconnect?.();
    delete window.__e2eReconnectObserver;
    document.getElementById('messenger-app')?.removeAttribute('data-e2e-saw-recovery');
  });

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
  await waitForMessageBubble(alice.page, message);

  // Bob receives the new dialog over WSS, opens it and sees the same message.
  const bobDialog = bob.page.locator('.messenger-dialog-item').first();
  await bobDialog.waitFor({ state: 'visible', timeout: 15000 });
  await bobDialog.click();
  await waitForMessageBubble(bob.page, message);

  // Prove the cross-process fallback bridge: Alice performs a durable Messenger
  // mutation through authenticated HTTP while Bob stays on the native WebSocket.
  // The HTTP process bumps the shared DB revision, the WS process emits
  // sync_required, and Bob reloads canonical dialog state without reconnecting.
  const fallbackMessage = `HTTP fallback bridge ${Date.now()}`;
  const fallbackResult = await alice.page.evaluate(async (text) => {
    const app = window.wspace?.messenger;
    const dialogUid = app?.currentDialog?.uid || '';
    if (!dialogUid) {
      return { ok: false, status: 0, body: 'Alice dialog UID is unavailable' };
    }

    const body = new URLSearchParams();
    body.set('action', 'MessangerSocket:message_send');
    body.set('data', JSON.stringify({ dialog_uid: dialogUid, message: text }));

    const response = await fetch('/messenger/realtime/action', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body,
    });
    return { ok: response.ok, status: response.status, body: await response.text() };
  }, fallbackMessage);

  if (!fallbackResult.ok) {
    throw new Error(
      `HTTP fallback message failed: HTTP ${fallbackResult.status} body=${fallbackResult.body}`
    );
  }

  let fallbackPayload;
  try {
    fallbackPayload = JSON.parse(fallbackResult.body);
  } catch (_) {
    throw new Error(`HTTP fallback returned invalid JSON: ${fallbackResult.body}`);
  }
  if (fallbackPayload?.status !== 'ok') {
    throw new Error(`HTTP fallback returned failure: ${fallbackResult.body}`);
  }

  await waitForMessageBubble(bob.page, fallbackMessage);
  const bobSocketStillOpen = await bob.page.evaluate(() => (
    window.wspace?.messenger?.socket?.readyState === WebSocket.OPEN
    && window.wspace?.messenger?.socketAuthorized === true
  ));
  if (!bobSocketStillOpen) {
    throw new Error('Bob WebSocket was not preserved across HTTP fallback bridge sync');
  }

  // Reproduce the user-visible presence contract with two actual browser
  // sessions over the native WSS runtime. Typing is emitted by the real input
  // handler, not by a synthetic server call.
  await alice.page.locator('#message-input').fill('typing presence probe');
  await bob.page.waitForFunction(() => {
    const indicator = document.getElementById('typing-indicator');
    const label = document.getElementById('typing-text');
    return Boolean(indicator && !indicator.hidden && label?.textContent?.includes('печатает'));
  }, null, { timeout: 10000 });
  await alice.page.evaluate(() => window.wspace?.messenger?.stopTyping?.());
  await bob.page.locator('#typing-indicator').waitFor({ state: 'hidden', timeout: 10000 });
  await alice.page.locator('#message-input').fill('');

  // The same transport carries transient recording/upload states. These calls
  // exercise the exact public client API used by voice.js/media.js; static CI
  // markers below prove those real producers are wired to it.
  const activityCases = [
    ['recording_voice', 'записывает голосовое'],
    ['recording_video', 'записывает видеосообщение'],
    ['uploading_image', 'отправляет изображение'],
    ['uploading_voice', 'отправляет голосовое'],
    ['uploading_audio', 'отправляет музыку/аудио'],
    ['uploading_video', 'отправляет видео'],
    ['uploading_document', 'отправляет документ'],
    ['uploading_file', 'отправляет файл'],
  ];
  for (const [activity, label] of activityCases) {
    await assertRemoteActivity(alice.page, bob.page, activity, label);
  }

  if (alice.pageErrors.length > 0) throw alice.pageErrors[0];
  if (bob.pageErrors.length > 0) throw bob.pageErrors[0];

  console.log('Browser HTTPS + authenticated WSS + reconnect + message + activity presence smoke: OK');

  await alice.context.close();
  await bob.context.close();
} finally {
  await browser.close();
}
