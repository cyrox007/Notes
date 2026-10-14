(() => {
    'use strict';

    const PAGE_LIMIT = 10;
    const COUNT_INTERVAL_MS = 60 * 1000;
    const PANEL_CLOSE_MS = 170;
    const API_ROOT = '/api/notifications';
    const DEFAULT_CATEGORIES = [
        'calendar.reminders',
        'calendar.changes',
        'tasks.deadlines',
        'system.updates',
        'system.health',
        'system.storage',
        'security.policy',
        'messenger.realtime'
    ];
    const CATEGORY_LABELS = {
        'calendar.reminders': 'Напоминания календаря',
        'calendar.changes': 'Изменения календаря',
        'tasks.deadlines': 'Сроки задач',
        'system.updates': 'Обновления системы',
        'system.health': 'Состояние системы',
        'system.storage': 'Хранилище',
        'security.policy': 'Безопасность',
        'messenger.realtime': 'Связь Messenger'
    };
    const IMPORTANCE_LABELS = {
        low: 'Низкая',
        normal: 'Обычная',
        high: 'Важная',
        critical: 'Критическая'
    };
    const DELIVERY_LABELS = {
        none: '',
        pending: 'Ожидает внешней доставки',
        sent: 'Внешняя доставка выполнена',
        failed: 'Ошибка внешней доставки',
        partial: 'Доставлено не по всем каналам'
    };

    function init() {
        const actions = document.querySelector('.navbar__actions');
        if (!actions || document.querySelector('[data-notification-center]')) {
            return;
        }

        const legacyUpdates = actions.querySelector('[data-update-notifications]');
        const host = buildShell();
        if (legacyUpdates) {
            actions.insertBefore(host, legacyUpdates);
            legacyUpdates.setAttribute('aria-hidden', 'true');
        } else {
            actions.insertBefore(host, actions.firstChild);
        }

        const refs = collectRefs(host);
        const state = {
            open: false,
            closeTimer: 0,
            mode: 'list',
            page: 1,
            totalPages: 0,
            unreadCount: 0,
            updateCount: 0,
            status: 'all',
            category: '',
            preferences: null,
            update: null,
            loadingList: false,
            loadingPreferences: false
        };

        bindShell(refs, state);
        void refreshCount(refs, state);
        window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                void refreshCount(refs, state);
            }
        }, COUNT_INTERVAL_MS);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                void refreshCount(refs, state);
            }
        });
        window.addEventListener('online', () => void refreshCount(refs, state));

        document.addEventListener('wspace:update-notification', (event) => {
            state.update = event.detail && typeof event.detail === 'object' ? event.detail : null;
            state.updateCount = state.update?.available === true ? 1 : 0;
            renderBadge(refs, state);
            renderUpdateNotice(refs, state);
        });

        document.addEventListener('wspace:update-install-state', (event) => {
            const detail = event.detail && typeof event.detail === 'object' ? event.detail : {};
            renderUpdateInstallState(refs, detail);
        });
    }

    function buildShell() {
        const host = node('div', 'workspace-notification-center');
        host.dataset.notificationCenter = '';

        const toggle = button('navbar__icon-button workspace-notification-center__toggle', 'Центр уведомлений');
        toggle.dataset.notificationCenterToggle = '';
        toggle.setAttribute('aria-haspopup', 'dialog');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', 'workspaceNotificationCenterPanel');
        toggle.appendChild(icon('fa-inbox'));
        const badge = node('span', 'workspace-notification-center__badge');
        badge.dataset.notificationCenterBadge = '';
        badge.hidden = true;
        toggle.appendChild(badge);
        host.appendChild(toggle);

        const panel = node('section', 'workspace-notification-center__panel');
        panel.id = 'workspaceNotificationCenterPanel';
        panel.dataset.notificationCenterPanel = '';
        panel.setAttribute('aria-label', 'Центр уведомлений');
        panel.hidden = true;

        const header = node('header', 'workspace-notification-center__header');
        const headingWrap = node('div', 'workspace-notification-center__heading');
        headingWrap.appendChild(node('strong', '', 'Центр уведомлений'));
        headingWrap.appendChild(node('small', '', 'Все события Workspace в одном месте'));
        header.appendChild(headingWrap);

        const headerActions = node('div', 'workspace-notification-center__header-actions');
        const markAll = button('workspace-notification-center__text-action', 'Отметить всё прочитанным');
        markAll.dataset.notificationMarkAll = '';
        markAll.textContent = 'Прочитать всё';
        headerActions.appendChild(markAll);
        const settings = button('workspace-notification-center__icon-action', 'Настройки уведомлений');
        settings.dataset.notificationSettingsToggle = '';
        settings.appendChild(icon('fa-sliders'));
        headerActions.appendChild(settings);
        const close = button('workspace-notification-center__icon-action workspace-notification-center__close', 'Закрыть центр уведомлений');
        close.dataset.notificationCenterClose = '';
        close.appendChild(icon('fa-times'));
        headerActions.appendChild(close);
        header.appendChild(headerActions);
        panel.appendChild(header);

        const listView = node('div', 'workspace-notification-center__view');
        listView.dataset.notificationListView = '';
        const toolbar = node('div', 'workspace-notification-center__toolbar');
        const statusSelect = selectControl('Состояние', [
            ['', 'Все'],
            ['unread', 'Непрочитанные'],
            ['read', 'Прочитанные']
        ]);
        statusSelect.select.dataset.notificationStatusFilter = '';
        toolbar.appendChild(statusSelect.wrap);
        const categorySelect = selectControl('Категория', [['', 'Все категории']]);
        categorySelect.select.dataset.notificationCategoryFilter = '';
        toolbar.appendChild(categorySelect.wrap);
        listView.appendChild(toolbar);

        const updateNotice = node('div', 'workspace-notification-center__update');
        updateNotice.dataset.notificationUpdateNotice = '';
        updateNotice.hidden = true;
        listView.appendChild(updateNotice);

        const feedback = node('p', 'workspace-notification-center__feedback');
        feedback.dataset.notificationCenterFeedback = '';
        feedback.hidden = true;
        listView.appendChild(feedback);

        const list = node('div', 'workspace-notification-center__list');
        list.dataset.notificationList = '';
        list.setAttribute('aria-live', 'polite');
        listView.appendChild(list);

        const empty = node('div', 'workspace-notification-center__empty');
        empty.dataset.notificationEmpty = '';
        empty.hidden = true;
        empty.appendChild(icon('fa-inbox'));
        empty.appendChild(node('strong', '', 'Уведомлений пока нет'));
        empty.appendChild(node('span', '', 'Новые события появятся здесь автоматически.'));
        listView.appendChild(empty);

        const pager = node('footer', 'workspace-notification-center__pager');
        const previous = button('workspace-notification-center__pager-button', 'Предыдущая страница');
        previous.dataset.notificationPrevious = '';
        previous.appendChild(icon('fa-angle-left'));
        previous.appendChild(document.createTextNode(' Назад'));
        pager.appendChild(previous);
        const page = node('span', 'workspace-notification-center__page', 'Страница 1');
        page.dataset.notificationPage = '';
        pager.appendChild(page);
        const next = button('workspace-notification-center__pager-button', 'Следующая страница');
        next.dataset.notificationNext = '';
        next.appendChild(document.createTextNode('Вперёд '));
        next.appendChild(icon('fa-angle-right'));
        pager.appendChild(next);
        listView.appendChild(pager);
        panel.appendChild(listView);

        const settingsView = node('div', 'workspace-notification-center__view workspace-notification-center__settings');
        settingsView.dataset.notificationSettingsView = '';
        settingsView.hidden = true;
        const settingsIntro = node('div', 'workspace-notification-center__settings-intro');
        const back = button('workspace-notification-center__back', 'Вернуться к уведомлениям');
        back.dataset.notificationSettingsBack = '';
        back.appendChild(icon('fa-angle-left'));
        back.appendChild(document.createTextNode(' Уведомления'));
        settingsIntro.appendChild(back);
        const copy = node('div');
        copy.appendChild(node('strong', '', 'Подписки и каналы'));
        copy.appendChild(node('p', '', 'Встроенный центр всегда включён. Внешнюю доставку можно настроить отдельно для каждой категории.'));
        settingsIntro.appendChild(copy);
        settingsView.appendChild(settingsIntro);
        const settingsFeedback = node('p', 'workspace-notification-center__feedback');
        settingsFeedback.dataset.notificationSettingsFeedback = '';
        settingsFeedback.hidden = true;
        settingsView.appendChild(settingsFeedback);
        const settingsList = node('div', 'workspace-notification-center__settings-list');
        settingsList.dataset.notificationSettingsList = '';
        settingsView.appendChild(settingsList);
        panel.appendChild(settingsView);

        host.appendChild(panel);
        return host;
    }

    function collectRefs(host) {
        const get = (selector) => host.querySelector(selector);
        return {
            host,
            toggle: get('[data-notification-center-toggle]'),
            badge: get('[data-notification-center-badge]'),
            panel: get('[data-notification-center-panel]'),
            close: get('[data-notification-center-close]'),
            markAll: get('[data-notification-mark-all]'),
            settingsToggle: get('[data-notification-settings-toggle]'),
            settingsBack: get('[data-notification-settings-back]'),
            listView: get('[data-notification-list-view]'),
            settingsView: get('[data-notification-settings-view]'),
            status: get('[data-notification-status-filter]'),
            category: get('[data-notification-category-filter]'),
            updateNotice: get('[data-notification-update-notice]'),
            feedback: get('[data-notification-center-feedback]'),
            list: get('[data-notification-list]'),
            empty: get('[data-notification-empty]'),
            previous: get('[data-notification-previous]'),
            next: get('[data-notification-next]'),
            page: get('[data-notification-page]'),
            settingsFeedback: get('[data-notification-settings-feedback]'),
            settingsList: get('[data-notification-settings-list]')
        };
    }

    function bindShell(refs, state) {
        refs.toggle.addEventListener('click', () => setPanel(refs, state, !state.open));
        refs.close.addEventListener('click', () => setPanel(refs, state, false));
        refs.settingsToggle.addEventListener('click', () => showSettings(refs, state));
        refs.settingsBack.addEventListener('click', () => showList(refs, state));
        refs.markAll.addEventListener('click', () => void markAllRead(refs, state));
        refs.status.addEventListener('change', () => {
            state.status = refs.status.value === '' ? 'all' : refs.status.value;
            state.page = 1;
            void loadList(refs, state);
        });
        refs.category.addEventListener('change', () => {
            state.category = refs.category.value;
            state.page = 1;
            void loadList(refs, state);
        });
        refs.previous.addEventListener('click', () => {
            if (state.page <= 1) return;
            state.page -= 1;
            void loadList(refs, state);
        });
        refs.next.addEventListener('click', () => {
            if (state.totalPages === 0 || state.page >= state.totalPages) return;
            state.page += 1;
            void loadList(refs, state);
        });

        document.addEventListener('click', (event) => {
            if (state.open && event.target instanceof Node && !refs.host.contains(event.target)) {
                setPanel(refs, state, false);
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && state.open) {
                setPanel(refs, state, false);
                refs.toggle.focus();
            }
        });
    }

    function setPanel(refs, state, open) {
        window.clearTimeout(state.closeTimer);
        state.open = open;
        refs.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            refs.panel.hidden = false;
            window.requestAnimationFrame(() => refs.panel.classList.add('is-open'));
            showList(refs, state);
            void Promise.all([loadList(refs, state), loadPreferences(refs, state, false)]);
            return;
        }

        refs.panel.classList.remove('is-open');
        state.closeTimer = window.setTimeout(() => {
            if (!state.open) refs.panel.hidden = true;
        }, PANEL_CLOSE_MS);
    }

    function showList(refs, state) {
        state.mode = 'list';
        refs.listView.hidden = false;
        refs.settingsView.hidden = true;
        refs.settingsToggle.setAttribute('aria-pressed', 'false');
    }

    function showSettings(refs, state) {
        state.mode = 'settings';
        refs.listView.hidden = true;
        refs.settingsView.hidden = false;
        refs.settingsToggle.setAttribute('aria-pressed', 'true');
        void loadPreferences(refs, state, true);
    }

    async function refreshCount(refs, state) {
        try {
            const payload = await requestJson(apiUrl('/unread-count'));
            state.unreadCount = positiveInt(payload.unread_count);
            renderBadge(refs, state);
        } catch (error) {
            handleQuietError(error);
        }
    }

    async function loadList(refs, state) {
        if (state.loadingList) return;
        state.loadingList = true;
        showFeedback(refs.feedback, 'Загружаем уведомления…', 'loading');
        refs.empty.hidden = true;
        try {
            const query = new URLSearchParams({
                status: state.status,
                page: String(state.page),
                limit: String(PAGE_LIMIT)
            });
            if (state.category) query.set('category', state.category);
            const payload = await requestJson(apiUrl('/?' + query.toString()));
            state.unreadCount = positiveInt(payload.unread_count);
            state.page = positiveInt(payload.pagination?.page) || 1;
            state.totalPages = positiveInt(payload.pagination?.total_pages);
            renderBadge(refs, state);
            renderList(refs, state, Array.isArray(payload.items) ? payload.items : []);
            hideFeedback(refs.feedback);
        } catch (error) {
            refs.list.replaceChildren();
            showFeedback(refs.feedback, errorMessage(error, 'Не удалось загрузить уведомления.'), 'error');
        } finally {
            state.loadingList = false;
        }
    }

    function renderList(refs, state, items) {
        refs.list.replaceChildren();
        refs.empty.hidden = items.length !== 0 || state.update?.available === true;

        items.forEach((item) => refs.list.appendChild(renderNotificationItem(item, refs, state)));
        refs.page.textContent = state.totalPages > 0
            ? `Страница ${state.page} из ${state.totalPages}`
            : 'Нет страниц';
        refs.previous.disabled = state.page <= 1;
        refs.next.disabled = state.totalPages === 0 || state.page >= state.totalPages;
    }

    function renderNotificationItem(item, refs, state) {
        const notificationId = positiveInt(item.id);
        const unread = item.read_at === null || item.read_at === '' || item.read_at === undefined;
        const article = node('article', 'workspace-notification-item');
        if (unread) article.classList.add('is-unread');
        article.dataset.importance = safeToken(item.importance, 'normal');

        const top = node('div', 'workspace-notification-item__top');
        const category = node('span', 'workspace-notification-item__category', categoryLabel(item.category));
        top.appendChild(category);
        const time = node('time', 'workspace-notification-item__time', formatDate(item.created_at));
        top.appendChild(time);
        article.appendChild(top);

        article.appendChild(node('strong', 'workspace-notification-item__title', safeText(item.title)));
        if (safeText(item.body)) {
            article.appendChild(node('p', 'workspace-notification-item__body', safeText(item.body)));
        }

        const meta = node('div', 'workspace-notification-item__meta');
        const importance = node('span', 'workspace-notification-item__importance', IMPORTANCE_LABELS[item.importance] || 'Обычная');
        meta.appendChild(importance);
        const delivery = item.delivery && typeof item.delivery === 'object' ? item.delivery : null;
        const deliveryState = safeToken(delivery?.state, 'none');
        if (deliveryState !== 'none' && DELIVERY_LABELS[deliveryState]) {
            const deliveryChip = node('span', 'workspace-notification-item__delivery', DELIVERY_LABELS[deliveryState]);
            deliveryChip.dataset.deliveryState = deliveryState;
            meta.appendChild(deliveryChip);
        }
        article.appendChild(meta);

        const actions = node('div', 'workspace-notification-item__actions');
        const target = safeTarget(item.target_path);
        if (target) {
            const open = button('workspace-notification-item__primary', 'Открыть связанный объект');
            open.textContent = 'Открыть';
            open.addEventListener('click', () => {
                if (unread && notificationId > 0) {
                    void mutate(`/` + notificationId + '/read', 'POST').finally(() => window.wspace.page.visit(target));
                } else {
                    window.wspace.page.visit(target);
                }
            });
            actions.appendChild(open);
        }

        if (notificationId > 0) {
            const readToggle = button('workspace-notification-item__secondary', unread ? 'Отметить прочитанным' : 'Вернуть в непрочитанные');
            readToggle.textContent = unread ? 'Прочитано' : 'Вернуть';
            readToggle.addEventListener('click', async () => {
                readToggle.disabled = true;
                try {
                    const payload = await mutate(`/` + notificationId + (unread ? '/read' : '/unread'), 'POST');
                    state.unreadCount = positiveInt(payload.unread_count);
                    renderBadge(refs, state);
                    await loadList(refs, state);
                } catch (error) {
                    showFeedback(refs.feedback, errorMessage(error, 'Не удалось изменить состояние уведомления.'), 'error');
                } finally {
                    readToggle.disabled = false;
                }
            });
            actions.appendChild(readToggle);
        }
        article.appendChild(actions);
        return article;
    }

    async function markAllRead(refs, state) {
        refs.markAll.disabled = true;
        try {
            const payload = await mutate('/read-all', 'POST');
            state.unreadCount = positiveInt(payload.unread_count);
            renderBadge(refs, state);
            await loadList(refs, state);
        } catch (error) {
            showFeedback(refs.feedback, errorMessage(error, 'Не удалось отметить уведомления прочитанными.'), 'error');
        } finally {
            refs.markAll.disabled = false;
        }
    }

    async function loadPreferences(refs, state, render) {
        if (state.loadingPreferences) return;
        state.loadingPreferences = true;
        if (render) showFeedback(refs.settingsFeedback, 'Загружаем настройки…', 'loading');
        try {
            const payload = await requestJson(apiUrl('/preferences'));
            state.preferences = normalizePreferences(payload);
            populateCategories(refs, state.preferences.subscriptions);
            if (render || state.mode === 'settings') {
                renderPreferences(refs, state);
                hideFeedback(refs.settingsFeedback);
            }
        } catch (error) {
            if (render || state.mode === 'settings') {
                showFeedback(refs.settingsFeedback, errorMessage(error, 'Не удалось загрузить настройки уведомлений.'), 'error');
            }
        } finally {
            state.loadingPreferences = false;
        }
    }

    function normalizePreferences(payload) {
        const subscriptions = new Map();
        if (Array.isArray(payload.subscriptions)) {
            payload.subscriptions.forEach((entry) => {
                const category = safeText(entry?.category).trim();
                if (category) subscriptions.set(category, entry);
            });
        }
        DEFAULT_CATEGORIES.forEach((category) => {
            if (!subscriptions.has(category)) {
                subscriptions.set(category, {
                    category,
                    in_app_enabled: true,
                    external_enabled: false,
                    channels: { email: false, browser: false, telegram: false, webhook: false, sms: false },
                    effective_channels: { email: false, browser: false, telegram: false, webhook: false, sms: false }
                });
            }
        });
        return {
            channels: payload.channels && typeof payload.channels === 'object' ? payload.channels : {},
            subscriptions: [...subscriptions.values()]
        };
    }

    function populateCategories(refs, subscriptions) {
        const selected = refs.category.value;
        const values = new Set(DEFAULT_CATEGORIES);
        subscriptions.forEach((entry) => {
            const category = safeText(entry?.category).trim();
            if (category) values.add(category);
        });
        refs.category.replaceChildren(option('', 'Все категории'));
        [...values].sort((a, b) => categoryLabel(a).localeCompare(categoryLabel(b), 'ru')).forEach((value) => {
            refs.category.appendChild(option(value, categoryLabel(value)));
        });
        refs.category.value = values.has(selected) ? selected : '';
        stateCategorySync(refs);
    }

    function stateCategorySync(refs) {
        refs.category.dispatchEvent(new Event('workspace:category-options-updated'));
    }

    function renderPreferences(refs, state) {
        refs.settingsList.replaceChildren();
        const preferences = state.preferences;
        if (!preferences) return;

        const emailAvailability = preferences.channels.email && typeof preferences.channels.email === 'object'
            ? preferences.channels.email
            : {};
        const ordered = [...preferences.subscriptions].sort((a, b) => categoryLabel(a.category).localeCompare(categoryLabel(b.category), 'ru'));
        ordered.forEach((subscription) => {
            refs.settingsList.appendChild(renderPreferenceCard(subscription, emailAvailability, refs, state));
        });
    }

    function renderPreferenceCard(subscription, emailAvailability, refs, state) {
        const category = safeText(subscription.category);
        const card = node('section', 'workspace-notification-preference');
        const header = node('header');
        const title = node('div');
        title.appendChild(node('strong', '', categoryLabel(category)));
        title.appendChild(node('small', '', category));
        header.appendChild(title);
        const internal = node('span', 'workspace-notification-preference__required', 'В Workspace · всегда');
        header.appendChild(internal);
        card.appendChild(header);

        const external = switchControl('Внешняя доставка', 'Разрешить дополнительные каналы для этой категории.', subscription.external_enabled === true);
        card.appendChild(external.wrap);

        const emailEnabled = subscription.channels?.email === true;
        const email = switchControl('E-mail', emailHint(emailAvailability, subscription), emailEnabled);
        email.input.disabled = emailAvailability.available !== true && !emailEnabled;
        card.appendChild(email.wrap);

        const future = node('div', 'workspace-notification-preference__future');
        future.appendChild(node('span', '', 'Browser Push · позже'));
        future.appendChild(node('span', '', 'Telegram · позже'));
        future.appendChild(node('span', '', 'Webhook · позже'));
        future.appendChild(node('span', '', 'SMS · позже'));
        card.appendChild(future);

        external.input.addEventListener('change', () => void savePreference(
            refs,
            state,
            card,
            category,
            { external_enabled: external.input.checked }
        ));
        email.input.addEventListener('change', () => void savePreference(
            refs,
            state,
            card,
            category,
            { channels: { email: email.input.checked } }
        ));
        return card;
    }

    async function savePreference(refs, state, card, category, patch) {
        card.classList.add('is-saving');
        card.querySelectorAll('input').forEach((input) => { input.disabled = true; });
        try {
            const payload = await requestJson(apiUrl('/preferences'), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ category, ...patch })
            });
            const updated = payload.subscription;
            if (updated && state.preferences) {
                const index = state.preferences.subscriptions.findIndex((entry) => entry.category === category);
                if (index >= 0) state.preferences.subscriptions[index] = updated;
                else state.preferences.subscriptions.push(updated);
            }
            renderPreferences(refs, state);
            showFeedback(refs.settingsFeedback, 'Настройки сохранены.', 'success');
            window.setTimeout(() => hideFeedback(refs.settingsFeedback), 1800);
        } catch (error) {
            showFeedback(refs.settingsFeedback, errorMessage(error, 'Не удалось сохранить настройки.'), 'error');
            await loadPreferences(refs, state, true);
        }
    }

    function emailHint(availability, subscription) {
        if (availability.configured !== true) return 'SMTP не настроен администратором.';
        if (availability.destination_ready !== true) return 'В профиле нет корректного E-mail.';
        if (subscription.effective_channels?.email === true) return 'Активен для этой категории.';
        return 'Доступен для внешней доставки.';
    }

    function renderUpdateNotice(refs, state) {
        const detail = state.update;
        refs.updateNotice.replaceChildren();
        if (!detail || detail.available !== true) {
            refs.updateNotice.hidden = true;
            return;
        }
        refs.updateNotice.hidden = false;
        const marker = node('span', 'workspace-notification-center__update-icon');
        marker.appendChild(icon('fa-arrow-circle-up'));
        refs.updateNotice.appendChild(marker);
        const body = node('div', 'workspace-notification-center__update-body');
        body.appendChild(node('strong', '', safeText(detail.title) || 'Доступно обновление'));
        body.appendChild(node('p', '', safeText(detail.message) || 'Доступна новая версия Workspace Organizer.'));
        const actions = node('div', 'workspace-notification-center__update-actions');
        if (detail.canApply === true) {
            const apply = button('workspace-notification-item__primary', 'Установить обновление');
            apply.dataset.notificationUpdateApply = '';
            apply.textContent = detail.version ? `Обновить до ${safeText(detail.version)}` : 'Обновить';
            apply.addEventListener('click', () => {
                document.dispatchEvent(new CustomEvent('wspace:update-install-request'));
            });
            actions.appendChild(apply);
        }
        const detailsUrl = safeSameOriginUrl(detail.detailsUrl);
        if (detailsUrl) {
            const link = document.createElement('a');
            link.href = detailsUrl;
            link.textContent = 'Подробнее';
            actions.appendChild(link);
        }
        body.appendChild(actions);
        refs.updateNotice.appendChild(body);
    }

    function renderUpdateInstallState(refs, detail) {
        const action = refs.updateNotice.querySelector('[data-notification-update-apply]');
        if (!action) return;
        const state = safeText(detail.state);
        if (state === 'running') {
            action.disabled = true;
            const progress = positiveInt(detail.progress);
            action.textContent = progress > 0 && progress < 100 ? `Обновление — ${progress}%` : 'Устанавливаем…';
            return;
        }
        action.disabled = false;
        if (state === 'error') {
            action.textContent = 'Повторить';
        }
    }

    function renderBadge(refs, state) {
        const total = Math.max(0, state.unreadCount + state.updateCount);
        refs.badge.hidden = total === 0;
        refs.badge.textContent = total > 99 ? '99+' : String(total);
        refs.toggle.classList.toggle('has-unread', total > 0);
        refs.toggle.setAttribute('aria-label', total > 0 ? `Центр уведомлений: ${total} новых` : 'Центр уведомлений');
    }

    async function mutate(suffix, method) {
        return requestJson(apiUrl(suffix), { method, headers: { 'Accept': 'application/json' } });
    }

    async function requestJson(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', ...(options.headers || {}) },
            ...options
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || typeof payload !== 'object') {
            const error = new Error(payload?.message || `HTTP ${response.status}`);
            error.status = response.status;
            error.code = payload?.code || '';
            throw error;
        }
        return payload;
    }

    function apiUrl(suffix) {
        const path = API_ROOT + suffix;
        return typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;
    }

    function safeTarget(value) {
        if (typeof value !== 'string') return null;
        const path = value.trim();
        if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\') || /[\x00-\x1F\x7F]/.test(path)) return null;
        const resolved = typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;
        return safeSameOriginUrl(resolved);
    }

    function safeSameOriginUrl(value) {
        if (typeof value !== 'string' || value.trim() === '') return null;
        try {
            const url = new URL(value, window.location.href);
            return url.origin === window.location.origin ? url.href : null;
        } catch (_) {
            return null;
        }
    }

    function formatDate(value) {
        const text = safeText(value).trim();
        if (!text) return '';
        const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!match) return text;
        return `${match[3]}.${match[2]}.${match[1]} ${match[4]}:${match[5]}`;
    }

    function categoryLabel(value) {
        const category = safeText(value).trim();
        if (CATEGORY_LABELS[category]) return CATEGORY_LABELS[category];
        if (!category) return 'Без категории';
        return category.split('.').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join(' · ');
    }

    function showFeedback(element, text, state) {
        element.hidden = false;
        element.textContent = text;
        element.dataset.state = state;
    }

    function hideFeedback(element) {
        element.hidden = true;
        element.textContent = '';
        delete element.dataset.state;
    }

    function handleQuietError(error) {
        if (error && error.status === 401) {
            document.dispatchEvent(new CustomEvent('wspace:session-expired'));
        }
    }

    function errorMessage(error, fallback) {
        return error instanceof Error && error.message ? error.message : fallback;
    }

    function safeText(value) {
        return typeof value === 'string' ? value : value == null ? '' : String(value);
    }

    function safeToken(value, fallback) {
        const token = safeText(value).toLowerCase();
        return /^[a-z][a-z0-9_-]{0,31}$/.test(token) ? token : fallback;
    }

    function positiveInt(value) {
        const number = Number(value);
        return Number.isInteger(number) && number > 0 ? number : 0;
    }

    function node(tag, className = '', text = null) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== null) element.textContent = text;
        return element;
    }

    function icon(name) {
        const element = node('i', `fa ${name}`);
        element.setAttribute('aria-hidden', 'true');
        return element;
    }

    function button(className, label) {
        const element = node('button', className);
        element.type = 'button';
        element.setAttribute('aria-label', label);
        element.title = label;
        return element;
    }

    function option(value, label) {
        const element = document.createElement('option');
        element.value = value;
        element.textContent = label;
        return element;
    }

    function selectControl(label, options) {
        const wrap = node('label', 'workspace-notification-center__select');
        wrap.appendChild(node('span', '', label));
        const select = document.createElement('select');
        options.forEach(([value, text]) => select.appendChild(option(value, text)));
        wrap.appendChild(select);
        return { wrap, select };
    }

    function switchControl(title, description, checked) {
        const wrap = node('label', 'workspace-notification-preference__switch');
        const copy = node('span', 'workspace-notification-preference__copy');
        copy.appendChild(node('strong', '', title));
        copy.appendChild(node('small', '', description));
        wrap.appendChild(copy);
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = checked;
        wrap.appendChild(input);
        const visual = node('span', 'workspace-notification-preference__toggle');
        visual.setAttribute('aria-hidden', 'true');
        wrap.appendChild(visual);
        return { wrap, input };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
