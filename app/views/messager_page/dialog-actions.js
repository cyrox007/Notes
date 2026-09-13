{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const pinButton = document.getElementById('chat-pin-button');
        const muteButton = document.getElementById('chat-mute-button');
        const archiveButton = document.getElementById('chat-archive-button');
        const activeTab = document.getElementById('chat-folder-active');
        const archiveTab = document.getElementById('chat-folder-archive');
        const activeCount = document.getElementById('chat-folder-active-count');
        const archiveCount = document.getElementById('chat-folder-archive-count');
        const emptyTitle = document.getElementById('dialog-list-empty-title');
        const emptyText = document.getElementById('dialog-list-empty-text');

        if (!pinButton || !muteButton || !archiveButton || !activeTab || !archiveTab) return;

        const states = new Map();
        let folder = 'active';

        const stateFor = (dialogUid) => states.get(dialogUid) || {
            dialog_uid: dialogUid,
            pinned: false,
            pinned_at: null,
            archived: false,
            archived_at: null,
            muted: false,
            muted_until: null
        };

        const mergeState = (incoming) => {
            if (!incoming?.dialog_uid) return;
            states.set(incoming.dialog_uid, {
                ...stateFor(incoming.dialog_uid),
                ...incoming
            });
        };

        const requireDialog = () => {
            if (!app.currentDialog?.uid) {
                app.showToast('Сначала выберите диалог');
                return null;
            }
            return app.currentDialog.uid;
        };

        const updateHeaderActions = () => {
            const uid = app.currentDialog?.uid;
            const state = uid ? stateFor(uid) : stateFor('');

            pinButton.dataset.active = state.pinned ? 'true' : 'false';
            pinButton.title = state.pinned ? 'Открепить чат' : 'Закрепить чат';
            pinButton.setAttribute('aria-label', pinButton.title);

            muteButton.dataset.active = state.muted ? 'true' : 'false';
            muteButton.title = state.muted ? 'Включить уведомления' : 'Выключить уведомления на час';
            muteButton.setAttribute('aria-label', muteButton.title);

            archiveButton.dataset.active = state.archived ? 'true' : 'false';
            archiveButton.title = state.archived ? 'Вернуть чат из архива' : 'Архивировать чат';
            archiveButton.setAttribute('aria-label', archiveButton.title);
        };

        const updateFolderCounters = (dialogs = app.dialogs) => {
            let active = 0;
            let archived = 0;
            dialogs.forEach((dialog) => {
                if (stateFor(dialog.uid).archived) archived += 1;
                else active += 1;
            });
            if (activeCount) activeCount.textContent = String(active);
            if (archiveCount) archiveCount.textContent = String(archived);
        };

        const updateFolderUi = () => {
            const archiveMode = folder === 'archive';
            activeTab.setAttribute('aria-selected', archiveMode ? 'false' : 'true');
            archiveTab.setAttribute('aria-selected', archiveMode ? 'true' : 'false');
            if (emptyTitle) emptyTitle.textContent = archiveMode ? 'Архив пуст' : 'Диалогов пока нет';
            if (emptyText) {
                emptyText.textContent = archiveMode
                    ? 'Архивированные чаты появятся здесь.'
                    : 'Создайте первый чат с коллегой.';
            }
        };

        const decorateDialogs = (visibleDialogs) => {
            const nodes = Array.from(app.el.dialogList.querySelectorAll('.messenger-dialog-item'));
            visibleDialogs.forEach((dialog, index) => {
                const node = nodes[index];
                if (!node) return;
                node.dataset.dialogUid = dialog.uid;

                const state = stateFor(dialog.uid);
                const bottom = node.querySelector('.messenger-dialog-item__bottom');
                if (!bottom) return;

                const icons = document.createElement('span');
                icons.className = 'messenger-dialog-state-icons';

                if (state.pinned) {
                    const pin = document.createElement('i');
                    pin.className = 'fa fa-thumb-tack';
                    pin.title = 'Закреплён';
                    icons.append(pin);
                }
                if (state.muted) {
                    const mute = document.createElement('i');
                    mute.className = 'fa fa-bell-slash-o';
                    mute.title = 'Уведомления выключены';
                    icons.append(mute);
                }

                if (icons.childNodes.length) {
                    const unread = bottom.querySelector('.messenger-dialog-item__unread');
                    bottom.insertBefore(icons, unread || null);
                }
            });
        };

        const originalRenderDialogs = app.renderDialogs.bind(app);
        app.renderDialogs = () => {
            const allDialogs = app.dialogs;
            const visibleDialogs = allDialogs
                .filter((dialog) => stateFor(dialog.uid).archived === (folder === 'archive'))
                .sort((left, right) => {
                    const leftState = stateFor(left.uid);
                    const rightState = stateFor(right.uid);
                    if (leftState.pinned !== rightState.pinned) return rightState.pinned ? 1 : -1;
                    if (leftState.pinned && rightState.pinned) {
                        return String(rightState.pinned_at || '').localeCompare(String(leftState.pinned_at || ''));
                    }
                    return 0;
                });

            app.dialogs = visibleDialogs;
            try {
                originalRenderDialogs();
            } finally {
                app.dialogs = allDialogs;
            }

            decorateDialogs(visibleDialogs);
            updateFolderCounters(allDialogs);
            updateFolderUi();
        };

        const originalRenderChatHeader = app.renderChatHeader.bind(app);
        app.renderChatHeader = () => {
            originalRenderChatHeader();
            updateHeaderActions();
        };

        activeTab.addEventListener('click', () => {
            folder = 'active';
            app.renderDialogs();
        });

        archiveTab.addEventListener('click', () => {
            folder = 'archive';
            app.renderDialogs();
        });

        pinButton.addEventListener('click', () => {
            const dialogUid = requireDialog();
            if (!dialogUid) return;
            app.sendEvent('DialogStateSocket:pin', { dialog_uid: dialogUid });
        });

        muteButton.addEventListener('click', () => {
            const dialogUid = requireDialog();
            if (!dialogUid) return;
            app.sendEvent('DialogStateSocket:mute', { dialog_uid: dialogUid });
        });

        archiveButton.addEventListener('click', () => {
            const dialogUid = requireDialog();
            if (!dialogUid) return;
            app.sendEvent('DialogStateSocket:archive', { dialog_uid: dialogUid });
        });

        const originalHandle = app.handleSocketMessage.bind(app);
        app.handleSocketMessage = (event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (_) {
                return originalHandle(event);
            }

            if (data?.action === 'Authorized') {
                const result = originalHandle(event);
                app.sendEvent('DialogStateSocket:list', {});
                return result;
            }

            if (data?.action === 'dialog_states') {
                states.clear();
                (Array.isArray(data.states) ? data.states : []).forEach(mergeState);
                app.renderDialogs();
                updateHeaderActions();
                return;
            }

            if (data?.action === 'dialog_state' && data.dialog_uid) {
                mergeState(data);
                app.renderDialogs();
                updateHeaderActions();

                if (data.state === 'pinned') {
                    app.showToast(data.pinned ? 'Чат закреплён' : 'Чат откреплён');
                } else if (data.state === 'muted') {
                    app.showToast(data.muted ? 'Уведомления отключены на час' : 'Уведомления включены');
                } else if (data.state === 'archived') {
                    app.showToast(data.archived ? 'Чат перемещён в архив' : 'Чат возвращён из архива');
                }
                return;
            }

            return originalHandle(event);
        };

        updateFolderUi();
        updateHeaderActions();
    });
})();
{/literal}
