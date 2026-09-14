{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;
        const el = {
            infoButton: document.getElementById('chat-group-button'),
            dialog: document.getElementById('group-info-dialog'),
            loading: document.getElementById('group-loading'),
            content: document.getElementById('group-content'),
            avatar: document.getElementById('group-summary-avatar'),
            title: document.getElementById('group-summary-title'),
            summary: document.getElementById('group-summary-text'),
            currentRole: document.getElementById('group-current-role'),
            nameInput: document.getElementById('group-name-input'),
            saveName: document.getElementById('group-save-name'),
            members: document.getElementById('group-member-list'),
            addSection: document.getElementById('group-add-section'),
            addList: document.getElementById('group-add-contact-list'),
            addButton: document.getElementById('group-add-button'),
            leaveButton: document.getElementById('group-leave-button')
        };

        if (!el.infoButton || !el.dialog) return;

        let group = null;
        let pendingOpen = false;
        let avatarUploading = false;

        const roleLabel = (role) => ({
            owner: 'Владелец',
            admin: 'Администратор',
            member: 'Участник'
        }[role] || 'Участник');

        const groupAvatarUrl = (dialogUid, token) => {
            if (!dialogUid || !token) return '';
            return appPath(`/messenger/group-avatar/${encodeURIComponent(dialogUid)}?v=${encodeURIComponent(String(token))}`);
        };

        const renderProtectedAvatar = (element, title, dialogUid, token) => {
            if (!element) return;
            app.setAvatar(element, title || '?');
            const url = groupAvatarUrl(dialogUid, token);
            if (!url) return;

            const image = document.createElement('img');
            image.className = 'messenger-avatar__image';
            image.src = url;
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('error', () => image.remove(), { once: true });
            element.append(image);
        };

        const decorateDialogAvatars = () => {
            app.el.dialogList?.querySelectorAll('.messenger-dialog-item[data-dialog-uid]').forEach((node) => {
                const uid = node.dataset.dialogUid || '';
                const dialog = app.dialogMap.get(uid);
                if (!dialog || dialog.type !== 'group') return;
                const avatar = node.querySelector('.messenger-avatar');
                renderProtectedAvatar(avatar, dialog.title || 'Группа', dialog.uid, dialog.avatar);
            });
        };

        const originalRenderDialogs = app.renderDialogs.bind(app);
        app.renderDialogs = () => {
            const result = originalRenderDialogs();
            decorateDialogAvatars();
            return result;
        };

        const syncGroupButton = () => {
            const isGroup = app.currentDialog?.type === 'group';
            el.infoButton.hidden = !isGroup;
            if (isGroup) {
                renderProtectedAvatar(
                    app.el.chatAvatar,
                    app.currentDialog.title || 'Группа',
                    app.currentDialog.uid,
                    app.currentDialog.avatar
                );
            }
        };

        const originalRenderHeader = app.renderChatHeader.bind(app);
        app.renderChatHeader = () => {
            const result = originalRenderHeader();
            syncGroupButton();
            return result;
        };
        syncGroupButton();

        const requestInfo = (openAfter = false) => {
            if (!app.currentDialog || app.currentDialog.type !== 'group') return;
            pendingOpen = pendingOpen || openAfter;
            if (openAfter) {
                el.loading.hidden = false;
                el.content.hidden = true;
                if (!el.dialog.open) el.dialog.showModal();
            }
            app.sendEvent('GroupSocket:info', { dialog_uid: app.currentDialog.uid });
        };

        el.infoButton.addEventListener('click', () => requestInfo(true));

        const avatarInput = document.createElement('input');
        avatarInput.type = 'file';
        avatarInput.accept = 'image/jpeg,image/png,image/webp';
        avatarInput.hidden = true;

        const avatarActions = document.createElement('div');
        avatarActions.className = 'messenger-group-avatar-actions';
        const avatarChange = document.createElement('button');
        avatarChange.type = 'button';
        avatarChange.className = 'messenger-group-action';
        avatarChange.textContent = 'Изменить фото';
        const avatarRemove = document.createElement('button');
        avatarRemove.type = 'button';
        avatarRemove.className = 'messenger-group-action messenger-group-action--danger';
        avatarRemove.textContent = 'Удалить фото';
        avatarActions.append(avatarChange, avatarRemove, avatarInput);
        el.currentRole?.parentElement?.append(avatarActions);

        const applyAvatarLocally = (token) => {
            if (!group) return;
            group.avatar = token || null;
            if (app.currentDialog?.uid === group.dialog_uid) {
                app.currentDialog = { ...app.currentDialog, avatar: group.avatar };
                app.dialogMap.set(group.dialog_uid, app.currentDialog);
            }
            const dialog = app.dialogMap.get(group.dialog_uid);
            if (dialog) {
                dialog.avatar = group.avatar;
                app.dialogMap.set(group.dialog_uid, dialog);
            }
            renderGroup();
            app.renderDialogs();
            app.renderChatHeader();
        };

        const uploadAvatar = async (file) => {
            if (!group || !file || avatarUploading) return;
            avatarUploading = true;
            avatarChange.disabled = true;
            avatarRemove.disabled = true;
            const form = new FormData();
            form.append('avatar', file, file.name || 'avatar');

            try {
                const response = await fetch(`/messenger/group-avatar/${encodeURIComponent(group.dialog_uid)}`, {
                    method: 'POST',
                    body: form
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success || !data.avatar) {
                    throw new Error(data.message || 'Не удалось загрузить аватар группы');
                }
                applyAvatarLocally(data.avatar.avatar || null);
                app.sendEvent('GroupSocket:refresh', { dialog_uid: group.dialog_uid });
                app.showToast('Аватар группы обновлён');
            } catch (error) {
                console.error(error);
                app.showToast(error?.message || 'Ошибка загрузки аватара');
            } finally {
                avatarUploading = false;
                avatarInput.value = '';
                renderGroup();
            }
        };

        avatarChange.addEventListener('click', () => {
            if (!group || avatarUploading) return;
            avatarInput.click();
        });
        avatarInput.addEventListener('change', () => {
            const file = avatarInput.files?.[0];
            if (file) uploadAvatar(file);
        });
        avatarRemove.addEventListener('click', async () => {
            if (!group || !group.avatar || avatarUploading) return;
            if (!window.confirm('Удалить аватар группы?')) return;
            avatarUploading = true;
            avatarChange.disabled = true;
            avatarRemove.disabled = true;
            try {
                const response = await fetch(
                    `/messenger/group-avatar/${encodeURIComponent(group.dialog_uid)}/delete`,
                    { method: 'POST' }
                );
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Не удалось удалить аватар группы');
                }
                applyAvatarLocally(null);
                app.sendEvent('GroupSocket:refresh', { dialog_uid: group.dialog_uid });
                app.showToast('Аватар группы удалён');
            } catch (error) {
                console.error(error);
                app.showToast(error?.message || 'Ошибка удаления аватара');
            } finally {
                avatarUploading = false;
                renderGroup();
            }
        });

        el.saveName?.addEventListener('click', () => {
            if (!group) return;
            const name = (el.nameInput?.value || '').trim();
            if (!name) {
                app.showToast('Введите название группы');
                return;
            }
            app.sendEvent('GroupSocket:rename', {
                dialog_uid: group.dialog_uid,
                name
            });
        });

        el.addButton?.addEventListener('click', () => {
            if (!group || !el.addList) return;
            const selected = Array.from(
                el.addList.querySelectorAll('.messenger-group-add-checkbox:checked:not([disabled])')
            ).map((input) => input.value);
            if (selected.length === 0) {
                app.showToast('Выберите пользователей для добавления');
                return;
            }
            app.sendEvent('GroupSocket:add_members', {
                dialog_uid: group.dialog_uid,
                member_uids: selected
            });
        });

        el.leaveButton?.addEventListener('click', () => {
            if (!group) return;
            if (!window.confirm('Выйти из этой группы?')) return;
            app.sendEvent('GroupSocket:leave', { dialog_uid: group.dialog_uid });
        });

        const memberAction = (label, handler, danger = false) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `messenger-group-action${danger ? ' messenger-group-action--danger' : ''}`;
            button.textContent = label;
            button.addEventListener('click', handler);
            return button;
        };

        const renderMembers = () => {
            if (!el.members || !group) return;
            el.members.replaceChildren();

            (group.members || []).forEach((member) => {
                const row = document.createElement('div');
                row.className = 'messenger-group-member';

                const avatar = app.createAvatar(app.displayUser(member), 'messenger-avatar messenger-avatar--small');
                const identity = document.createElement('div');
                identity.className = 'messenger-group-member__identity';
                const name = document.createElement('strong');
                name.textContent = app.displayUser(member);
                const meta = document.createElement('small');
                meta.textContent = `@${member.username || 'user'} · ${roleLabel(member.role)}`;
                identity.append(name, meta);

                const actions = document.createElement('div');
                actions.className = 'messenger-group-member__actions';
                const isSelf = member.uid === app.userUid;

                if (!isSelf && group.current_role === 'owner' && member.role !== 'owner') {
                    actions.append(memberAction(
                        member.role === 'admin' ? 'Снять админа' : 'Сделать админом',
                        () => app.sendEvent('GroupSocket:set_role', {
                            dialog_uid: group.dialog_uid,
                            member_uid: member.uid,
                            role: member.role === 'admin' ? 'member' : 'admin'
                        })
                    ));
                    actions.append(memberAction('Передать группу', () => {
                        if (!window.confirm(`Передать права владельца пользователю ${app.displayUser(member)}?`)) return;
                        app.sendEvent('GroupSocket:transfer_owner', {
                            dialog_uid: group.dialog_uid,
                            member_uid: member.uid
                        });
                    }));
                    actions.append(memberAction('Удалить', () => {
                        if (!window.confirm(`Удалить ${app.displayUser(member)} из группы?`)) return;
                        app.sendEvent('GroupSocket:remove_member', {
                            dialog_uid: group.dialog_uid,
                            member_uid: member.uid
                        });
                    }, true));
                } else if (!isSelf && group.current_role === 'admin' && member.role === 'member') {
                    actions.append(memberAction('Удалить', () => {
                        if (!window.confirm(`Удалить ${app.displayUser(member)} из группы?`)) return;
                        app.sendEvent('GroupSocket:remove_member', {
                            dialog_uid: group.dialog_uid,
                            member_uid: member.uid
                        });
                    }, true));
                } else {
                    const role = document.createElement('span');
                    role.className = 'messenger-group-role';
                    role.dataset.role = member.role || 'member';
                    role.textContent = isSelf ? `${roleLabel(member.role)} · вы` : roleLabel(member.role);
                    actions.append(role);
                }

                row.append(avatar, identity, actions);
                el.members.append(row);
            });
        };

        const renderAddCandidates = () => {
            if (!el.addList || !group) return;
            const memberUids = new Set((group.members || []).map((member) => member.uid));
            let visible = 0;
            el.addList.querySelectorAll('.messenger-group-add-contact').forEach((contact) => {
                const uid = contact.dataset.contactUid || '';
                const checkbox = contact.querySelector('.messenger-group-add-checkbox');
                const hidden = !uid || memberUids.has(uid);
                contact.hidden = hidden;
                if (checkbox) {
                    checkbox.checked = false;
                    checkbox.disabled = hidden;
                }
                if (!hidden) visible += 1;
            });
            el.addButton.disabled = visible === 0;
        };

        function renderGroup() {
            if (!group) return;
            const canManage = group.current_role === 'owner' || group.current_role === 'admin';

            el.loading.hidden = true;
            el.content.hidden = false;
            el.title.textContent = group.name || 'Групповой чат';
            el.summary.textContent = `${(group.members || []).length} участников`;
            renderProtectedAvatar(el.avatar, group.name || 'Группа', group.dialog_uid, group.avatar);
            el.currentRole.dataset.role = group.current_role || 'member';
            el.currentRole.textContent = roleLabel(group.current_role);
            el.nameInput.value = group.name || '';
            el.nameInput.disabled = !canManage;
            el.saveName.hidden = !canManage;
            el.addSection.hidden = !canManage;
            el.leaveButton.hidden = group.current_role === 'owner';
            avatarActions.hidden = !canManage;
            avatarChange.disabled = avatarUploading;
            avatarRemove.disabled = avatarUploading || !group.avatar;
            avatarRemove.hidden = !group.avatar;

            renderMembers();
            renderAddCandidates();
        }

        const closeRemovedGroup = (dialogUid, message) => {
            if (app.currentDialog?.uid === dialogUid) {
                app.currentDialog = null;
                app.messages = [];
                app.el.chatActive.hidden = true;
                app.el.chatEmpty.hidden = false;
                app.root.classList.remove('messenger-app--chat-open');
                app.el.messageList?.replaceChildren();
            }
            group = null;
            if (el.dialog.open) el.dialog.close();
            app.showToast(message);
            app.sendEvent('MessangerSocket:get_dialogs', {});
            app.sendEvent('DialogStateSocket:list', {});
        };

        const originalHandle = app.handleSocketMessage.bind(app);
        app.handleSocketMessage = (event) => {
            let data = null;
            try {
                data = JSON.parse(event.data);
            } catch (_) {
                return originalHandle(event);
            }

            if (data?.action === 'group_info' && data.group) {
                if (!app.currentDialog || data.group.dialog_uid !== app.currentDialog.uid) return;
                group = data.group;
                renderGroup();
                if (pendingOpen && !el.dialog.open) el.dialog.showModal();
                pendingOpen = false;
                return;
            }

            if (data?.action === 'group_changed') {
                app.sendEvent('MessangerSocket:get_dialogs', {});
                if (app.currentDialog?.uid === data.dialog_uid) {
                    app.sendEvent('GroupSocket:info', { dialog_uid: data.dialog_uid });
                }
                return;
            }

            if (data?.action === 'group_removed') {
                closeRemovedGroup(data.dialog_uid, 'Вас удалили из группы');
                return;
            }

            if (data?.action === 'group_left') {
                closeRemovedGroup(data.dialog_uid, 'Вы вышли из группы');
                return;
            }

            return originalHandle(event);
        };
    });
})();
{/literal}