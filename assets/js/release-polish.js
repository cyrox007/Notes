(function bootstrapReleasePolish() {
    document.addEventListener('DOMContentLoaded', () => {
        normalizePagedUrl();
        initFileManagerFindability();
    });

    function normalizePagedUrl() {
        const totalPages = Number.parseInt(document.body.dataset.listTotalPages || '', 10);
        if (!Number.isFinite(totalPages) || totalPages < 1) return;

        const url = new URL(window.location.href);
        const requested = Number.parseInt(url.searchParams.get('page') || '1', 10);
        const normalized = Math.min(Math.max(Number.isFinite(requested) ? requested : 1, 1), totalPages);
        if (requested === normalized) return;

        url.searchParams.set('page', String(normalized));
        window.location.replace(`${url.pathname}${url.search}${url.hash}`);
    }

    function initFileManagerFindability() {
        const manager = document.querySelector('.file-manager');
        const grid = manager?.querySelector('.file-manager__grid');
        if (!manager || !grid) return;

        const items = Array.from(grid.querySelectorAll('.file-manager__item'));
        if (items.length < 2) return;

        const controls = document.createElement('div');
        controls.className = 'list-findability file-manager-findability';
        controls.setAttribute('role', 'search');

        const searchLabel = document.createElement('label');
        searchLabel.className = 'list-findability__search';
        const searchText = document.createElement('span');
        searchText.textContent = 'Поиск в папке';
        const search = document.createElement('input');
        search.type = 'search';
        search.autocomplete = 'off';
        search.placeholder = 'Имя или расширение';
        searchLabel.append(searchText, search);

        const sortLabel = document.createElement('label');
        sortLabel.className = 'list-findability__sort';
        const sortText = document.createElement('span');
        sortText.textContent = 'Сортировка';
        const sort = document.createElement('select');
        [
            ['folders-name-asc', 'Папки, затем А–Я'],
            ['name-asc', 'Имя А–Я'],
            ['name-desc', 'Имя Я–А'],
            ['files-name-asc', 'Файлы, затем папки'],
        ].forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            sort.appendChild(option);
        });
        sortLabel.append(sortText, sort);

        const summary = document.createElement('span');
        summary.className = 'list-findability__summary';
        summary.setAttribute('role', 'status');
        summary.setAttribute('aria-live', 'polite');

        controls.append(searchLabel, sortLabel, summary);
        grid.before(controls);

        const normalizedName = (item) => `${item.dataset.name || ''}.${item.dataset.extension || ''}`.toLocaleLowerCase('ru');
        const compareName = (left, right) => normalizedName(left).localeCompare(normalizedName(right), 'ru', {
            numeric: true,
            sensitivity: 'base'
        });
        const typeRank = (item, filesFirst) => {
            const folder = item.dataset.type === 'folder';
            if (filesFirst) return folder ? 1 : 0;
            return folder ? 0 : 1;
        };

        function apply() {
            const needle = search.value.trim().toLocaleLowerCase('ru');
            const mode = sort.value;
            const visible = [];

            items.forEach((item) => {
                const matches = needle === '' || normalizedName(item).includes(needle);
                item.hidden = !matches;
                if (matches) visible.push(item);
            });

            const descending = mode === 'name-desc';
            const grouped = mode === 'folders-name-asc' || mode === 'files-name-asc';
            const filesFirst = mode === 'files-name-asc';
            visible.sort((left, right) => {
                if (grouped) {
                    const rank = typeRank(left, filesFirst) - typeRank(right, filesFirst);
                    if (rank !== 0) return rank;
                }
                const name = compareName(left, right);
                return descending ? -name : name;
            });
            visible.forEach((item) => grid.appendChild(item));
            summary.textContent = `Показано ${visible.length} из ${items.length}`;
        }

        search.addEventListener('input', apply);
        sort.addEventListener('change', apply);
        apply();
    }
})();
