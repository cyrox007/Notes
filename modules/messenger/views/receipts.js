{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const deliveredCursors = new Map();

        const originalOpenDialog = app.openDialog.bind(app);
        app.openDialog = (uid) => {
            app.readCursors.clear();
            deliveredCursors.clear();
            return originalOpenDialog(uid);
        };

        const receiptStateForMessage = (message) => {
            if (!app.currentDialog || message.user?.uid !== app.userUid) {
                return 'sent';
            }

            const messageId = Number(message.id || 0);
            const others = (app.currentDialog.participants || [])
                .filter((member) => member.uid && member.uid !== app.userUid);

            const read = others.some((member) =>
                Number(app.readCursors.get(member.uid) || 0) >= messageId
            );
            if (read) return 'read';

            const delivered = others.some((member) =>
                Number(deliveredCursors.get(member.uid) || 0) >= messageId
            );
            return delivered ? 'delivered' : 'sent';
        };

        const originalRenderMessage = app.renderMessage.bind(app);
        app.renderMessage = (message) => {
            const row = originalRenderMessage(message);
            if (message.user?.uid !== app.userUid) return row;

            const status = row.querySelector('.messenger-message__status');
            if (!status) return row;

            const state = receiptStateForMessage(message);
            status.dataset.state = state;
            status.textContent = state === 'sent' ? '✓' : '✓✓';
            status.title = state === 'read'
                ? 'Прочитано'
                : state === 'delivered'
                    ? 'Доставлено'
                    : 'Отправлено';
            status.setAttribute('aria-label', status.title);
            return row;
        };

        const requestReceiptState = (dialogUid) => {
            if (!dialogUid) return;
            app.sendEvent('ReceiptSocket:list', { dialog_uid: dialogUid });
        };

        const acknowledgeLatestDelivered = (dialogUid, messages) => {
            if (!dialogUid || !Array.isArray(messages) || messages.length === 0) return;

            for (let index = messages.length - 1; index >= 0; index -= 1) {
                const message = messages[index];
                if (message?.uid && message.user?.uid !== app.userUid) {
                    app.sendEvent('ReceiptSocket:delivered', {
                        dialog_uid: dialogUid,
                        message_uid: message.uid
                    });
                    return;
                }
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

            if (data?.action === 'receipt_states') {
                if (!app.currentDialog || data.dialog_uid !== app.currentDialog.uid) return;

                deliveredCursors.clear();
                app.readCursors.clear();
                (Array.isArray(data.receipts) ? data.receipts : []).forEach((receipt) => {
                    if (!receipt?.user_uid) return;
                    deliveredCursors.set(
                        receipt.user_uid,
                        Number(receipt.last_delivered_message_id || 0)
                    );
                    app.readCursors.set(
                        receipt.user_uid,
                        Number(receipt.last_read_message_id || 0)
                    );
                });
                app.renderMessages();
                return;
            }

            if (data?.action === 'delivered_update') {
                if (!data.user_uid) return;
                const next = Number(data.last_delivered_message_id || 0);
                const previous = Number(deliveredCursors.get(data.user_uid) || 0);
                deliveredCursors.set(data.user_uid, Math.max(previous, next));
                if (app.currentDialog?.uid === data.dialog_uid) {
                    app.renderMessages();
                }
                return;
            }

            if (data?.action === 'get_messages') {
                const result = originalHandle(event);
                if (app.currentDialog?.uid === data.dialog_uid) {
                    requestReceiptState(data.dialog_uid);
                    acknowledgeLatestDelivered(data.dialog_uid, data.messages);
                }
                return result;
            }

            if (data?.action === 'send_message') {
                const result = originalHandle(event);
                const message = data.message;
                if (
                    app.currentDialog?.uid === data.dialog_uid &&
                    message?.uid &&
                    message.user?.uid !== app.userUid
                ) {
                    app.sendEvent('ReceiptSocket:delivered', {
                        dialog_uid: data.dialog_uid,
                        message_uid: message.uid
                    });
                }
                return result;
            }

            if (data?.action === 'read_update') {
                const result = originalHandle(event);
                if (data.user_uid) {
                    const read = Number(data.last_read_message_id || 0);
                    const delivered = Number(deliveredCursors.get(data.user_uid) || 0);
                    deliveredCursors.set(data.user_uid, Math.max(delivered, read));
                }
                if (app.currentDialog?.uid === data.dialog_uid) {
                    app.renderMessages();
                }
                return result;
            }

            return originalHandle(event);
        };
    });
})();
{/literal}
