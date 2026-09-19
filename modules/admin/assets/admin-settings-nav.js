(() => {
    'use strict';

    function boot() {
        const page = document.querySelector('.admin-page');
        const hero = page?.querySelector('.admin-page__hero');
        if (!page || !hero || page.querySelector('.admin-settings-tabs')) return;

        const appPath = (path) => typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;
        const current = window.location.pathname.replace(/\/+$/, '') || '/';
        const base = String(window.wspace?.basePath || '').replace(/\/+$/, '');
        const relative = base && current.startsWith(base) ? (current.slice(base.length) || '/') : current;

        const items = [
            ['/admin', 'fa-users', 'Пользователи'],
            ['/admin/roles', 'fa-shield', 'Роли и доступ'],
            ['/admin/registration', 'fa-user-plus', 'Регистрация'],
            ['/admin/settings', 'fa-sliders', 'Системные настройки'],
            ['/admin/license', 'fa-key', 'Лицензия'],
            ['/admin/updates', 'fa-refresh', 'Обновления'],
        ];

        const nav = document.createElement('nav');
        nav.className = 'admin-settings-tabs';
        nav.setAttribute('aria-label', 'Разделы админпанели');

        items.forEach(([path, icon, label]) => {
            const link = document.createElement('a');
            link.className = 'admin-settings-tabs__link';
            link.href = appPath(path + (path === '/admin' ? '/' : ''));
            link.innerHTML = `<i class="fa ${icon}" aria-hidden="true"></i><span>${label}</span>`;
            const active = path === '/admin'
                ? relative === '/admin' || relative === '/admin/'
                : relative === path || relative.startsWith(path + '/');
            if (active) link.setAttribute('aria-current', 'page');
            nav.append(link);
        });

        hero.insertAdjacentElement('afterend', nav);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
