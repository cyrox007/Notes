{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const listHeader = document.querySelector('.messenger-list__header');
        const newChatButton = document.getElementById('new-chat-button');
        if (listHeader && newChatButton) {
            const savedButton = document.createElement('button');
            savedButton.type = 'button';
            savedButton.className = 'messenger-icon-button messenger-saved-button';
            savedButton.title = 'Сохранённые сообщения';
            savedButton.setAttribute('aria-label', savedButton.title);
            const icon = document.createElement('i');
            icon.className = 'fa fa-bookmark-o';
            icon.setAttribute('aria-hidden', 'true');
            savedButton.append(icon);
            savedButton.addEventListener('click', () => {
                app.sendEvent('ForwardSocket:saved', {});
            });
            newChatButton.before(savedButton);
        }

        const modal = document.createElement('dialog');
        modal.className = 'messenger-dialog-modal messenger-forward-dialog';
        const surface = document.createElement('form');
        surface.method = 'dialog';
        surface.className = 'messenger-dialog-modal__surface messenger-forward-dialog__surface';
        const header = document.createElement('header');
        const headerCopy = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = 'Переслать сообщение';
        const subtitle = document.createElement('span');
        subtitle.textContent = 'Выберите чат назначения';
        headerCopy.append(title, subtitle);
        const close = document.createElement('button');
        close.className = 'messenger-icon-button';
        close.value = 'cancel';
        close.setAttribute('aria-label', 'Закрыть');
        close.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
        header.append(headerCopy, close);

        const searchWrap = document.createElement('div');
        searchWrap.className = 'messenger-search messenger-search--modal';
        searchWrap.innerHTML = '<i class="fa fa-search" aria-hidden="true"></i>';
        const search = document.createElement('input');
        search.type = 'search';
        search.autocomplete = 'off';
        search.placeholder = 'Найти чат';
        search.setAttribute('aria-label', 'Найти чат для пересылки');
        searchWrap.append(search);

        const list = document.createElement('div');
        list.className = 'messenger-forward-dialog__list';
        surface.append(header, searchWrap, list);
        modal.append(surface);
        document.body.append(modal);

        let pendingMessageUid = null;

        const dialogTitle = (dialog) => dialog.type === 'saved'
            ? 'Сохранённые сообщения'
            : (dialog.title || dialog.name || 'Диалог');

        const renderDestinations = () => {
            const query = search.value.trim().toLocaleLowerCase('ru');
            const dialogs = (app.dialogs || []).filter((dialog) => {
                const value = `${dialogTitle(dialog)} ${dialog.last_message_preview || ''}`.toLocaleLowerCase('ru');
                return !query || value.includes(query);
            });

            list.replaceChildren();
            if (dialogs.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'messenger-forward-dialog__empty';
                empty.textContent = query ? 'Чаты не найдены' : 'Нет доступных чатов';
                list.append(empty);
                return;
            }

            dialogs.forEach((dialog) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'messenger-forward-dialog__item';
                const avatar = app.createAvatar(dialogTitle(dialog), 'messenger-avatar messenger-avatar--small');
                if (dialog.type === 'saved') {
                    avatar.replaceChildren();
                    const icon = document.createElement('i');
                    icon.className = 'fa fa-bookmark';
                    icon.setAttribute('aria-hidden', 'true');
                    avatar.append(icon);
                }
                const copy = document.createElement('span');
                copy.className = 'messenger-forward-dialog__identity';
                const name = document.createElement('strong');
                name.textContent = dialogTitle(dialog);
                const preview = document.createElement('small');
                preview.textContent = dialog.type === 'saved'
                    ? 'Только вы'
                    : (dialog.last_message_preview || (dialog.type === 'group' ? 'Групповой чат' : 'Личный чат'));
                copy.append(name, preview);
                button.append(avatar, copy);
                button.addEventListener('click', () => {
                    if (!pendingMessageUid) return;
                    const messageUid = pendingMessageUid;
                    pendingMessageUid = null;
                    modal.close();
                    app.sendEvent('ForwardSocket:forward', {
                        message_uid: messageUid,
                        dialog_uid: dialog.uid,
                    });
                });
                list.append(button);
            });
        };

        search.addEventListener('input', renderDestinations);
        modal.addEventListener('close', () => {
            pendingMessageUid = null;
            search.value = '';
        });

        const openForward = (message) => {
            pendingMessageUid = message?.uid || null;
            if (!pendingMessageUid) return;
            search.value = '';
            renderDestinations();
            if (!modal.open) modal.showModal();
            requestAnimationFrame(() => search.focus());
        };

        const originalRenderMessage = app.renderMessage.bind(app);
        app.renderMessage = (message) => {
            const row = originalRenderMessage(message);
            if (!message?.uid) return row;
            const bubble = row.querySelector('.messenger-message__bubble');
            const actions = row.querySelector('.messenger-message__actions');
            if (!bubble || !actions) return row;

            const forwarded = message.meta_data?.forwarded_from;
            if (forwarded?.user_name) {
                const origin = document.createElement('div');
                origin.className = 'messenger-message__forwarded';
                const icon = document.createElement('i');
                icon.className = 'fa fa-share';
                icon.setAttribute('aria-hidden', 'true');
                const copy = document.createElement('span');
                copy.textContent = `Переслано от ${forwarded.user_name}`;
                origin.append(icon, copy);
                bubble.insertBefore(origin, bubble.firstChild);
            }

            const save = app.messageAction('Сохранить', 'fa-bookmark-o', () => {
                app.sendEvent('ForwardSocket:save_message', { message_uid: message.uid });
            });
            const forward = app.messageAction('Переслать', 'fa-share', () => openForward(message));
            actions.insertBefore(forward, actions.firstChild);
            actions.insertBefore(save, actions.firstChild);
            return row;
        };

        const originalRenderHeader = app.renderChatHeader.bind(app);
        app.renderChatHeader = () => {
            const result = originalRenderHeader();
            if (app.currentDialog?.type === 'saved') {
                app.el.chatTitle.textContent = 'Сохранённые сообщения';
                app.el.chatSubtitle.textContent = 'личное облако';
                app.el.chatSubtitle.dataset.state = '';
                app.setAvatar(app.el.chatAvatar, 'С');
            }
            return result;
        };

        const originalHandle = app.handleSocketMessage.bind(app);
        app.handleSocketMessage = (event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (_) {
                return originalHandle(event);
            }

            if (data?.action === 'saved_dialog' && data.dialog?.uid) {
                app.pendingOpenUid = data.dialog.uid;
                app.sendEvent('MessangerSocket:get_dialogs', {});
                return;
            }

            if (data?.action === 'message_saved') {
                app.showToast('Сообщение сохранено');
                return;
            }

            if (data?.action === 'message_forwarded') {
                app.showToast('Сообщение переслано');
                return;
            }

            return originalHandle(event);
        };
    });
})();
{/literal}
