document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('.file-manager');
  if (!root) return;

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
        try {
          await navigator.clipboard.writeText(new URL(url, window.location.origin).href);
          copy.textContent = 'Скопировано';
        } catch (_) {
          window.prompt('Скопируйте ссылку', new URL(url, window.location.origin).href);
        }
      });
      toast.append(copy);
    }
    document.body.append(toast);
    window.setTimeout(() => toast.remove(), 7000);
  }

  root.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('.btn-share') : null;
    if (!button) return;
    event.preventDefault();
    event.stopPropagation();

    const item = button.closest('.file-manager__item');
    const uid = String(item?.dataset.uid || '').trim();
    if (!uid) {
      showToast('У файла нет доступного идентификатора');
      return;
    }

    button.disabled = true;
    try {
      const body = new URLSearchParams({ expires_hours: '0' });
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
        showToast('Публичная ссылка создана и скопирована', data.share_url);
      } catch (_) {
        showToast('Публичная ссылка создана', data.share_url);
        window.prompt('Скопируйте ссылку', absolute);
      }
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Не удалось создать ссылку');
    } finally {
      button.disabled = false;
    }
  });
});
