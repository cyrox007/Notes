{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const headerActions = document.querySelector('.messenger-chat__actions');
        if (!headerActions) return;

        const searchButton = document.createElement('button');
        searchButton.type = 'button';
        searchButton.className = 'messenger-icon-button';
        searchButton.title = 'Поиск сообщений';
        searchButton.setAttribute('aria-label', 'Поиск сообщений');
        const searchIcon = document.createElement('i');
        searchIcon.className = 'fa fa-search';
        searchIcon.setAttribute('aria-hidden', 'true');
        searchButton.append(searchIcon);
        headerActions.prepend(searchButton);

        const dialog = document.createElement('dialog');
        dialog.className = 'messenger-dialog-modal';

        const surface = document.createElement('form');
        surface.method = 'dialog';
        surface.className = 'messenger-dialog-modal__surface messenger-search-modal__surface';

        const header = document.createElement('header');
        const headerCopy = document.createElement('div');
        const headerTitle = document.createElement('strong');
        headerTitle.textContent = 'Поиск';
        const headerSubtitle = document.createElement('span');
        headerSubtitle.textContent = 'По сообщениям и чатам';
        headerCopy.append(headerTitle, headerSubtitle);
        const close = document.createElement('button');
        close.className = 'messenger-icon-button';
        close.value = 'cancel';
        close.setAttribute('aria-label', 'Закрыть поиск');
        const closeIcon = document.createElement('i');
        closeIcon.className = 'fa fa-times';
        closeIcon.setAttribute('aria-hidden', 'true');
        close.append(closeIcon);
        header.append(headerCopy, close);

        const body = document.createElement('div');
        body.className = 'messenger-search-modal__body';
        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'messenger-search-modal__input';
        input.autocomplete = 'off';
        input.placeholder = 'Введите минимум 2 символа';
        input.setAttribute('aria-label', 'Поисковый запрос');

        const tabs = document.createElement('div');
        tabs.className = 'messenger-search-tabs';
        const currentTab = document.createElement('button');
        currentTab.type = 'button';
        currentTab.className = 'messenger-search-tab';
        currentTab.textContent = 'В этом чате';
        currentTab.setAttribute('aria-selected', 'true');
        const globalTab = document.createElement('button');
        globalTab.type = 'button';
        globalTab.className = 'messenger-search-tab';
        globalTab.textContent = 'Во всех чатах';
        globalTab.setAttribute('aria-selected', 'false');
        tabs.append(currentTab, globalTab);

        const results = document.createElement('div');
        results.className = 'messenger-search-results';
        results.setAttribute('aria-live', 'polite');
        body.append(input, tabs, results);
        surface.append(header, body);
        dialog.append(surface);
        document.body.append(dialog);

        let mode = 'chat';
        let debounceTimer = null;
        let pendingLocate = null;

        const showHint = (text) => {
            results.replaceChildren();
            const hint = document.createElement('div');
            hint.className = 'messenger-search-results__hint';
            hint.textContent = text;
            results.append(hint);
        };

        const updateTabs = () => {
            currentTab.setAttribute('aria-selected', mode === 'chat' ? 'true' : 'false');
            globalTab.setAttribute('aria-selected', mode === 'global' ? 'true' : 'false');
            headerSubtitle.textContent = mode === 'chat'
                ? `В чате «${app.currentDialog?.title || 'Диалог'}»`
                : 'По сообщениям и чатам';
        };

        const runSearch = () => {
            const query = input.value.trim();
            if (query.length < 2) {
                showHint('Введите минимум 2 символа');
                return;
            }
            if (mode === 'chat') {
                if (!app.currentDialog?.uid) {
                    showHint('Сначала выберите диалог');
                    return;
                }
                app.sendEvent('SearchSocket:messages', {
                    query,
                    dialog_uid: app.currentDialog.uid,
                    limit: 40
                });
            } else {
                app.sendEvent('SearchSocket:all', { query });
            }
        };

        const queueSearch = () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(runSearch, 280);
        };

        input.addEventListener('input', queueSearch);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(debounceTimer);
                runSearch();
            }
        });

        currentTab.addEventListener('click', () => {
            mode = 'chat';
            updateTabs();
            runSearch();
        });
        globalTab.addEventListener('click', () => {
            mode = 'global';
            updateTabs();
            runSearch();
        });

        searchButton.addEventListener('click', () => {
            if (!app.currentDialog) {
                app.showToast('Сначала выберите диалог');
                return;
            }
            mode = 'chat';
            input.value = '';
            updateTabs();
            showHint('Введите минимум 2 символа');
            if (!dialog.open) dialog.showModal();
            requestAnimationFrame(() => input.focus());
        });

        const resultAvatar = (title) => app.createAvatar(title || '?', 'messenger-avatar messenger-avatar--small');

        const sectionTitle = (text) => {
            const title = document.createElement('div');
            title.className = 'messenger-search-section-title';
            title.textContent = text;
            return title;
        };

        const openDialogResult = (dialogUid) => {
            if (!app.dialogMap.has(dialogUid)) {
                app.showToast('Диалог больше недоступен');
                return;
            }
            dialog.close();
            app.openDialog(dialogUid);
        };

        const locateMessage = (message) => {
            const messageId = Number(message?.id || 0);
            if (!message?.dialog_uid || !message?.uid || !messageId) return;
            if (!app.dialogMap.has(message.dialog_uid)) {
                app.showToast('Диалог больше недоступен');
                return;
            }

            pendingLocate = {
                dialog_uid: message.dialog_uid,
                message_uid: message.uid,
                message_id: messageId,
                targeted_load_requested: false
            };
            dialog.close();
            app.openDialog(message.dialog_uid);
        };

        const renderDialogResult = (item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'messenger-search-result';
            button.addEventListener('click', () => openDialogResult(item.uid));

            const avatar = resultAvatar(item.title || 'Диалог');
            const copy = document.createElement('span');
            copy.className = 'messenger-search-result__body';
            const title = document.createElement('strong');
            title.textContent = item.title || 'Диалог';
            const preview = document.createElement('span');
            preview.textContent = item.last_message_preview || 'Нет сообщений';
            copy.append(title, preview);
            const time = document.createElement('time');
            time.textContent = app.formatListTime(item.last_message_at || item.updated_at);
            button.append(avatar, copy, time);
            return button;
        };

        const renderMessageResult = (item, globalMode) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'messenger-search-result';
            button.addEventListener('click', () => locateMessage(item));

            const userName = app.displayUser(item.user);
            const avatar = resultAvatar(userName);
            const copy = document.createElement('span');
            copy.className = 'messenger-search-result__body';
            const title = document.createElement('strong');
            title.textContent = globalMode
                ? `${userName} · ${item.dialog_title || 'Диалог'}`
                : userName;
            const preview = document.createElement('span');
            preview.textContent = item.preview || 'Сообщение';
            copy.append(title, preview);
            const time = document.createElement('time');
            time.textContent = app.formatListTime(item.created_at);
            button.append(avatar, copy, time);
            return button;
        };

        const renderSearch = (dialogs, messages, globalMode) => {
            results.replaceChildren();
            const dialogItems = Array.isArray(dialogs) ? dialogs : [];
            const messageItems = Array.isArray(messages) ? messages : [];

            if (dialogItems.length === 0 && messageItems.length === 0) {
                showHint('Ничего не найдено');
                return;
            }

            if (globalMode && dialogItems.length > 0) {
                results.append(sectionTitle('Чаты'));
                dialogItems.forEach((item) => results.append(renderDialogResult(item)));
            }
            if (messageItems.length > 0) {
                results.append(sectionTitle(globalMode ? 'Сообщения' : 'Найденные сообщения'));
                messageItems.forEach((item) => results.append(renderMessageResult(item, globalMode)));
            }
        };

        const originalHandle = app.handleSocketMessage.bind(app);
        app.handleSocketMessage = (event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (_) {
                return originalHandle(event);
            }

            if (data?.action === 'search_messages') {
                if (mode === 'chat' && data.query === input.value.trim()) {
                    renderSearch([], data.messages, false);
                }
                return;
            }

            if (data?.action === 'search_all') {
                if (mode === 'global' && data.query === input.value.trim()) {
                    renderSearch(data.dialogs, data.messages, true);
                }
                return;
            }

            if (data?.action === 'get_messages') {
                const result = originalHandle(event);
                if (
                    pendingLocate
                    && pendingLocate.dialog_uid === data.dialog_uid
                    && app.currentDialog?.uid === data.dialog_uid
                ) {
                    if (app.messages.some((message) => message.uid === pendingLocate.message_uid)) {
                        const uid = pendingLocate.message_uid;
                        pendingLocate = null;
                        requestAnimationFrame(() => app.scrollToMessage(uid));
                    } else if (!pendingLocate.targeted_load_requested) {
                        pendingLocate.targeted_load_requested = true;
                        app.sendEvent('MessangerSocket:load', {
                            dialog_uid: pendingLocate.dialog_uid,
                            before_id: pendingLocate.message_id + 1
                        });
                    } else {
                        pendingLocate = null;
                        app.showToast('Сообщение больше недоступно');
                    }
                }
                return result;
            }

            return originalHandle(event);
        };
    });
})();
{/literal}
