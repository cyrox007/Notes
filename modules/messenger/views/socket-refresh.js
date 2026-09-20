{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app || typeof app.connect !== 'function') {
            return;
        }

        const initialConnect = app.connect.bind(app);
        let firstConnectionAlreadyStarted = true;
        let refreshPromise = null;
        let sessionUnavailable = false;

        async function refreshTicket() {
            if (sessionUnavailable) {
                return false;
            }

            if (refreshPromise) {
                return refreshPromise;
            }

            refreshPromise = (async () => {
                try {
                    const response = await fetch('/messenger/socket-ticket', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json'
                        }
                    });

                    if (response.status === 401 || response.status === 403) {
                        sessionUnavailable = true;
                        app.setConnectionState?.('offline', 'Сессия завершена');
                        return false;
                    }

                    if (!response.ok) {
                        throw new Error(`Ticket refresh failed with HTTP ${response.status}`);
                    }

                    const data = await response.json();
                    if (data.status !== 'ok' || typeof data.ticket !== 'string' || data.ticket === '') {
                        throw new Error('Ticket refresh returned an invalid payload');
                    }

                    window.wspace.socketConfig = window.wspace.socketConfig || {};
                    window.wspace.socketConfig.ticket = data.ticket;
                    return true;
                } catch (error) {
                    console.warn('WebSocket ticket refresh failed', error);
                    app.setConnectionState?.('offline', 'Не удалось обновить соединение');
                    return false;
                } finally {
                    refreshPromise = null;
                }
            })();

            return refreshPromise;
        }

        app.connect = async function reconnectWithFreshTicket() {
            // The main messenger listener starts the first connection before this
            // extension is installed. Every later connection gets a fresh ticket.
            if (firstConnectionAlreadyStarted) {
                firstConnectionAlreadyStarted = false;
                return;
            }

            const refreshed = await refreshTicket();
            if (!refreshed) {
                if (!sessionUnavailable) {
                    this.scheduleReconnect();
                }
                return;
            }

            initialConnect();
        };

        // The first connection is already in flight at this point. Mark the next
        // invocation as a reconnect immediately.
        firstConnectionAlreadyStarted = false;
    });
})();
{/literal}
