/**
 * File Manager JavaScript
 * Handles file operations, media player, and code editor
 */

document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const btnCreateFolder = document.getElementById('btn-create-folder');
    const btnUploadFile = document.getElementById('btn-upload-file');
    const fileInput = document.getElementById('file-input');
    const modalCreateFolder = document.getElementById('modal-create-folder');
    const modalRename = document.getElementById('modal-rename');
    const modalPlayer = document.getElementById('media-player-modal');
    const modalEditor = document.getElementById('code-editor-modal');
    
    // Current folder context
    let currentFolderId = 0;
    let currentItemId = null;
    
    // Ace Editor instance
    let editor = null;
    
    // Initialize Ace Editor
    if (typeof ace !== 'undefined') {
        editor = ace.edit("code-editor");
        editor.setTheme("ace/theme/monokai");
        editor.session.setMode("ace/mode/javascript");
        editor.setFontSize(14);
    }
    
    // ==================== Folder Creation ====================
    
    if (btnCreateFolder) {
        btnCreateFolder.addEventListener('click', function() {
            showModal(modalCreateFolder);
            document.getElementById('folder-name-input').focus();
        });
    }
    
    // Modal OK button for create folder
    const createFolderOk = modalCreateFolder.querySelector('.modal-ok');
    if (createFolderOk) {
        createFolderOk.addEventListener('click', createFolder);
    }
    
    function createFolder() {
        const nameInput = document.getElementById('folder-name-input');
        const folderName = nameInput.value.trim();
        
        if (!folderName) {
            alert('Введите название папки');
            return;
        }
        
        fetch('/files/create-folder/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `name=${encodeURIComponent(folderName)}&parent_id=${currentFolderId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Добавляем новую папку в DOM без перезагрузки
                const filesContainer = document.querySelector('.file-manager__grid');
                if (filesContainer && data.folder && data.folder.id) {
                    // Проверяем, есть ли элемент "Папка пуста" и удаляем его
                    const emptyMessage = document.querySelector('.file-manager__empty');
                    if (emptyMessage) {
                        emptyMessage.remove();
                    }
                    
                    const folderHtml = `
                        <div class="file-manager__item" data-id="${data.folder.id}" data-type="folder" data-name="${escapeHtml(data.folder.name)}">
                            <div class="file-manager__item-icon">
                                <i class="fa fa-folder"></i>
                            </div>
                            <div class="file-manager__item-name">${escapeHtml(data.folder.name)}</div>
                            <div class="file-manager__item-meta">Папка</div>
                            <div class="file-manager__item-actions">
                                <a href="/files/folder/${data.folder.id}/" class="file-manager__action-btn" title="Открыть">
                                    <i class="fa fa-folder-open"></i>
                                </a>
                                <button class="file-manager__action-btn file-manager__action-btn--rename btn-rename" title="Переименовать">
                                    <i class="fa fa-edit"></i>
                                </button>
                                <button class="file-manager__action-btn file-manager__action-btn--delete btn-delete" title="Удалить">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                    filesContainer.insertAdjacentHTML('beforeend', folderHtml);
                    
                    // Переназначаем обработчики событий для новых кнопок
                    const newItem = filesContainer.lastElementChild;
                    newItem.querySelector('.btn-delete').addEventListener('click', function(e) {
                        e.stopPropagation();
                        const item = this.closest('.file-manager__item');
                        const id = item.dataset.id;
                        const itemName = item.dataset.name;
                        if (confirm(`Вы уверены, что хотите удалить "${itemName}"?`)) {
                            deleteItem(id);
                        }
                    });
                    
                    newItem.querySelector('.btn-rename').addEventListener('click', function(e) {
                        e.stopPropagation();
                        const item = this.closest('.file-manager__item');
                        currentItemId = item.dataset.id;
                        const itemName = item.dataset.name;
                        const renameInput = document.getElementById('rename-input');
                        const renameIdInput = document.getElementById('rename-id');
                        const modalRename = document.getElementById('modal-rename');
                        renameInput.value = itemName;
                        renameIdInput.value = currentItemId;
                        modalRename.classList.add('show');
                        renameInput.focus();
                        renameInput.select();
                    });
                    
                    hideModal(modalCreateFolder);
                    nameInput.value = '';
                } else {
                    // Если не удалось добавить в DOM, перезагружаем страницу
                    location.reload();
                }
            } else {
                alert(data.message || 'Ошибка при создании папки');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при создании папки');
        });
        
        hideModal(modalCreateFolder);
        nameInput.value = '';
    }
    
    // Функция для экранирования HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // ==================== File Upload ====================
    
    if (btnUploadFile) {
        btnUploadFile.addEventListener('click', function() {
            fileInput.click();
        });
    }
    
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const files = e.target.files;
            if (files.length === 0) return;
            
            uploadFiles(files);
        });
    }
    
    function uploadFiles(files) {
        Array.from(files).forEach(file => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('parent_id', currentFolderId);
            
            fetch('/files/upload/', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Ошибка при загрузке файла: ' + file.name);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ошибка при загрузке файла: ' + file.name);
            });
        });
        
        fileInput.value = '';
    }
    
    // ==================== Delete ====================
    
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const item = this.closest('.fm-item');
            const fileId = item.dataset.id;
            const fileName = item.dataset.name;
            
            if (confirm(`Вы уверены, что хотите удалить "${fileName}"?`)) {
                fetch('/files/delete/', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id=${fileId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        item.remove();
                    } else {
                        alert(data.message || 'Ошибка при удалении');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Ошибка при удалении');
                });
            }
        });
    });
    
    // ==================== Rename ====================
    
    document.querySelectorAll('.btn-rename').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const item = this.closest('.fm-item');
            currentItemId = item.dataset.id;
            const itemName = item.dataset.name;
            
            document.getElementById('rename-input').value = itemName;
            document.getElementById('rename-id').value = currentItemId;
            showModal(modalRename);
            document.getElementById('rename-input').focus();
        });
    });
    
    const renameOk = modalRename.querySelector('.modal-ok');
    if (renameOk) {
        renameOk.addEventListener('click', function() {
            const newName = document.getElementById('rename-input').value.trim();
            
            if (!newName) {
                alert('Введите название');
                return;
            }
            
            fetch('/files/rename/', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `id=${currentItemId}&name=${encodeURIComponent(newName)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Ошибка при переименовании');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ошибка при переименовании');
            });
            
            hideModal(modalRename);
        });
    }
    
    // ==================== Media Player ====================
    
    document.querySelectorAll('.fm-item[data-type="file"]').forEach(item => {
        item.addEventListener('dblclick', function() {
            const fileId = this.dataset.id;
            const fileName = this.dataset.name;
            const extension = this.dataset.extension;
            
            // Check if it's a media file
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(extension.toLowerCase());
            const isAudio = ['mp3', 'wav', 'ogg', 'flac', 'm4a'].includes(extension.toLowerCase());
            const isVideo = ['mp4', 'webm', 'avi', 'mov', 'mkv'].includes(extension.toLowerCase());
            const isCode = ['php', 'js', 'py', 'java', 'cpp', 'c', 'html', 'css', 'json', 'xml', 'sql'].includes(extension.toLowerCase());
            
            if (isImage || isAudio || isVideo) {
                openMediaPlayer(fileId, fileName, extension, isImage, isAudio, isVideo);
            } else if (isCode) {
                openCodeEditor(fileId, fileName, extension);
            }
        });
    });
    
    function openMediaPlayer(fileId, fileName, extension, isImage, isAudio, isVideo) {
        const container = document.getElementById('player-container');
        const title = document.getElementById('player-title');
        const fileUrl = `/files/get/${fileId}/`;
        
        container.innerHTML = '';
        
        if (isImage) {
            title.textContent = `Просмотр: ${fileName}.${extension}`;
            const img = document.createElement('img');
            img.src = fileUrl;
            img.alt = fileName;
            container.appendChild(img);
        } else if (isAudio) {
            title.textContent = `Аудио: ${fileName}.${extension}`;
            const audio = document.createElement('audio');
            audio.src = fileUrl;
            audio.controls = true;
            audio.autoplay = true;
            container.appendChild(audio);
        } else if (isVideo) {
            title.textContent = `Видео: ${fileName}.${extension}`;
            const video = document.createElement('video');
            video.src = fileUrl;
            video.controls = true;
            video.autoplay = true;
            video.style.maxHeight = '500px';
            container.appendChild(video);
        }
        
        showModal(modalPlayer);
    }
    
    // ==================== Code Editor ====================
    
    function openCodeEditor(fileId, fileName, extension) {
        const title = document.getElementById('editor-title');
        title.textContent = `Редактор: ${fileName}.${extension}`;
        
        // Set editor mode based on extension
        if (editor) {
            const modeMap = {
                'php': 'ace/mode/php',
                'js': 'ace/mode/javascript',
                'py': 'ace/mode/python',
                'java': 'ace/mode/java',
                'cpp': 'ace/mode/c_cpp',
                'c': 'ace/mode/c_cpp',
                'html': 'ace/mode/html',
                'css': 'ace/mode/css',
                'json': 'ace/mode/json',
                'xml': 'ace/mode/xml',
                'sql': 'ace/mode/sql'
            };
            
            const mode = modeMap[extension.toLowerCase()] || 'ace/mode/text';
            editor.session.setMode(mode);
            
            // Load file content
            fetch(`/files/get/${fileId}/`)
                .then(response => response.text())
                .then(content => {
                    editor.setValue(content, -1);
                })
                .catch(error => {
                    console.error('Error loading file:', error);
                    editor.setValue('// Error loading file content', -1);
                });
        }
        
        // Store current file ID for save
        modalEditor.dataset.fileId = fileId;
        
        showModal(modalEditor);
        
        // Resize editor after modal is shown
        setTimeout(() => {
            if (editor) editor.resize();
        }, 100);
    }
    
    // Run code button
    const btnRunCode = document.getElementById('btn-run-code');
    if (btnRunCode && editor) {
        btnRunCode.addEventListener('click', function() {
            const code = editor.getValue();
            runCodeInSandbox(code);
        });
    }
    
    // Save code button
    const btnSaveCode = document.getElementById('btn-save-code');
    if (btnSaveCode) {
        btnSaveCode.addEventListener('click', function() {
            if (!editor || !modalEditor.dataset.fileId) return;
            
            const content = editor.getValue();
            const fileId = modalEditor.dataset.fileId;
            
            // For now, just show a message - actual save would require backend endpoint
            alert('Функция сохранения будет доступна в следующей версии.\n\nКод можно скопировать вручную.');
            
            // Copy to clipboard
            navigator.clipboard.writeText(content).then(() => {
                alert('Код скопирован в буфер обмена');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        });
    }
    
    function runCodeInSandbox(code) {
        // Create a sandboxed iframe for code execution
        const sandbox = document.createElement('iframe');
        sandbox.style.display = 'none';
        sandbox.sandbox = 'allow-scripts';
        document.body.appendChild(sandbox);
        
        // Create HTML for the sandbox
        const html = `
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
                    .output { background: #2d2d2d; padding: 10px; border-radius: 4px; margin-top: 10px; }
                    .error { color: #f48771; }
                    .log { color: #569cd6; }
                </style>
            </head>
            <body>
                <div class="output" id="output"></div>
                <script>
                    // Override console methods
                    const output = document.getElementById('output');
                    const originalLog = console.log;
                    const originalError = console.error;
                    const originalWarn = console.warn;
                    
                    console.log = function(...args) {
                        originalLog.apply(console, args);
                        output.innerHTML += '<div class="log">' + args.join(' ') + '</div>';
                    };
                    
                    console.error = function(...args) {
                        originalError.apply(console, args);
                        output.innerHTML += '<div class="error">' + args.join(' ') + '</div>';
                    };
                    
                    console.warn = function(...args) {
                        originalWarn.apply(console, args);
                        output.innerHTML += '<div class="warn">' + args.join(' ') + '</div>';
                    };
                    
                    try {
                        ${code}
                    } catch (e) {
                        console.error('Error: ' + e.message);
                    }
                <\/script>
            </body>
            </html>
        `;
        
        sandbox.srcdoc = html;
        
        // Show results in a new modal or alert
        setTimeout(() => {
            const doc = sandbox.contentDocument;
            if (doc) {
                const output = doc.getElementById('output');
                if (output && output.innerHTML.trim()) {
                    alert('Результат выполнения:\n\n' + output.innerText);
                } else {
                    alert('Код выполнен (нет вывода в консоль)');
                }
            }
            document.body.removeChild(sandbox);
        }, 500);
    }
    
    // ==================== Modal Helpers ====================
    
    function showModal(modal) {
        if (modal) {
            modal.classList.add('show');
        }
    }
    
    function hideModal(modal) {
        if (modal) {
            modal.classList.remove('show');
        }
    }
    
    // Close modals
    document.querySelectorAll('.fm-modal-close, .modal-cancel').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.fm-modal');
            hideModal(modal);
        });
    });
    
    // Close modal on outside click
    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('fm-modal')) {
            hideModal(e.target);
        }
    });
    
    // Handle Enter key in modals
    modalCreateFolder.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            createFolder();
        }
    });
    
    modalRename.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            modalRename.querySelector('.modal-ok').click();
        }
    });
});
