document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('.file-manager');
  if (!root) return;
  const content = root.querySelector('.file-manager__content');
  const grid = root.querySelector('.file-manager__grid');

  const controls = document.createElement('section');
  controls.className = 'file-manager-polish';
  controls.setAttribute('aria-label', 'Поиск и отображение файлов');

  const search = document.createElement('input');
  search.id = 'file-manager-search';
  search.type = 'search';
  search.placeholder = 'Поиск в этой папке';
  search.autocomplete = 'off';
  search.setAttribute('aria-label', 'Поиск в текущей папке');

  const sort = document.createElement('select');
  sort.id = 'file-manager-sort';
  sort.setAttribute('aria-label', 'Сортировка файлов');
  [['name-asc','Имя A → Я'],['name-desc','Имя Я → A'],['type','По типу']].forEach(([value,label]) => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    sort.appendChild(option);
  });

  const view = document.createElement('div');
  view.className = 'file-manager-polish__view';
  view.setAttribute('role', 'group');
  view.setAttribute('aria-label', 'Режим отображения');
  [['grid','fa-th-large','Плитка'],['list','fa-list','Список']].forEach(([mode,icon,label]) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.fileView = mode;
    button.title = label;
    button.setAttribute('aria-label', label);
    button.setAttribute('aria-pressed', 'false');
    const node = document.createElement('i');
    node.className = `fa ${icon}`;
    node.setAttribute('aria-hidden', 'true');
    button.appendChild(node);
    view.appendChild(button);
  });

  const count = document.createElement('span');
  count.className = 'file-manager-polish__count';
  count.setAttribute('aria-live', 'polite');
  controls.append(search, sort, view, count);
  content?.before(controls);

  const items = grid ? Array.from(grid.querySelectorAll('.file-manager__item')) : [];
  const fullName = (item) => {
    const name = item.dataset.name || '';
    const ext = item.dataset.type === 'folder' ? '' : (item.dataset.extension || '');
    return `${name}${ext ? `.${ext}` : ''}`;
  };
  const byName = (a,b) => fullName(a).localeCompare(fullName(b), 'ru', { numeric: true, sensitivity: 'base' });

  function apply() {
    if (!grid) {
      count.textContent = '0 элементов';
      return;
    }
    const query = search.value.trim().toLocaleLowerCase('ru');
    const ordered = [...items].sort((a,b) => {
      const folderOrder = (a.dataset.type === 'folder' ? 0 : 1) - (b.dataset.type === 'folder' ? 0 : 1);
      if (folderOrder) return folderOrder;
      if (sort.value === 'name-desc') return -byName(a,b);
      if (sort.value === 'type') {
        const typed = (a.dataset.extension || a.dataset.type || '').localeCompare(b.dataset.extension || b.dataset.type || '', 'ru');
        return typed || byName(a,b);
      }
      return byName(a,b);
    });
    let visible = 0;
    ordered.forEach((item) => {
      grid.appendChild(item);
      const match = !query || fullName(item).toLocaleLowerCase('ru').includes(query);
      item.hidden = !match;
      if (match) visible += 1;
    });
    count.textContent = `${visible} из ${items.length}`;
  }

  search.addEventListener('input', apply);
  sort.addEventListener('change', apply);

  const storageKey = 'wspace:file-manager:view';
  function setView(mode) {
    const value = mode === 'list' ? 'list' : 'grid';
    root.dataset.view = value;
    view.querySelectorAll('[data-file-view]').forEach((button) => button.setAttribute('aria-pressed', button.dataset.fileView === value ? 'true' : 'false'));
    try { localStorage.setItem(storageKey, value); } catch (_) {}
  }
  view.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-file-view]') : null;
    if (button) setView(button.dataset.fileView || 'grid');
  });
  let savedView = 'list';
  try { savedView = localStorage.getItem(storageKey) || 'list'; } catch (_) {}
  setView(savedView);
  apply();
});
