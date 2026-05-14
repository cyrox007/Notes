// File Manager JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const btnCreateFolder = document.getElementById('btn-create-folder');
    const btnUploadFile = document.getElementById('btn-upload-file');
    const fileInput = document.getElementById('file-input');
    const modalCreateFolder = document.getElementById('modal-create-folder');
    const modalRename = document.getElementById('modal-rename');
    const folderNameInput = document.getElementById('folder-name-input');
    const renameInput = document.getElementById('rename-input');
    const renameIdInput = document.getElementById('rename-id');
    
    // Modal controls
    const modalCloseButtons = document.querySelectorAll('.file-manager__modal-close, .modal-cancel');
    const modalOkButtons = document.querySelectorAll('.modal-ok');
    
    let currentItemId = null;
    let currentItemType = null;
    let currentParentId = 0;
    
    // Get current folder ID from breadcrumb or URL
    const breadcrumb = document.querySelector('.file-manager__breadcrumb');
    if (breadcrumb) {
        const activeItem = breadcrumb.querySelector('.file-manager__breadcrumb-item--active');
        if (activeItem) {
            const href = activeItem.getAttribute('href');
            const match = href.match(/\/files\/folder\/(\d+)\//);
            currentParentId = match ? parseInt(match[1]) : 0;
        }
    }
    
    // Create Folder Button
    if (btnCreateFolder) {
        btnCreateFolder.addEventListener('click', function() {
            folderNameInput.value = '';
            modalCreateFolder.classList.add('show');
            folderNameInput.focus();
        });
    }
    
    // Upload File Button
    if (btnUploadFile) {
        btnUploadFile.addEventListener('click', function() {
            fileInput.click();
        });
        
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                uploadFiles(this.files);
            }
        });
    }
    
    // Modal Close
    modalCloseButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.file-manager__modal').classList.remove('show');
        });
    });
    
    // Close modal on outside click
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('file-manager__modal')) {
            event.target.classList.remove('show');
        }
    });
    
    // Create Folder OK
    const createFolderOkBtn = document.querySelector('#modal-create-folder .modal-ok');
    if (createFolderOkBtn) {
        createFolderOkBtn.addEventListener('click', function() {
            const name = folderNameInput.value.trim();
            if (name) {
                createFolder(name);
            }
        });
    }
    
    // Handle Enter key in folder name input
    if (folderNameInput) {
        folderNameInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const name = this.value.trim();
                if (name) {
                    createFolder(name);
                }
            }
        });
    }
    
    // Rename OK
    const renameOkBtn = document.querySelector('#modal-rename .modal-ok');
    if (renameOkBtn) {
        renameOkBtn.addEventListener('click', function() {
            const name = renameInput.value.trim();
            if (name && currentItemId) {
                renameItem(currentItemId, name);
            }
        });
    }
    
    // Handle Enter key in rename input
    if (renameInput) {
        renameInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const name = this.value.trim();
                if (name && currentItemId) {
                    renameItem(currentItemId, name);
                }
            }
        });
    }
    
    // Delete buttons
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const item = this.closest('.file-manager__item');
            const id = item.dataset.id;
            const type = item.dataset.type;
            const name = item.dataset.name;
            
            if (confirm(`Вы уверены, что хотите удалить "${name}"?`)) {
                deleteItem(id);
            }
        });
    });
    
    // Rename buttons
    document.querySelectorAll('.btn-rename').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const item = this.closest('.file-manager__item');
            currentItemId = item.dataset.id;
            currentItemType = item.dataset.type;
            const name = item.dataset.name;
            
            renameInput.value = name;
            renameIdInput.value = currentItemId;
            modalRename.classList.add('show');
            renameInput.focus();
            renameInput.select();
        });
    });
    
    // Functions
    function createFolder(name) {
        fetch('/files/create-folder/', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                name: name,
                parent_id: currentParentId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Ошибка при создании папки');
            }
            modalCreateFolder.classList.remove('show');
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при создании папки');
            modalCreateFolder.classList.remove('show');
        });
    }
    
    function uploadFiles(files) {
        Array.from(files).forEach(file => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('parent_id', currentParentId);
            
            fetch('/files/upload/', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('File uploaded:', data.file);
                    location.reload();
                } else {
                    alert(data.message || 'Ошибка при загрузке файла');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ошибка при загрузке файла');
            });
        });
    }
    
    function deleteItem(id) {
        const formData = new FormData();
        formData.append('id', id);
        
        fetch('/files/delete/', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Ошибка при удалении');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при удалении');
        });
    }
    
    function renameItem(id, name) {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('name', name);
        
        fetch('/files/rename/', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Ошибка при переименовании');
            }
            modalRename.classList.remove('show');
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при переименовании');
            modalRename.classList.remove('show');
        });
    }
});
