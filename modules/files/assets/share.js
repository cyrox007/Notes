document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('.file-manager');
  const modal = document.getElementById('modal-share');
  const uidInput = document.getElementById('share-item-uid');
  const nameNode = document.getElementById('share-item-name');
  const expiryMode = document.getElementById('share-expiry-mode');
  const customWrap = document.getElementById('share-expiry-custom-wrap');
  const customInput = document.getElementById('share-expiry-custom');
  const createButton = document.getElementById('share-create-button');
  if (!root || !modal || !uidInput || !expiryMode || !createButton) return;

  const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;

  function showToast(message, url = '') {
    document.querySelector('.file-manager-share-toast')?.remove();
    const toast = document.createElement('div');
    toast.className = 'file-manager-share-toast';
    const text = document.createElement('span');
    text.textContent = message;
    toast.append(text);
    if (url) {
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.textContent = 'Копировать';
      copy.addEventListener('click', async () => {
        const absolute = new URL(url, window.location.origin).href;
        try {
          await navigator.clipboard.writeText(absolute);
          copy.textContent = 'Скопировано';
        } catch (_) {
          window.prompt('Скопируйте ссылку', absolute);
        }
      });
      toast.append(copy);
    }
    document.body.append(toast);
    window.setTimeout(() => toast.remove(), 8000);
  }

  function openModal(item) {
    uidInput.value = String(item?.dataset.uid || '').trim();
    if (nameNode) {
      const ext = item?.dataset.type === 'folder' ? '' : String(item?.dataset.extension || '');
      nameNode.textContent = String(item?.dataset.name || '') + (ext ? '.' + ext : '');
    }
    expiryMode.value = '0';
    if (customInput) {
      const now = new Date(Date.now() + 5 * 60 * 1000);
      const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      customInput.min = local;
      customInput.value = '';
    }
    if (customWrap) customWrap.hidden = true;
    modal.classList.add('show');
    document.body.classList.add('file-manager-modal-open');
    expiryMode.focus();
  }

  function closeModal() {
    modal.classList.remove('show');
    if (!document.querySelector('.file-manager__modal.show')) {
      document.body.classList.remove('file-manager-modal-open');
    }
  }

  expiryMode.addEventListener('change', () => {
    if (customWrap) customWrap.hidden = expiryMode.value !== 'custom';
    if (expiryMode.value === 'custom') customInput?.focus();
  });

  root.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('.btn-share') : null;
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();
    const item = button.closest('.file-manager__item');
    if (!item?.dataset.uid) {
      showToast('У объекта нет доступного идентификатора');
      return;
    }
    openModal(item);
  });

  createButton.addEventListener('click', async () => {
    const uid = uidInput.value.trim();
    if (!uid) return;

    const body = new URLSearchParams();
    if (expiryMode.value === 'custom') {
      const value = String(customInput?.value || '').trim();
      if (!value) {
        showToast('Укажите дату окончания доступа');
        return;
      }
      body.set('expires_at', value);
    } else {
      body.set('expires_hours', expiryMode.value);
    }

    createButton.disabled = true;
    try {
      const response = await fetch(appPath('/files/share/' + encodeURIComponent(uid)), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data?.success !== true || !data?.share_url) {
        throw new Error(data?.message || 'Не удалось создать ссылку');
      }

      const absolute = new URL(data.share_url, window.location.origin).href;
      try {
        await navigator.clipboard.writeText(absolute);
        showToast('Публичная ссылка создана и скопирована. Управление — в разделе «Общий доступ».', data.share_url);
      } catch (_) {
        showToast('Публичная ссылка создана. Управление — в разделе «Общий доступ».', data.share_url);
        window.prompt('Скопируйте ссылку', absolute);
      }
      closeModal();
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Не удалось создать ссылку');
    } finally {
      createButton.disabled = false;
    }
  });

  modal.addEventListener('mousedown', (event) => {
    if (event.target === modal) closeModal();
  });
});
