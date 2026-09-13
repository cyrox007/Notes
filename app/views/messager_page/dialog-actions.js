{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const pinButton = document.getElementById('chat-pin-button');
        const muteButton = document.getElementById('chat-mute-button');
        if (!pinButton || !muteButton) return;

        const requireDialog = () => {
            if (!app.currentDialog?.uid) {
                app.showToast('Сначала выберите диалог');
                return null;
            }
            return app.currentDialog.uid;
        };

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

        const originalHandle = app.handleSocketMessage.bind(app);
        app.handleSocketMessage = (event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (_) {
                return originalHandle(event);
            }

            if (data?.action === 'dialog_state' && data.dialog_uid === app.currentDialog?.uid) {
                if (data.state === 'pinned') {
                    pinButton.dataset.active = data.pinned ? 'true' : 'false';
                    pinButton.title = data.pinned ? 'Открепить чат' : 'Закрепить чат';
                    pinButton.setAttribute('aria-label', pinButton.title);
                    app.showToast(data.pinned ? 'Чат закреплён' : 'Чат откреплён');
                    app.sendEvent('MessangerSocket:get_dialogs', {});
                }

                if (data.state === 'muted') {
                    muteButton.dataset.active = data.muted ? 'true' : 'false';
                    muteButton.title = data.muted ? 'Включить уведомления' : 'Выключить уведомления на час';
                    muteButton.setAttribute('aria-label', muteButton.title);
                    app.showToast(data.muted ? 'Уведомления отключены на час' : 'Уведомления включены');
                }
            }

            return originalHandle(event);
        };
    });
})();
{/literal}
