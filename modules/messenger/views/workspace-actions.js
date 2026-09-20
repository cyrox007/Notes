{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        const root = document.getElementById('messenger-app');
        if (!app || !root) return;

        const canNote = root.dataset.canCreateNote === '1';
        const canTask = root.dataset.canCreateTask === '1';
        const createButton = document.getElementById('workspace-create-button');
        const menu = document.getElementById('workspace-create-menu');
        const dialog = document.getElementById('workspace-action-dialog');
        const form = document.getElementById('workspace-action-form');
        const titleInput = document.getElementById('workspace-action-title');
        const bodyInput = document.getElementById('workspace-action-body');
        const priorityInput = document.getElementById('workspace-action-priority');
        const dueInput = document.getElementById('workspace-action-due');
        const taskFields = document.getElementById('workspace-task-fields');
        const source = document.getElementById('workspace-action-source');
        const sourceText = document.getElementById('workspace-action-source-text');
        const submit = document.getElementById('workspace-action-submit');
        const heading = document.getElementById('workspace-action-heading');
        const subtitle = document.getElementById('workspace-action-subtitle');
        const close = document.getElementById('workspace-action-close');
        const cancel = document.getElementById('workspace-action-cancel');
        const kindButtons = Array.from(document.querySelectorAll('[data-workspace-kind]'));
        let kind = canTask ? 'task' : 'note';
        let sourceMessage = null;

        if (!canNote && !canTask) {
            if (createButton) createButton.hidden = true;
            return;
        }

        const appPath = (path) => typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;

        function messageText(message) {
            const text = String(message?.message || '').trim();
            if (text !== '') return text;
            switch (String(message?.message_type || '')) {
                case 'voice': return 'Голосовое сообщение';
                case 'image': return 'Изображение';
                case 'audio': return 'Аудиозапись';
                case 'video': return 'Видео';
                case 'file': return 'Файл';
                default: return 'Сообщение из Messenger';
            }
        }

        function suggestedTitle(text, fallback) {
            const normalized = String(text || '').replace(/\s+/g, ' ').trim();
            if (!normalized) return fallback;
            return normalized.length > 90 ? normalized.slice(0, 89) + '…' : normalized;
        }

        function setKind(nextKind) {
            if (nextKind === 'task' && !canTask) nextKind = 'note';
            if (nextKind === 'note' && !canNote) nextKind = 'task';
            kind = nextKind;
            if (form) form.dataset.kind = kind;
            if (dialog) dialog.dataset.kind = kind;
            kindButtons.forEach((button) => {
                const selected = button.dataset.workspaceKind === kind;
                button.hidden = (button.dataset.workspaceKind === 'task' && !canTask)
                    || (button.dataset.workspaceKind === 'note' && !canNote);
                button.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            if (taskFields) taskFields.hidden = kind !== 'task';
            if (bodyInput) {
                bodyInput.maxLength = kind === 'task' ? 10000 : 60000;
                bodyInput.previousElementSibling.textContent = kind === 'task' ? 'Описание' : 'Содержимое';
            }
            if (heading) heading.textContent = kind === 'task' ? 'Новая задача' : 'Новая заметка';
            if (subtitle) subtitle.textContent = kind === 'task'
                ? 'Создайте задачу, не выходя из Messenger.'
                : 'Сохраните заметку, не выходя из Messenger.';
            if (submit) submit.textContent = kind === 'task' ? 'Создать задачу' : 'Создать заметку';
        }

        function openDialog(nextKind, message = null) {
            if (!dialog || !form) return;
            sourceMessage = message;
            const text = messageText(message);
            setKind(nextKind);
            form.reset();
            setKind(kind);

            if (message) {
                titleInput.value = suggestedTitle(text, kind === 'task' ? 'Новая задача' : 'Новая заметка');
                bodyInput.value = text;
                source.hidden = false;
                sourceText.textContent = app.truncate(text, 180);
            } else {
                titleInput.value = '';
                bodyInput.value = '';
                source.hidden = true;
                sourceText.textContent = '';
            }

            dialog.showModal();
            window.setTimeout(() => titleInput?.focus(), 0);
        }

        function hideMenu() {
            if (menu) menu.hidden = true;
            createButton?.setAttribute('aria-expanded', 'false');
        }

        createButton?.addEventListener('click', (event) => {
            event.stopPropagation();
            if (!menu) return;
            menu.hidden = !menu.hidden;
            createButton.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
        });

        menu?.querySelectorAll('[data-create-workspace]').forEach((button) => {
            button.addEventListener('click', () => {
                hideMenu();
                openDialog(button.dataset.createWorkspace, null);
            });
        });

        document.addEventListener('click', (event) => {
            if (menu?.hidden === false && !menu.contains(event.target) && event.target !== createButton) hideMenu();
        });

        kindButtons.forEach((button) => {
            button.addEventListener('click', () => setKind(button.dataset.workspaceKind));
        });

        close?.addEventListener('click', () => dialog?.close());
        cancel?.addEventListener('click', () => dialog?.close());

        const originalRenderMessage = app.renderMessage.bind(app);
        app.renderMessage = (message) => {
            const row = originalRenderMessage(message);
            const actions = row.querySelector('.messenger-message__actions');
            if (actions) {
                const action = app.messageAction(
                    'Создать задачу или заметку',
                    'fa-plus-square-o',
                    () => openDialog(canTask ? 'task' : 'note', message)
                );
                action.classList.add('messenger-message__action--workspace');
                actions.append(action);
            }
            return row;
        };
        if (app.messages?.length) app.renderMessages();

        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!submit || submit.disabled) return;

            const title = String(titleInput?.value || '').trim();
            const body = String(bodyInput?.value || '').trim();
            if (!title) {
                app.showToast(kind === 'task' ? 'Укажите название задачи' : 'Укажите название заметки');
                titleInput?.focus();
                return;
            }

            const payload = new FormData();
            payload.set('title', title);
            if (kind === 'task') {
                payload.set('description', body);
                payload.set('priority', String(priorityInput?.value || 'medium'));
                payload.set('due_date', String(dueInput?.value || ''));
            } else {
                payload.set('content', body);
            }
            if (sourceMessage && app.currentDialog?.uid) {
                payload.set('dialog_uid', app.currentDialog.uid);
                payload.set('message_uid', sourceMessage.uid || '');
            }

            submit.disabled = true;
            const originalText = submit.textContent;
            submit.textContent = 'Создание…';
            try {
                const response = await fetch(appPath('/messenger/workspace/' + kind), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    body: payload
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data?.success !== true) {
                    throw new Error(data?.message || 'Не удалось создать объект');
                }

                dialog.close();
                showResult(data);
            } catch (error) {
                app.showToast(error instanceof Error ? error.message : 'Не удалось создать объект');
            } finally {
                submit.disabled = false;
                submit.textContent = originalText;
            }
        });

        function showResult(data) {
            document.querySelector('.messenger-workspace-result')?.remove();
            const toast = document.createElement('div');
            toast.className = 'messenger-workspace-result';
            const icon = document.createElement('i');
            icon.className = 'fa ' + (data.kind === 'task' ? 'fa-check-square-o' : 'fa-sticky-note-o');
            icon.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span');
            text.textContent = data.message || (data.kind === 'task' ? 'Задача создана' : 'Заметка создана');
            toast.append(icon, text);
            if (data.open_url) {
                const link = document.createElement('a');
                link.href = data.open_url;
                link.textContent = 'Открыть';
                toast.append(link);
            }
            document.body.append(toast);
            window.setTimeout(() => toast.remove(), 6500);
        }

        setKind(kind);
    });
})();
{/literal}
