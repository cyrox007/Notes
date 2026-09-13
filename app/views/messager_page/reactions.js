{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const allowed = [
            ['like', '👍'],
            ['heart', '❤️'],
            ['laugh', '😂'],
            ['wow', '😮'],
            ['sad', '😢'],
            ['fire', '🔥'],
        ];
        const states = new Map();
        let openPicker = null;

        const setState = (messageUid, reactions) => {
            if (!messageUid) return;
            states.set(messageUid, Array.isArray(reactions) ? reactions : []);
        };

        const requestStates = (messageUids) => {
            const uids = Array.from(new Set((messageUids || []).filter(Boolean))).slice(0, 100);
            if (uids.length === 0) return;
            app.sendEvent('ReactionSocket:list', { message_uids: uids });
        };

        const closePicker = () => {
            if (openPicker) {
                openPicker.hidden = true;
                openPicker = null;
            }
        };

        document.addEventListener('click', (event) => {
            if (!openPicker || openPicker.contains(event.target)) return;
            closePicker();
        });

        const reactionChips = (message) => {
            const reactions = states.get(message.uid) || [];
            if (reactions.length === 0) return null;

            const container = document.createElement('div');
            container.className = 'messenger-reactions';
            container.setAttribute('aria-label', 'Реакции на сообщение');

            reactions.forEach((reaction) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'messenger-reaction-chip';
                if (reaction.reacted_by_me) button.dataset.active = 'true';
                button.dataset.reaction = reaction.code || '';
                button.title = reaction.reacted_by_me ? 'Убрать реакцию' : 'Добавить реакцию';
                button.setAttribute('aria-label', `${button.title}: ${reaction.emoji || ''}`);

                const emoji = document.createElement('span');
                emoji.className = 'messenger-reaction-chip__emoji';
                emoji.textContent = reaction.emoji || '';
                const count = document.createElement('span');
                count.className = 'messenger-reaction-chip__count';
                count.textContent = String(Number(reaction.count || 0));
                button.append(emoji, count);
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    app.sendEvent('ReactionSocket:toggle', {
                        message_uid: message.uid,
                        reaction_code: reaction.code,
                    });
                });
                container.append(button);
            });
            return container;
        };

        const reactionPicker = (message) => {
            const picker = document.createElement('div');
            picker.className = 'messenger-reaction-picker';
            picker.hidden = true;
            picker.setAttribute('role', 'menu');
            picker.setAttribute('aria-label', 'Выберите реакцию');

            allowed.forEach(([code, emoji]) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'messenger-reaction-picker__item';
                button.textContent = emoji;
                button.dataset.reaction = code;
                button.setAttribute('role', 'menuitem');
                button.setAttribute('aria-label', `Реакция ${emoji}`);
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    closePicker();
                    app.sendEvent('ReactionSocket:toggle', {
                        message_uid: message.uid,
                        reaction_code: code,
                    });
                });
                picker.append(button);
            });
            return picker;
        };

        const originalRenderMessage = app.renderMessage.bind(app);
        app.renderMessage = (message) => {
            const row = originalRenderMessage(message);
            if (!message?.uid) return row;

            const bubble = row.querySelector('.messenger-message__bubble');
            const actions = row.querySelector('.messenger-message__actions');
            if (!bubble || !actions) return row;

            const chips = reactionChips(message);
            if (chips) bubble.append(chips);

            const picker = reactionPicker(message);
            row.append(picker);

            const reactionAction = app.messageAction('Реакция', 'fa-smile-o', (event) => {
                event?.stopPropagation?.();
                if (openPicker && openPicker !== picker) closePicker();
                picker.hidden = !picker.hidden;
                openPicker = picker.hidden ? null : picker;
            });
            reactionAction.classList.add('messenger-message__reaction-action');
            reactionAction.addEventListener('click', (event) => event.stopPropagation());
            actions.insertBefore(reactionAction, actions.firstChild);
            return row;
        };

        const originalHandle = app.handleSocketMessage.bind(app);
        app.handleSocketMessage = (event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (_) {
                return originalHandle(event);
            }

            if (data?.action === 'reaction_state') {
                const payload = data.reactions && typeof data.reactions === 'object'
                    ? data.reactions
                    : {};
                Object.entries(payload).forEach(([uid, reactions]) => setState(uid, reactions));
                if (app.currentDialog) app.renderMessages();
                return;
            }

            if (data?.action === 'reaction_update' && data.message_uid) {
                setState(data.message_uid, data.reactions);
                if (
                    app.currentDialog?.uid === data.dialog_uid
                    && app.messages.some((message) => message.uid === data.message_uid)
                ) {
                    app.renderMessages();
                }
                return;
            }

            if (data?.action === 'get_messages') {
                const result = originalHandle(event);
                requestStates((data.messages || []).map((message) => message?.uid));
                return result;
            }

            if (data?.action === 'send_message' && data.message?.uid) {
                const result = originalHandle(event);
                setState(data.message.uid, []);
                requestStates([data.message.uid]);
                return result;
            }

            if (data?.action === 'delete_message' && data.message_uid) {
                states.delete(data.message_uid);
                return originalHandle(event);
            }

            return originalHandle(event);
        };

        app.reactionStates = states;
    });
})();
{/literal}
