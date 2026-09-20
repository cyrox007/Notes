{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        const root = document.getElementById('messenger-app');
        if (!app || !root || root.dataset.canUseFiles !== '1') return;

        const button = document.getElementById('message-storage-button');
        const dialog = document.getElementById('storage-file-dialog');
        const search = document.getElementById('storage-file-search');
        const list = document.getElementById('storage-file-list');
        const empty = document.getElementById('storage-file-empty');
        const selectedBox = document.getElementById('storage-file-selected');
        const selectedName = document.getElementById('storage-file-selected-name');
        const selectedMeta = document.getElementById('storage-file-selected-meta');
        const sendAttachment = document.getElementById('storage-send-attachment');
        const sendLink = document.getElementById('storage-send-link');
        if (!button || !dialog || !search || !list || !sendAttachment || !sendLink) return;

        const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;
        let files = [];
        let selected = null;
        let canShare = false;
        let loading = false;
        let searchTimer = null;

        const formatBytes = (bytes) => {
            const value = Number(bytes || 0);
            if (!Number.isFinite(value) || value <= 0) return '0 Б';
            if (value >= 1024 * 1024 * 1024) return `${(value / (1024 * 1024 * 1024)).toFixed(1)} ГБ`;
            if (value >= 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(value >= 10 * 1024 * 1024 ? 0 : 1)} МБ`;
            if (value >= 1024) return `${Math.round(value / 1024)} КБ`;
            return `${value} Б`;
        };

        const fileName = (file) => {
            const base = String(file?.name || 'Файл');
            const ext = String(file?.extension || '').trim();
            return ext ? `${base}.${ext}` : base;
        };

        const iconFor = (file) => {
            const type = String(file?.type || '');
            const ext = String(file?.extension || '').toLowerCase();
            if (type === 'image') return 'fa-file-image-o';
            if (type === 'audio') return 'fa-file-audio-o';
            if (type === 'video') return 'fa-file-video-o';
            if (ext === 'pdf') return 'fa-file-pdf-o';
            if (['doc','docx','odt'].includes(ext)) return 'fa-file-word-o';
            if (['xls','xlsx','ods'].includes(ext)) return 'fa-file-excel-o';
            if (['ppt','pptx','odp'].includes(ext)) return 'fa-file-powerpoint-o';
            if (['txt','md'].includes(ext)) return 'fa-file-text-o';
            return 'fa-file-o';
        };

        function setBusy(active) {
            loading = active;
            button.disabled = active;
            sendAttachment.disabled = active || !selected;
            sendLink.disabled = active || !selected || !canShare;
        }

        function setSelected(file) {
            selected = file || null;
            list.querySelectorAll('.messenger-storage-item').forEach((node) => {
                node.setAttribute('aria-selected', node.dataset.uid === selected?.uid ? 'true' : 'false');
            });
            if (selectedBox) selectedBox.hidden = !selected;
            if (selectedName) selectedName.textContent = selected ? fileName(selected) : '';
            if (selectedMeta) selectedMeta.textContent = selected
                ? [String(selected.extension || '').toUpperCase(), formatBytes(selected.size)].filter(Boolean).join(' · ')
                : '';
            sendAttachment.disabled = loading || !selected;
            sendLink.disabled = loading || !selected || !canShare;
        }

        function render() {
            list.replaceChildren();
            empty.hidden = files.length !== 0;
            files.forEach((file) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'messenger-storage-item';
                item.dataset.uid = file.uid || '';
                item.setAttribute('aria-selected', selected?.uid === file.uid ? 'true' : 'false');

                const iconBox = document.createElement('span');
                iconBox.className = 'messenger-storage-item__icon';
                const icon = document.createElement('i');
                icon.className = `fa ${iconFor(file)}`;
                icon.setAttribute('aria-hidden', 'true');
                iconBox.append(icon);

                const copy = document.createElement('span');
                copy.className = 'messenger-storage-item__copy';
                const name = document.createElement('strong');
                name.textContent = fileName(file);
                const meta = document.createElement('small');
                meta.textContent = [String(file.extension || '').toUpperCase(), formatBytes(file.size)].filter(Boolean).join(' · ');
                copy.append(name, meta);

                const date = document.createElement('time');
                date.className = 'messenger-storage-item__date';
                date.textContent = String(file.updated_at || '').slice(0, 16).replace('T', ' ');

                item.append(iconBox, copy, date);
                item.addEventListener('click', () => setSelected(file));
                list.append(item);
            });
        }

        async function loadFiles() {
            const query = String(search.value || '').trim();
            setBusy(true);
            try {
                const response = await fetch(appPath('/messenger/workspace/files') + (query ? `?q=${encodeURIComponent(query)}` : ''), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data?.success !== true) {
                    throw new Error(data?.message || 'Не удалось загрузить файлы');
                }
                files = Array.isArray(data.files) ? data.files : [];
                canShare = data.can_share === true;
                if (selected && !files.some((file) => file.uid === selected.uid)) setSelected(null);
                render();
                setSelected(selected);
                if (!canShare) sendLink.title = 'Публичные ссылки запрещены политикой роли';
                else sendLink.removeAttribute('title');
            } catch (error) {
                files = [];
                render();
                app.showToast(error instanceof Error ? error.message : 'Не удалось загрузить файлы');
            } finally {
                setBusy(false);
            }
        }

        button.addEventListener('click', () => {
            if (!app.currentDialog) {
                app.showToast('Сначала выберите диалог');
                return;
            }
            selected = null;
            search.value = '';
            setSelected(null);
            dialog.showModal();
            loadFiles();
            window.setTimeout(() => search.focus(), 0);
        });

        search.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(loadFiles, 220);
        });

        sendAttachment.addEventListener('click', async () => {
            if (!selected || !app.currentDialog?.uid || loading) return;
            const initialDialog = app.currentDialog.uid;
            const payload = new FormData();
            payload.set('file_uid', selected.uid);
            payload.set('dialog_uid', initialDialog);
            setBusy(true);
            try {
                const response = await fetch(appPath('/messenger/workspace/file-attachment'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    body: payload
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data?.success !== true || !data.attachment?.uid) {
                    throw new Error(data?.message || 'Не удалось подготовить вложение');
                }
                if (app.currentDialog?.uid !== initialDialog) {
                    throw new Error('Диалог изменился во время подготовки файла');
                }

                const caption = String(app.el.input?.value || '').trim();
                const replyUid = app.replyTo?.uid || null;
                const sent = app.sendEvent('MediaSocket:send', {
                    attachment_uid: data.attachment.uid,
                    caption,
                    reply_to_uid: replyUid
                });
                if (!sent) throw new Error('Файл подготовлен, но соединение Messenger недоступно');

                if (caption && app.el.input) {
                    app.el.input.value = '';
                    app.autosizeComposer();
                }
                if (replyUid) app.clearComposeContext();
                dialog.close();
                app.showToast('Файл из хранилища отправляется');
            } catch (error) {
                app.showToast(error instanceof Error ? error.message : 'Не удалось отправить файл');
            } finally {
                setBusy(false);
            }
        });

        sendLink.addEventListener('click', async () => {
            if (!selected || !canShare || !app.currentDialog?.uid || loading) return;
            const initialDialog = app.currentDialog.uid;
            const payload = new FormData();
            payload.set('file_uid', selected.uid);
            payload.set('expires_hours', '0');
            setBusy(true);
            try {
                const response = await fetch(appPath('/messenger/workspace/file-link'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    body: payload
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data?.success !== true || !data.share_url) {
                    throw new Error(data?.message || 'Не удалось создать ссылку');
                }
                if (app.currentDialog?.uid !== initialDialog) {
                    throw new Error('Диалог изменился во время создания ссылки');
                }

                const absoluteUrl = new URL(data.share_url, window.location.origin).href;
                const caption = String(app.el.input?.value || '').trim();
                const linkText = `📎 ${fileName(selected)}\n${absoluteUrl}`;
                const text = caption ? `${caption}\n\n${linkText}` : linkText;
                const sent = app.sendEvent('MessangerSocket:message_send', {
                    dialog_uid: initialDialog,
                    message: text,
                    reply_to_uid: app.replyTo?.uid || null
                });
                if (!sent) throw new Error('Ссылка создана, но соединение Messenger недоступно');

                if (app.el.input) {
                    app.el.input.value = '';
                    app.autosizeComposer();
                }
                if (app.replyTo) app.clearComposeContext();
                dialog.close();
                app.showToast('Ссылка на файл отправляется');
            } catch (error) {
                app.showToast(error instanceof Error ? error.message : 'Не удалось отправить ссылку');
            } finally {
                setBusy(false);
            }
        });
    });
})();
{/literal}
