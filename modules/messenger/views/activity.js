{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const ACTIVITY_TTL_MS = 5000;
        const labels = {
            typing: 'печатает…',
            recording_voice: 'записывает голосовое…',
            recording_video: 'записывает видеосообщение…',
            uploading_image: 'отправляет изображение…',
            uploading_voice: 'отправляет голосовое…',
            uploading_audio: 'отправляет музыку/аудио…',
            uploading_video: 'отправляет видео…',
            uploading_document: 'отправляет документ…',
            uploading_file: 'отправляет файл…'
        };

        const remoteActivities = new Map();
        const localActivities = new Map();
        let sequence = 0;

        const keyFor = (dialogUid, userUid, activity) => `${dialogUid}:${userUid}:${activity}`;
        const localKeyFor = (dialogUid, activity) => `${dialogUid}:${activity}`;

        const participantName = (userUid) => {
            const participant = (app.currentDialog?.participants || []).find((item) => item.uid === userUid);
            return app.displayUser(participant);
        };

        const renderRemoteActivity = () => {
            if (!app.el?.typingIndicator || !app.el?.typingText || !app.currentDialog?.uid) return;
            const now = Date.now();
            const matching = [];

            for (const [key, state] of remoteActivities.entries()) {
                if (state.expiresAt <= now) {
                    window.clearTimeout(state.timerId);
                    remoteActivities.delete(key);
                    continue;
                }
                if (state.dialogUid === app.currentDialog.uid && state.userUid !== app.userUid) {
                    matching.push(state);
                }
            }

            if (matching.length === 0) {
                app.el.typingIndicator.hidden = true;
                app.el.typingText.textContent = 'печатает…';
                return;
            }

            matching.sort((a, b) => b.sequence - a.sequence);
            const state = matching[0];
            app.el.typingIndicator.hidden = false;
            app.el.typingText.textContent = `${participantName(state.userUid)} ${labels[state.activity] || 'активен…'}`;
        };

        const clearDialogActivities = (dialogUid) => {
            if (!dialogUid) return;
            for (const [key, state] of remoteActivities.entries()) {
                if (state.dialogUid !== dialogUid) continue;
                window.clearTimeout(state.timerId);
                remoteActivities.delete(key);
            }
            renderRemoteActivity();
        };

        app.receiveActivity = (data) => {
            const dialogUid = String(data?.dialog_uid || '');
            const userUid = String(data?.user_uid || '');
            const activity = String(data?.activity || '');
            const active = Boolean(data?.active);
            if (!dialogUid || !userUid || !Object.hasOwn(labels, activity) || userUid === app.userUid) return;

            const key = keyFor(dialogUid, userUid, activity);
            const previous = remoteActivities.get(key);
            if (previous?.timerId) window.clearTimeout(previous.timerId);

            if (!active) {
                remoteActivities.delete(key);
                renderRemoteActivity();
                return;
            }

            const state = {
                dialogUid,
                userUid,
                activity,
                sequence: ++sequence,
                expiresAt: Date.now() + ACTIVITY_TTL_MS,
                timerId: null
            };
            state.timerId = window.setTimeout(() => {
                const current = remoteActivities.get(key);
                if (current === state) {
                    remoteActivities.delete(key);
                    renderRemoteActivity();
                }
            }, ACTIVITY_TTL_MS + 50);
            remoteActivities.set(key, state);
            renderRemoteActivity();
        };

        app.sendActivity = (activity, active, dialogUid = app.currentDialog?.uid || '') => {
            const uid = String(dialogUid || '');
            if (!uid || !Object.hasOwn(labels, activity)) return false;
            if (!app.socket || app.socket.readyState !== WebSocket.OPEN) return false;
            app.socket.send(JSON.stringify({
                action: 'MessangerSocket:activity',
                data: { dialog_uid: uid, activity, active: Boolean(active) }
            }));
            return true;
        };

        app.setLocalActivity = (activity, active, dialogUid = app.currentDialog?.uid || '') => {
            const uid = String(dialogUid || '');
            if (!uid || !Object.hasOwn(labels, activity)) return false;
            const key = localKeyFor(uid, activity);
            const previous = localActivities.get(key) === true;
            if (active && previous) return true;
            if (!active && !previous) return true;

            const sent = app.sendActivity(activity, active, uid);
            if (sent) {
                if (active) localActivities.set(key, true);
                else localActivities.delete(key);
            }
            return sent;
        };

        app.clearLocalActivities = (dialogUid = app.currentDialog?.uid || '') => {
            const uid = String(dialogUid || '');
            if (!uid) return;
            for (const key of Array.from(localActivities.keys())) {
                if (!key.startsWith(`${uid}:`)) continue;
                const activity = key.slice(uid.length + 1);
                app.sendActivity(activity, false, uid);
                localActivities.delete(key);
            }
        };

        const originalHandleSocketMessage = app.handleSocketMessage.bind(app);
        app.handleSocketMessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                if (data?.action === 'activity') {
                    app.receiveActivity(data);
                    return;
                }
            } catch (_) {
                // Let the canonical handler report malformed payloads consistently.
            }
            originalHandleSocketMessage(event);
        };

        // Replace the legacy two-action typing path with the unified activity
        // protocol. Legacy receive handlers stay in script.js for mixed-version
        // compatibility during rolling upgrades.
        app.notifyTyping = () => {
            if (!app.currentDialog?.uid) return;
            const dialogUid = app.currentDialog.uid;
            app.typingSent = app.setLocalActivity('typing', true, dialogUid);
            window.clearTimeout(app.typingTimer);
            app.typingTimer = window.setTimeout(() => app.stopTyping(), 1400);
        };

        app.stopTyping = () => {
            window.clearTimeout(app.typingTimer);
            if (app.typingSent && app.currentDialog?.uid) {
                app.setLocalActivity('typing', false, app.currentDialog.uid);
            }
            app.typingSent = false;
        };

        const originalOpenDialog = app.openDialog.bind(app);
        app.openDialog = (uid) => {
            const previousUid = app.currentDialog?.uid || '';
            if (previousUid && previousUid !== uid) {
                app.clearLocalActivities(previousUid);
                clearDialogActivities(previousUid);
            }
            const result = originalOpenDialog(uid);
            renderRemoteActivity();
            return result;
        };

        const attachSocketCleanup = (socket) => {
            if (!socket || socket.datasetActivityCleanup) return;
            socket.datasetActivityCleanup = true;
            socket.addEventListener('close', () => {
                localActivities.clear();
                for (const state of remoteActivities.values()) {
                    if (state.timerId) window.clearTimeout(state.timerId);
                }
                remoteActivities.clear();
                renderRemoteActivity();
            });
        };

        attachSocketCleanup(app.socket);
        const originalConnect = app.connect.bind(app);
        app.connect = () => {
            const result = originalConnect();
            attachSocketCleanup(app.socket);
            return result;
        };

        app.activityLabels = Object.freeze({ ...labels });
    });
})();
{/literal}
