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

async function waitForNavigation(page, action) {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await action();
  await navigation;
}

async function answerWorkspaceDialog(page, { value = null, button }) {
  const dialog = page.locator('.wspace-dialog');
  await dialog.waitFor({ state: 'visible', timeout: 5000 });
  if (value !== null) await dialog.locator('.wspace-dialog__input').fill(value);
  await dialog.getByRole('button', { name: button, exact: true }).click();
  await dialog.waitFor({ state: 'detached', timeout: 5000 });
}

try {
  const context = await browser.newContext();
  const page = await context.newPage();
  instrument(page);
  await login(page);

  const tasksResponse = await page.goto(`${baseUrl}/tasks/`, { waitUntil: 'domcontentloaded' });
  if (!tasksResponse || tasksResponse.status() !== 200) throw new Error(`Tasks page returned ${tasksResponse?.status()}`);

  const sortAction = await page.locator('.tasks__sort-form').getAttribute('action');
  if (!sortAction || !sortAction.startsWith(`${basePath}/tasks`)) {
    throw new Error(`Tasks sort form escaped BASE_PATH: ${sortAction}`);
  }

  const board = page.locator('.tasks-board');
  await board.waitFor({ state: 'visible', timeout: 5000 });
  if (await board.locator('.tasks-board__column').count() !== 4) {
    throw new Error('Kanban board must render four status columns');
  }
  if ((await page.locator('.tasks-view-switch__button.active').textContent())?.trim() !== 'Доска') {
    throw new Error('Kanban board is not the default Tasks view');
  }

  const stamp = Date.now();
  const title = `Tasks lifecycle ${stamp}`;
  const updatedTitle = `${title} updated`;
  const description = `Task description ${stamp}`;
  const updatedDescription = `Updated task description ${stamp}`;
  const subtaskTitle = `Subtask ${stamp}`;

  await page.locator('#open-create-task').click();
  await page.locator('#create-task-modal').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#title').fill(title);
  await page.locator('#description').fill(description);
  await page.locator('#priority').selectOption('high');
  await waitForNavigation(page, () => page.locator('#create-task-modal .task-form button[type="submit"]').click());

  let task = page.locator('.task-item').filter({ hasText: title });
  await task.waitFor({ state: 'visible', timeout: 10000 });
  const taskUid = await task.getAttribute('data-task-id');
  if (!taskUid) throw new Error('Created task has no data-task-id');
  if ((await task.locator('xpath=..').getAttribute('data-status')) !== 'pending') {
    throw new Error('New task was not placed in the pending kanban column');
  }

  await task.locator('.edit-task').click();
  const editPanel = task.locator('.task-edit-panel');
  await editPanel.waitFor({ state: 'visible', timeout: 5000 });
  await editPanel.locator('input[name="title"]').fill(updatedTitle);
  await editPanel.locator('textarea[name="description"]').fill(updatedDescription);
  await editPanel.locator('select[name="status"]').selectOption('in_progress');
  await editPanel.locator('select[name="priority"]').selectOption('urgent');
  await waitForNavigation(page, () => editPanel.getByRole('button', { name: 'Сохранить', exact: true }).click());

  task = page.locator(`.task-item[data-task-id="${taskUid}"]`);
  await task.waitFor({ state: 'visible', timeout: 10000 });
  await task.getByRole('heading', { name: updatedTitle }).waitFor({ state: 'visible' });
  await task.locator('.task-description p').filter({ hasText: updatedDescription }).waitFor({ state: 'visible' });
  if ((await task.locator('.task-status-toggle').inputValue()) !== 'in_progress') {
    throw new Error('Edited task status did not persist');
  }
  if ((await task.locator('xpath=..').getAttribute('data-status')) !== 'in_progress') {
    throw new Error('Edited task was not rendered in the in-progress kanban column');
  }

  // Shared prompt UI adds the subtask without a full-page navigation.
  await task.locator('.add-subtask-btn').click();
  await answerWorkspaceDialog(page, { value: subtaskTitle, button: 'Добавить' });

  let subtask = page.locator(`.task-item[data-task-id="${taskUid}"] .subtask-item`).filter({ hasText: subtaskTitle });
  await subtask.waitFor({ state: 'visible', timeout: 10000 });
  const inlineUrl = page.url();

  await subtask.locator('.subtask-toggle').check();
  await subtask.waitFor({ state: 'visible', timeout: 5000 });
  await page.waitForFunction(
    ({ uid, titleText }) => [...document.querySelectorAll(`.task-item[data-task-id="${uid}"] .subtask-item.completed`)]
      .some((node) => node.textContent.includes(titleText)),
    { uid: taskUid, titleText: subtaskTitle },
    { timeout: 10000 }
  );
  if (page.url() !== inlineUrl) throw new Error('Subtask toggle unexpectedly navigated the page');
  subtask = page.locator(`.task-item[data-task-id="${taskUid}"] .subtask-item`).filter({ hasText: subtaskTitle });
  if (!(await subtask.locator('.subtask-toggle').isChecked())) throw new Error('Subtask completion did not persist');

  // The 0.13 board must persist status through the same API when a card is dragged.
  task = page.locator(`.task-item[data-task-id="${taskUid}"]`);
  const completedDropzone = page.locator('.tasks-board__dropzone[data-status="completed"]');
  await task.dragTo(completedDropzone);
  await page.waitForFunction(
    (uid) => {
      const item = document.querySelector(`.task-item[data-task-id="${uid}"]`);
      return item?.parentElement?.dataset.status === 'completed'
        && item?.querySelector('.task-status-toggle')?.value === 'completed'
        && item?.querySelector('.task-complete-toggle')?.checked === true;
    },
    taskUid,
    { timeout: 10000 }
  );
  if (page.url() !== inlineUrl) throw new Error('Kanban drag unexpectedly navigated the page');

  // View switching is client-side and must not lose the task DOM/state.
  await page.getByRole('button', { name: /Список/ }).click();
  await page.locator('.tasks__list').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator(`.tasks__list .task-item[data-task-id="${taskUid}"]`).waitFor({ state: 'visible', timeout: 5000 });
  await page.getByRole('button', { name: /Доска/ }).click();
  await page.locator('.tasks-board').waitFor({ state: 'visible', timeout: 5000 });
  await page.locator(`.tasks-board__dropzone[data-status="completed"] .task-item[data-task-id="${taskUid}"]`)
    .waitFor({ state: 'visible', timeout: 5000 });

  await page.locator('.tasks__sort-form select[name="sort"]').selectOption('title');
  await page.locator('.tasks__sort-form select[name="direction"]').selectOption('asc');
  await Promise.all([
    page.waitForURL((url) => url.pathname.replace(/\/+$/, '') === `${basePath}/tasks` && url.searchParams.get('sort') === 'title', { timeout: 15000 }),
    page.locator('.tasks__sort-form button[type="submit"]').click(),
  ]);
  await page.locator(`.task-item[data-task-id="${taskUid}"]`).waitFor({ state: 'visible', timeout: 10000 });

  // Shared confirmation replaces native confirm; accepted form still performs the normal POST redirect.
  task = page.locator(`.task-item[data-task-id="${taskUid}"]`);
  await task.locator('.delete-task').click();
  const deleteNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
  await answerWorkspaceDialog(page, { button: 'Удалить' });
  await deleteNavigation;
  if (await page.locator(`.task-item[data-task-id="${taskUid}"]`).count()) {
    throw new Error('Deleted task is still visible');
  }

  if (pageErrors.length) throw pageErrors[0];
  if (escapedRequests.length) throw new Error(`Requests escaped BASE_PATH: ${[...new Set(escapedRequests)].join(', ')}`);
  if (unexpectedHttpErrors.length) throw new Error(`Unexpected HTTP errors: ${unexpectedHttpErrors.join(', ')}`);

  console.log(`Tasks kanban lifecycle browser flow: OK (${taskUid})`);
  await context.close();
} finally {
  await browser.close();
}