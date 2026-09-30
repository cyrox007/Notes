document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-copy-share]').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = new URL(button.dataset.copyShare || '', window.location.origin).href;
      try {
        await navigator.clipboard.writeText(value);
        const original = button.textContent;
        button.textContent = 'Скопировано';
        window.setTimeout(() => { button.textContent = original; }, 1600);
      } catch (_) {
        window.prompt('Скопируйте ссылку', value);
      }
    });
  });

  document.querySelectorAll('.file-shares__expiry').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const input = form.querySelector('input[name="expires_at"]');
      if (!(input instanceof HTMLInputElement) || input.value === '') return;

      const expiresAt = new Date(input.value);
      if (Number.isNaN(expiresAt.getTime())) {
        event.preventDefault();
        window.alert('Укажите корректную дату окончания доступа');
        return;
      }

      // datetime-local хранит время без зоны. Перед отправкой превращаем его
      // в абсолютный момент, чтобы сервер в другом часовом поясе не сдвигал срок.
      input.type = 'hidden';
      input.value = expiresAt.toISOString();
    });
  });

  document.querySelectorAll('[data-revoke-share]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm('Отозвать эту публичную ссылку? Файл или папка останутся на месте.')) {
        event.preventDefault();
      }
    });
  });
});
