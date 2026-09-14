/**
 * File Manager browser behavior.
 * User files are opened only through the protected /files/get/{id}/ endpoint.
 * Text/code preview is read-only: the browser never evaluates file contents.
 */

document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('.file-manager');
    if (!root) return;

    const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;
    const btnCreateFolder = document.getElementById('btn-create-folder');
    const btnUploadFile = document.getElementById('btn-upload-file');
    const fileInput = document.getElementById('file-input');
    const modalCreateFolder = document.getElementById('modal-create-folder');
    const modalRename = document.getElementById('modal-rename');
    const modalPlayer = document.getElementById('media-player-modal');
    const modalTextPreview = document.getElementById('text-preview-modal');
    const modalUploadProgress = document.getElementById('modal-upload-progress');
    const folderNameInput = document.getElementById('folder-name-input');
    const renameInput = document.getElementById('rename-input');
    const renameIdInput = document.getElementById('rename-id');
    const progressBar = modalUploadProgress ? modalUploadProgress.querySelector('.progress-bar') : null;
    const progressBarFill = document.getElementById('progress-bar-fill');
    const progressPercent = document.getElementById('progress-percent');
    const uploadFileName = document.getElementById('upload-file-name');

    const currentFolderId = Number.parseInt(root.dataset.currentFolderId || '0', 10) || 0;
    let currentItemId = null;
    let lastFocusedElement = null;

    const imageExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp']);
    const audioExtensions = new Set(['mp3', 'wav', 'ogg', 'flac', 'm4a']);
    const videoExtensions = new Set(['mp4', 'webm', 'avi', 'mov', 'mkv']);
    const textExtensions = new Set(['txt', 'md', 'php', 'js', 'py', 'java', 'cpp', 'c', 'html', 'css', 'json', 'xml', 'sql']);

    function showModal(modal) {
        if (!modal) return;
        lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modal.classList.add('show');
        document.body.classList.add('file-manager-modal-open');
        const focusTarget = modal.querySelector('input:not([type="hidden"]), button, [tabindex="0"]');
        if (focusTarget instanceof HTMLElement) {
            window.setTimeout(() => focusTarget.focus(), 0);
        }
    }

    function hideModal(modal) {
        if (!modal) return;
        modal.classList.remove('show');
        if (!document.querySelector('.file-manager__modal.show')) {
            document.body.classList.remove('file-manager-modal-open');
        }
        if (lastFocusedElement && document.contains(lastFocusedElement)) {
            lastFocusedElement.focus();
        }
    }

    function showError(message) {
        window.alert(message || 'Не удалось выполнить операцию');
    }

    function parseJsonResponse(response) {
        return response.json().catch(() => ({ success: false, message: `Ошибка сервера (${response.status})` }));
    }

    function postForm(url, values) {
        const body = new URLSearchParams();
        Object.entries(values).forEach(([key, value]) => body.set(key, String(value)));
        return fetch(appPath(url), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(parseJsonResponse);
    }

    function itemForElement(element) {
        return element instanceof Element ? element.closest('.file-manager__item') : null;
    }

    function fileUrl(id) {
        return appPath(`/files/get/${encodeURIComponent(String(id))}/`);
    }

    async function createFolder() {
        const folderName = folderNameInput ? folderNameInput.value.trim() : '';
        if (!folderName) {
            showError('Введите название папки');
            folderNameInput?.focus();
            return;
        }

        try {
            const data = await postForm('/files/create-folder/', {
                name: folderName,
                parent_id: currentFolderId
            });
            if (!data.success) {
                showError(data.message || 'Ошибка при создании папки');
                return;
            }
            hideModal(modalCreateFolder);
            window.location.reload();
        } catch (error) {
            console.error('Create folder failed:', error);
            showError('Ошибка при создании папки');
        }
    }

    async function renameItem() {
        const newName = renameInput ? renameInput.value.trim() : '';
        if (!currentItemId || !newName) {
            showError('Введите название');
            renameInput?.focus();
            return;
        }

        try {
            const data = await postForm('/files/rename/', {
                id: currentItemId,
                name: newName
            });
            if (!data.success) {
                showError(data.message || 'Ошибка при переименовании');
                return;
            }
            hideModal(modalRename);
            window.location.reload();
        } catch (error) {
            console.error('Rename failed:', error);
            showError('Ошибка при переименовании');
        }
    }

    async function deleteItem(item) {
        const id = item?.dataset.id;
        const name = item?.dataset.name || 'элемент';
        if (!id) return;
        if (!window.confirm(`Удалить «${name}»?`)) return;

        try {
            const data = await postForm('/files/delete/', { id });
            if (!data.success) {
                showError(data.message || 'Ошибка при удалении');
                return;
            }
            item.remove();
            if (!root.querySelector('.file-manager__item')) {
                window.location.reload();
            }
        } catch (error) {
            console.error('Delete failed:', error);
            showError('Ошибка при удалении');
        }
    }

    function resetProgress(fileName) {
        if (uploadFileName) uploadFileName.textContent = fileName;
        if (progressBarFill) progressBarFill.style.width = '0%';
        if (progressPercent) progressPercent.textContent = '0%';
        if (progressBar) progressBar.setAttribute('aria-valuenow', '0');
    }

    function uploadSingleFile(file) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('parent_id', String(currentFolderId));

            resetProgress(file.name);
            showModal(modalUploadProgress);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', appPath('/files/upload/'), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                if (progressBarFill) progressBarFill.style.width = `${percent}%`;
                if (progressPercent) progressPercent.textContent = `${percent}%`;
                if (progressBar) progressBar.setAttribute('aria-valuenow', String(percent));
            };

            xhr.onload = function () {
                let data;
                try {
                    data = JSON.parse(xhr.responseText || '{}');
                } catch (error) {
                    reject(new Error('Сервер вернул некорректный ответ'));
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300 && data.success) {
                    resolve(data);
                } else {
                    reject(new Error(data.message || `Ошибка загрузки (${xhr.status})`));
                }
            };
            xhr.onerror = () => reject(new Error('Ошибка сети при загрузке'));
            xhr.send(formData);
        });
    }

    async function uploadFiles(files) {
        try {
            for (const file of Array.from(files)) {
                await uploadSingleFile(file);
            }
            hideModal(modalUploadProgress);
            window.location.reload();
        } catch (error) {
            hideModal(modalUploadProgress);
            console.error('Upload failed:', error);
            showError(error instanceof Error ? error.message : 'Ошибка при загрузке файла');
        } finally {
            if (fileInput) fileInput.value = '';
        }
    }

    function openMediaPlayer(item) {
        if (!modalPlayer) return;
        const id = item.dataset.id;
        const name = item.dataset.name || 'Файл';
        const extension = (item.dataset.extension || '').toLowerCase();
        const container = document.getElementById('player-container');
        const title = document.getElementById('player-title');
        if (!id || !container || !title) return;

        container.replaceChildren();
        const url = fileUrl(id);
        let media = null;

        if (imageExtensions.has(extension)) {
            const image = document.createElement('img');
            image.src = url;
            image.alt = name;
            image.loading = 'lazy';
            media = image;
            title.textContent = `Просмотр: ${name}.${extension}`;
        } else if (audioExtensions.has(extension)) {
            const audio = document.createElement('audio');
            audio.src = url;
            audio.controls = true;
            audio.preload = 'metadata';
            media = audio;
            title.textContent = `Аудио: ${name}.${extension}`;
        } else if (videoExtensions.has(extension)) {
            const video = document.createElement('video');
            video.src = url;
            video.controls = true;
            video.preload = 'metadata';
            video.playsInline = true;
            media = video;
            title.textContent = `Видео: ${name}.${extension}`;
        }

        if (!media) return;
        container.appendChild(media);
        showModal(modalPlayer);
    }

    async function openTextPreview(item) {
        if (!modalTextPreview) return;
        const id = item.dataset.id;
        const name = item.dataset.name || 'Файл';
        const extension = (item.dataset.extension || '').toLowerCase();
        const title = document.getElementById('text-preview-title');
        const content = document.getElementById('text-preview-content');
        if (!id || !title || !content) return;

        title.textContent = `Просмотр: ${name}.${extension}`;
        content.textContent = 'Загрузка…';
        showModal(modalTextPreview);

        try {
            const response = await fetch(fileUrl(id), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const text = await response.text();
            content.textContent = text.length > 500000
                ? `${text.slice(0, 500000)}\n\n… предпросмотр ограничен 500 000 символами`
                : text;
        } catch (error) {
            console.error('Text preview failed:', error);
            content.textContent = 'Не удалось загрузить содержимое файла.';
        }
    }

    function openItem(item) {
        const type = item.dataset.type;
        const id = item.dataset.id;
        const extension = (item.dataset.extension || '').toLowerCase();
        if (!id) return;

        if (type === 'folder') {
            window.location.href = appPath(`/files/folder/${encodeURIComponent(id)}/`);
            return;
        }
        if (imageExtensions.has(extension) || audioExtensions.has(extension) || videoExtensions.has(extension)) {
            openMediaPlayer(item);
            return;
        }
        if (textExtensions.has(extension)) {
            openTextPreview(item);
        }
    }

    btnCreateFolder?.addEventListener('click', () => showModal(modalCreateFolder));
    btnUploadFile?.addEventListener('click', () => fileInput?.click());
    fileInput?.addEventListener('change', (event) => {
        const files = event.target.files;
        if (files && files.length > 0) uploadFiles(files);
    });

    modalCreateFolder?.querySelector('.modal-ok')?.addEventListener('click', createFolder);
    modalRename?.querySelector('.modal-ok')?.addEventListener('click', renameItem);

    root.addEventListener('click', function (event) {
        const deleteButton = event.target.closest('.btn-delete');
        if (deleteButton) {
            event.preventDefault();
            event.stopPropagation();
            deleteItem(itemForElement(deleteButton));
            return;
        }

        const renameButton = event.target.closest('.btn-rename');
        if (renameButton) {
            event.preventDefault();
            event.stopPropagation();
            const item = itemForElement(renameButton);
            if (!item || !renameInput || !renameIdInput) return;
            currentItemId = item.dataset.id || null;
            renameInput.value = item.dataset.name || '';
            renameIdInput.value = currentItemId || '';
            showModal(modalRename);
            window.setTimeout(() => renameInput.select(), 0);
        }
    });

    root.addEventListener('dblclick', function (event) {
        if (event.target.closest('.file-manager__item-actions')) return;
        const item = itemForElement(event.target);
        if (item) openItem(item);
    });

    root.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        const item = itemForElement(event.target);
        if (!item || event.target.closest('.file-manager__item-actions')) return;
        event.preventDefault();
        openItem(item);
    });

    document.querySelectorAll('.file-manager__modal-close, .modal-cancel').forEach((button) => {
        button.addEventListener('click', function () {
            hideModal(this.closest('.file-manager__modal'));
        });
    });

    document.querySelectorAll('.file-manager__modal').forEach((modal) => {
        modal.addEventListener('mousedown', function (event) {
            if (event.target === modal) hideModal(modal);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const openModal = document.querySelector('.file-manager__modal.show');
            if (openModal) hideModal(openModal);
        }
    });

    folderNameInput?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            createFolder();
        }
    });

    renameInput?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            renameItem();
        }
    });
});