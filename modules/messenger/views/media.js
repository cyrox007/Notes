{literal}
(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        if (!app) return;

        const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;
        const attachButton = document.getElementById('message-attach-button');
        const fileInput = document.getElementById('message-file-input');
        const uploadStatus = document.getElementById('messenger-upload-status');
        const uploadText = document.getElementById('messenger-upload-text');
        const uploadProgress = document.getElementById('messenger-upload-progress');
        const uploadPercent = document.getElementById('messenger-upload-percent');

        if (!attachButton || !fileInput || !uploadStatus || !uploadProgress) return;

        let uploading = false;
        let dragDepth = 0;
        let pendingPasteFiles = [];

        const pastePreview = document.createElement('div');
        pastePreview.className = 'messenger-paste-preview';
        pastePreview.hidden = true;
        pastePreview.setAttribute('aria-live', 'polite');

        const pasteFiles = document.createElement('div');
        pasteFiles.className = 'messenger-paste-preview__files';
        const pasteRemove = document.createElement('button');
        pasteRemove.type = 'button';
        pasteRemove.className = 'messenger-paste-preview__remove';
        pasteRemove.textContent = 'Убрать';
        pastePreview.append(pasteFiles, pasteRemove);
        uploadStatus.insertAdjacentElement('beforebegin', pastePreview);

        const safeMediaUrl = (value) => {
            const raw = String(value || '');
            const basePath = String(window.wspace?.basePath || '');
            const route = basePath && (raw === basePath || raw.startsWith(basePath + '/'))
                ? (raw.slice(basePath.length) || '/')
                : raw;
            return /^\/messenger\/media\/[A-Za-z0-9-]+$/.test(route) ? appPath(route) : '';
        };

        const formatBytes = (bytes) => {
            const value = Number(bytes || 0);
            if (!Number.isFinite(value) || value <= 0) return '';
            if (value >= 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(value >= 10 * 1024 * 1024 ? 0 : 1)} МБ`;
            if (value >= 1024) return `${Math.round(value / 1024)} КБ`;
            return `${value} Б`;
        };

        const iconForExtension = (extension) => {
            const ext = String(extension || '').toLowerCase();
            if (ext === 'pdf') return 'fa-file-pdf-o';
            if (['doc', 'docx', 'odt'].includes(ext)) return 'fa-file-word-o';
            if (['xls', 'xlsx', 'ods'].includes(ext)) return 'fa-file-excel-o';
            if (['ppt', 'pptx', 'odp'].includes(ext)) return 'fa-file-powerpoint-o';
            if (['txt', 'md'].includes(ext)) return 'fa-file-text-o';
            return 'fa-file-o';
        };

        const activityForFile = (file) => {
    const mime = String(file?.type || '').toLowerCase();
    const name = String(file?.name || '');
    const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
    if (mime.startsWith('image/')) return 'uploading_image';
    if (mime.startsWith('video/')) return 'uploading_video';
    if (mime.startsWith('audio/')) return 'uploading_audio';
    if (['pdf', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'].includes(extension)) {
        return 'uploading_document';
    }
    return 'uploading_file';
};

const setActivity = (activity, active, dialogUid) => {
    if (!dialogUid || typeof app.setLocalActivity !== 'function') return false;
    return app.setLocalActivity(activity, active, dialogUid);
};

        const originalRenderMessage = app.renderMessage.bind(app);
        app.renderMessage = (message) => {
            const row = originalRenderMessage(message);
            if (!['image', 'audio', 'video', 'file', 'voice'].includes(message.message_type)) {
                return row;
            }

            const body = row.querySelector('.messenger-message__text');
            const mediaUrl = safeMediaUrl(message.media_url);
            if (!body || !mediaUrl) return row;

            const meta = message.meta_data && typeof message.meta_data === 'object'
                ? message.meta_data
                : {};
            const wrapper = document.createElement('div');
            wrapper.className = `messenger-media messenger-media--${message.message_type}`;

            if (message.message_type === 'image') {
                const link = document.createElement('a');
                link.href = mediaUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                const image = document.createElement('img');
                image.className = 'messenger-media__image';
                image.src = mediaUrl;
                image.alt = String(meta.name || 'Изображение');
                image.loading = 'lazy';
                link.append(image);
                wrapper.append(link);
            } else if (message.message_type === 'video') {
                const video = document.createElement('video');
                video.className = 'messenger-media__video';
                video.src = mediaUrl;
                video.controls = true;
                video.preload = 'metadata';
                wrapper.append(video);
            } else if (message.message_type === 'audio' || message.message_type === 'voice') {
                const audio = document.createElement('audio');
                audio.className = 'messenger-media__audio';
                audio.src = mediaUrl;
                audio.controls = true;
                audio.preload = 'metadata';
                wrapper.append(audio);
            } else {
                const link = document.createElement('a');
                link.className = 'messenger-media__file';
                link.href = mediaUrl;

                const iconBox = document.createElement('span');
                iconBox.className = 'messenger-media__file-icon';
                const icon = document.createElement('i');
                icon.className = `fa ${iconForExtension(meta.extension)}`;
                icon.setAttribute('aria-hidden', 'true');
                iconBox.append(icon);

                const copy = document.createElement('span');
                copy.className = 'messenger-media__file-copy';
                const name = document.createElement('strong');
                name.textContent = String(meta.name || 'Файл');
                const size = document.createElement('small');
                size.textContent = [String(meta.extension || '').toUpperCase(), formatBytes(meta.size)]
                    .filter(Boolean)
                    .join(' · ');
                copy.append(name, size);
                link.append(iconBox, copy);
                wrapper.append(link);
            }

            if (message.message) {
                const caption = document.createElement('div');
                caption.className = 'messenger-media__caption';
                caption.textContent = message.message;
                wrapper.append(caption);
            }

            body.replaceChildren(wrapper);
            return row;
        };

        const setUploadState = (active, text = '', percent = 0) => {
            uploading = active;
            attachButton.disabled = active;
            uploadStatus.hidden = !active;
            if (uploadText) uploadText.textContent = text;
            uploadProgress.value = Math.max(0, Math.min(100, Number(percent || 0)));
            if (uploadPercent) uploadPercent.textContent = active ? `${Math.round(uploadProgress.value)}%` : '';
        };

        const clearPastePreview = () => {
            pendingPasteFiles = [];
            pasteFiles.replaceChildren();
            pastePreview.hidden = true;
        };

        const stagePastedFiles = (files) => {
            const list = Array.from(files || []).filter((file) => file instanceof File);
            if (list.length === 0) return;
            pendingPasteFiles = list;
            pasteFiles.replaceChildren();
            list.forEach((file) => {
                const chip = document.createElement('span');
                chip.className = 'messenger-paste-preview__file';
                chip.title = file.name || 'Вложение из буфера';
                chip.textContent = `${file.name || 'Вложение из буфера'}${file.size ? ` · ${formatBytes(file.size)}` : ''}`;
                pasteFiles.append(chip);
            });
            pastePreview.hidden = false;
            app.showToast('Вложение добавлено. Нажмите «Отправить», чтобы отправить его в чат.');
        };

        pasteRemove.addEventListener('click', clearPastePreview);

        const uploadBinary = (file) => new Promise((resolve, reject) => {
            if (!app.currentDialog?.uid) {
                reject(new Error('Сначала выберите диалог'));
                return;
            }

            const form = new FormData();
            form.append('dialog_uid', app.currentDialog.uid);
            form.append('file', file, file.name || 'attachment');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', appPath('/messenger/upload'), true);
            xhr.responseType = 'json';
            xhr.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable) return;
                const percent = (event.loaded / event.total) * 100;
                setUploadState(true, `Загрузка: ${file.name || 'файл'}`, percent);
            });
            xhr.addEventListener('load', () => {
                const data = xhr.response || {};
                if (xhr.status >= 200 && xhr.status < 300 && data.success && data.attachment) {
                    resolve(data.attachment);
                    return;
                }
                reject(new Error(data.message || `Ошибка загрузки (${xhr.status})`));
            });
            xhr.addEventListener('error', () => reject(new Error('Сетевая ошибка при загрузке')));
            xhr.addEventListener('abort', () => reject(new Error('Загрузка отменена')));
            xhr.send(form);
        });

        const sendFiles = async (files) => {
            if (uploading) return false;
            const list = Array.from(files || []).filter((file) => file instanceof File);
            if (list.length === 0) return false;
            if (!app.currentDialog?.uid) {
                app.showToast('Сначала выберите диалог');
                return false;
            }
            if (app.editing) {
                app.showToast('Сначала завершите редактирование сообщения');
                return false;
            }

            const initialDialogUid = app.currentDialog.uid;
            const caption = (app.el.input?.value || '').trim();
            const replyToUid = app.replyTo?.uid || null;
            setUploadState(true, `Подготовка ${list.length === 1 ? 'файла' : 'файлов'}…`, 0);

            try {
                for (let index = 0; index < list.length; index += 1) {
                    if (app.currentDialog?.uid !== initialDialogUid) {
                        throw new Error('Диалог изменился во время загрузки');
                    }

                    const file = list[index];
                    const activity = activityForFile(file);
                    setActivity(activity, true, initialDialogUid);
                    try {
                        const attachment = await uploadBinary(file);
                        const sent = app.sendEvent('MediaSocket:send', {
                            attachment_uid: attachment.uid,
                            caption: index === 0 ? caption : '',
                            reply_to_uid: index === 0 ? replyToUid : null
                        });
                        if (!sent) {
                            throw new Error('Файл загружен, но нет соединения для отправки сообщения');
                        }
                    } finally {
                        setActivity(activity, false, initialDialogUid);
                    }
                }

                if (caption && app.el.input) {
                    app.el.input.value = '';
                    app.autosizeComposer();
                }
                if (replyToUid) {
                    app.clearComposeContext();
                }
                app.stopTyping();
                app.showToast(list.length === 1 ? 'Вложение отправляется' : `Отправляется файлов: ${list.length}`);
                return true;
            } catch (error) {
                console.error(error);
                app.showToast(error?.message || 'Не удалось отправить вложение');
                return false;
            } finally {
                setUploadState(false);
                fileInput.value = '';
            }
        };

        const originalSubmitComposer = app.submitComposer.bind(app);
        app.submitComposer = () => {
            if (pendingPasteFiles.length > 0 && !app.editing) {
                const files = [...pendingPasteFiles];
                sendFiles(files).then((sent) => {
                    if (sent) clearPastePreview();
                });
                return;
            }
            originalSubmitComposer();
        };

        const originalOpenDialog = app.openDialog.bind(app);
        app.openDialog = (uid) => {
            if (pendingPasteFiles.length > 0 && app.currentDialog?.uid && app.currentDialog.uid !== uid) {
                clearPastePreview();
            }
            originalOpenDialog(uid);
        };

        attachButton.addEventListener('click', () => {
            if (!app.currentDialog) {
                app.showToast('Сначала выберите диалог');
                return;
            }
            fileInput.click();
        });

        fileInput.addEventListener('change', () => sendFiles(fileInput.files));

        app.el.input?.addEventListener('paste', (event) => {
            const files = Array.from(event.clipboardData?.files || []);
            if (files.length === 0) return;
            event.preventDefault();
            stagePastedFiles(files);
        });

        const dropTarget = app.el.chatActive;
        if (dropTarget) {
            dropTarget.addEventListener('dragenter', (event) => {
                if (!event.dataTransfer?.types?.includes('Files')) return;
                event.preventDefault();
                dragDepth += 1;
                dropTarget.dataset.dragging = 'true';
            });
            dropTarget.addEventListener('dragover', (event) => {
                if (!event.dataTransfer?.types?.includes('Files')) return;
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            });
            dropTarget.addEventListener('dragleave', () => {
                dragDepth = Math.max(0, dragDepth - 1);
                if (dragDepth === 0) dropTarget.dataset.dragging = 'false';
            });
            dropTarget.addEventListener('drop', (event) => {
                event.preventDefault();
                dragDepth = 0;
                dropTarget.dataset.dragging = 'false';
                sendFiles(event.dataTransfer?.files || []);
            });
        }
    });
})();
{/literal}