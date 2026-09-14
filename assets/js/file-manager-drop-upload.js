document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('.file-manager');
  const fileInput = document.getElementById('file-input');
  if (!(root instanceof HTMLElement) || !(fileInput instanceof HTMLInputElement)) return;

  const overlay = document.createElement('div');
  overlay.className = 'file-manager-dropzone';
  overlay.hidden = true;
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-live', 'polite');
  overlay.innerHTML = '<div class="file-manager-dropzone__card"><i class="fa fa-cloud-upload" aria-hidden="true"></i><strong>Перетащите файлы сюда</strong><span>Файлы будут загружены в текущую папку</span></div>';
  root.appendChild(overlay);

  let dragDepth = 0;

  const isFileDrag = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');
  const show = () => {
    root.classList.add('is-file-dragging');
    overlay.hidden = false;
  };
  const hide = () => {
    dragDepth = 0;
    root.classList.remove('is-file-dragging');
    overlay.hidden = true;
  };

  root.addEventListener('dragenter', (event) => {
    if (!isFileDrag(event)) return;
    event.preventDefault();
    dragDepth += 1;
    show();
  });

  root.addEventListener('dragover', (event) => {
    if (!isFileDrag(event)) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    show();
  });

  root.addEventListener('dragleave', (event) => {
    if (!isFileDrag(event)) return;
    event.preventDefault();
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) hide();
  });

  root.addEventListener('drop', (event) => {
    if (!isFileDrag(event)) return;
    event.preventDefault();
    const files = event.dataTransfer?.files;
    hide();
    if (!files || files.length === 0) return;

    const uploadModal = document.getElementById('modal-upload-progress');
    if (uploadModal?.classList.contains('show')) return;

    try {
      fileInput.files = files;
    } catch (_) {
      return;
    }
    fileInput.dispatchEvent(new Event('change', { bubbles: true }));
  });

  window.addEventListener('blur', hide);
});
