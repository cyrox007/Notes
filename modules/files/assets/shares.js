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

  document.querySelectorAll('[data-revoke-share]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm('Отозвать эту публичную ссылку? Файл или папка останутся на месте.')) {
        event.preventDefault();
      }
    });
  });
});
