{literal}
window.wspace.page.register('modules/messenger/views/e2ee.js', (pageScope) => {
(() => {
    'use strict';

    pageScope.onInit(() => {
        const app = window.wspace?.messenger;
        const root = document.getElementById('messenger-app');
        const panel = document.getElementById('messenger-e2ee-panel');
        const toggle = document.getElementById('chat-e2ee-button');
        if (!app || !root || !panel || !toggle) return;

        const ui = {
            status: document.getElementById('messenger-e2ee-status'),
            setup: document.getElementById('messenger-e2ee-setup'),
            passphrase: document.getElementById('messenger-e2ee-passphrase'),
            activate: document.getElementById('messenger-e2ee-activate'),
            keys: document.getElementById('messenger-e2ee-keys'),
            refresh: document.getElementById('messenger-e2ee-refresh-devices'),
            devices: document.getElementById('messenger-e2ee-device-list'),
            messages: document.getElementById('messenger-e2ee-messages'),
            input: document.getElementById('messenger-e2ee-input'),
            file: document.getElementById('messenger-e2ee-file'),
            send: document.getElementById('messenger-e2ee-send')
        };
        const path = (resource) => typeof window.wspace?.path === 'function'
            ? window.wspace.path(resource) : resource;
        const clientModule = (name) => path('/messenger/e2ee/client/' + name);
        const state = {
            active: false, pending: false, loading: false, sdk: null,
            machine: null, transport: null, trust: null, conversation: null,
            currentDevice: '', cursor: new Map(), rendered: new Set(), database: null,
            generation: 0
        };

        const status = (message, error = false) => {
            ui.status.textContent = message;
            ui.status.classList.toggle('messenger-e2ee-status--error', error);
            ui.status.classList.toggle('messenger-e2ee-status--ok', !error && !!state.conversation);
        };

        const setReady = (ready) => {
            const allow = ready && !state.pending && state.active;
            ui.input.disabled = !allow;
            ui.send.disabled = !allow;
            ui.file.disabled = !allow;
            panel.querySelector('label[for="messenger-e2ee-file"]')?.setAttribute('aria-disabled', String(!allow));
        };

        const setBusy = (busy) => {
            state.pending = busy;
            ui.activate.disabled = busy;
            ui.refresh.disabled = busy;
            setReady(Boolean(state.conversation));
        };

        function togglePanel(visible) {
            state.active = Boolean(visible && app.currentDialog?.uid);
            state.generation += 1;
            panel.hidden = !state.active;
            root.dataset.e2eeActive = String(state.active);
            toggle.setAttribute('aria-pressed', String(state.active));
            toggle.title = state.active ? 'Вернуться к обычным сообщениям' : 'Открыть защищённую переписку';
            if (state.active) {
                ui.messages.replaceChildren();
                state.rendered.clear();
                status(state.conversation
                    ? 'Отдельная зашифрованная история. Проверяйте ключи при каждом изменении участников.'
                    : 'Настройте ключи устройства. Никакое сообщение не будет отправлено открытым текстом.');
                if (state.conversation) {
                    void refreshDevices();
                    void refreshMessages();
                }
            }
            setReady(Boolean(state.conversation));
        }

        function deviceIdentifier(userUid) {
            const storageKey = 'notes:e2ee:device:v1:' + userUid;
            let id = localStorage.getItem(storageKey);
            if (!id) {
                if (!crypto?.randomUUID) throw new Error('Нужен безопасный генератор устройства');
                id = 'device_' + crypto.randomUUID().replaceAll('-', '');
                localStorage.setItem(storageKey, id);
            }
            if (!/^[A-Za-z0-9_-]{8,64}$/.test(id)) {
                throw new Error('Сохранённый идентификатор устройства повреждён');
            }
            return id;
        }

        async function loadEngine() {
            if (state.sdk) return;
            if (!window.isSecureContext || !window.indexedDB || !window.crypto?.subtle) {
                throw new Error('Защищённый режим требует HTTPS и доступного хранилища ключей.');
            }
            // Все модули загружаются только с сервера Notes: никаких внешних CDN.
            const modules = await Promise.all([
                import(path('/messenger/e2ee/sdk/index.mjs')),
                import(clientModule('matrix-device-bootstrap.mjs')),
                import(clientModule('matrix-http-transport.mjs')),
                import(clientModule('matrix-device-trust.mjs')),
                import(clientModule('matrix-conversation.mjs'))
            ]);
            const [sdk, bootstrap, http, trust, conversations] = modules;
            if (!sdk.OlmMachine?.initialize) throw new Error('Локальный криптодвижок не установлен.');
            state.sdk = sdk;
            state.bootstrap = bootstrap;
            state.http = http;
            state.trustApi = trust;
            state.conversations = conversations;
        }

        async function initialize() {
            if (state.loading || state.conversation) return;
            const userUid = app.userUid;
            const passphrase = String(ui.passphrase.value);
            if (passphrase.length < 16) {
                status('Введите отдельную парольную фразу не короче 16 символов.', true);
                return;
            }
            state.loading = true;
            setBusy(true);
            status('Загружается локальный криптографический движок…');
            try {
                await loadEngine();
                const deviceId = deviceIdentifier(userUid);
                const opened = await state.bootstrap.openClientMachine(
                    state.sdk, userUid, deviceId, passphrase
                );
                const transport = state.http.createMessengerE2eeTransport({
                    messengerPath: path('/messenger'),
                    getCsrfToken: () => window.wspace?.security?.getCSRFToken?.() || ''
                });
                const database = state.trustApi.openTrustDatabase(userUid);
                const db = await database;
                const trust = state.trustApi.createLocalDeviceTrust(db);
                await state.bootstrap.publishInitialKeys(
                    opened.machine, userUid, deviceId,
                    (route, body) => {
                        if (route === '/e2ee/devices') {
                            return transport.registerDevice(body.device_id, body.identity);
                        }
                        if (route === '/e2ee/prekeys') {
                            return transport.publishPrekeys(body.device_id, body.keys);
                        }
                        throw new Error('Криптодвижок запросил неизвестную операцию');
                    }
                );

                state.machine = opened.machine;
                state.currentDevice = deviceId;
                state.transport = transport;
                state.database = db;
                state.trust = trust;
                state.conversation = state.conversations.createE2eeConversation({
                    sdk: state.sdk, machine: state.machine, transport, trust,
                    userUid, deviceId
                });
                ui.setup.hidden = true;
                ui.keys.hidden = false;
                status('Устройство подготовлено. Сверьте отпечатки участников перед отправкой.');
                await refreshDevices();
                await refreshMessages();
            } catch (error) {
                status(error?.message || 'Криптодвижок недоступен. Отправка заблокирована.', true);
            } finally {
                // Парольная фраза не хранится в DOM после попытки открытия.
                ui.passphrase.value = '';
                state.loading = false;
                setBusy(false);
            }
        }

        async function refreshDevices() {
            if (!state.conversation || !app.currentDialog?.uid || !state.active) return;
            const generation = state.generation;
            const participants = await state.transport.participants(app.currentDialog.uid);
            const users = Array.isArray(participants?.members) ? participants.members : [];
            const deviceRows = [];
            for (const uid of users) {
                const response = await state.transport.getDevices(uid);
                for (const device of response?.devices || []) {
                    if (!device.signing_key || !device.device_id) continue;
                    const fingerprint = await state.trust.fingerprint(device.signing_key);
                    const confirmed = await state.trust.isTrusted(uid, device.device_id, device.signing_key);
                    deviceRows.push({ uid, device, fingerprint, confirmed });
                }
            }
            if (generation !== state.generation || !state.active) return;
            ui.devices.replaceChildren();
            if (deviceRows.length === 0) {
                const empty = document.createElement('p');
                empty.textContent = 'Участники ещё не зарегистрировали устройства. Защищённая отправка недоступна.';
                ui.devices.append(empty);
            }
            for (const row of deviceRows) {
                const item = document.createElement('div');
                item.className = 'messenger-e2ee-device';
                const data = document.createElement('div');
                const title = document.createElement('strong');
                title.textContent = (row.uid === app.userUid ? 'Моё устройство' : row.uid)
                    + ' · ' + row.device.device_id;
                const code = document.createElement('code');
                code.textContent = row.fingerprint;
                const info = document.createElement('p');
                info.textContent = row.confirmed ? 'Отпечаток подтверждён локально'
                    : 'Не подтверждено — сверьте отпечаток вне этого чата';
                data.append(title, code, info);
                item.append(data);
                if (!row.confirmed) {
                    const verify = document.createElement('button');
                    verify.type = 'button';
                    verify.className = 'messenger-secondary-button';
                    verify.textContent = 'Подтвердить';
                    verify.addEventListener('click', () => void (async () => {
                        try {
                            await state.trust.pin(
                                row.uid, row.device.device_id, row.device.signing_key,
                                ({fingerprint}) => window.confirm(
                                    'Сверьте отпечаток по независимому каналу:\n\n'
                                    + fingerprint
                                    + '\n\nЕсли вы лично подтвердили совпадение, нажмите ОК.'
                                )
                            );
                            await refreshDevices();
                            status('Отпечаток подтверждён на этом устройстве.');
                        } catch (error) {
                            status(error?.message || 'Подтверждение устройства отклонено', true);
                        }
                    })());
                    item.append(verify);
                }
                ui.devices.append(item);
            }
        }

        function appendMessage(message) {
            const item = document.createElement('article');
            item.className = 'messenger-e2ee-message';
            item.dataset.own = String(message.sender_uid === app.userUid);
            const header = document.createElement('strong');
            header.textContent = message.sender_uid === app.userUid
                ? 'Вы · шифрование' : 'Участник · шифрование';
            const body = document.createElement('span');
            body.textContent = message.type === 'file' ? 'Зашифрованный файл: '
                + message.attachment.name : message.text;
            item.append(header, body);
            if (message.type === 'file' && message.attachment) {
                const download = document.createElement('button');
                download.type = 'button';
                download.className = 'messenger-secondary-button';
                download.textContent = 'Расшифровать и сохранить';
                download.addEventListener('click', () => void (async () => {
                    download.disabled = true;
                    try {
                        const bytes = await state.conversation.receiveFile(
                            app.currentDialog.uid, message.attachment
                        );
                        const url = URL.createObjectURL(new Blob([bytes], {
                            type: 'application/octet-stream'
                        }));
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = message.attachment.name;
                        link.click();
                        pageScope.setTimeout(() => URL.revokeObjectURL(url), 30000);
                    } catch (error) {
                        status(error?.message || 'Не удалось расшифровать файл', true);
                    } finally {
                        download.disabled = false;
                    }
                })());
                item.append(download);
            }
            ui.messages.append(item);
        }

        async function refreshMessages() {
            if (!state.active || !state.conversation || state.pending || !app.currentDialog?.uid) return;
            const dialogUid = app.currentDialog.uid;
            const generation = state.generation;
            try {
                const cursor = state.cursor.get(dialogUid) || 0;
                const result = await state.conversation.receive(dialogUid, cursor);
                if (!state.active || generation !== state.generation
                    || app.currentDialog?.uid !== dialogUid) return;
                for (const message of result.messages || []) {
                    const key = dialogUid + ':' + message.id;
                    if (state.rendered.has(key)) continue;
                    state.rendered.add(key);
                    appendMessage(message);
                }
                state.cursor.set(dialogUid, result.next_cursor || cursor);
                if (result.messages?.length) ui.messages.scrollTop = ui.messages.scrollHeight;
            } catch (error) {
                status(error?.message || 'Не удалось расшифровать входящие сообщения', true);
            }
        }

        async function sendMessage() {
            if (!state.active || !state.conversation || !app.currentDialog?.uid || state.pending) return;
            const dialogUid = app.currentDialog.uid;
            const text = String(ui.input.value).trim();
            if (!text) return;
            setBusy(true);
            status('Проверка доверия и шифрование сообщения…');
            try {
                await state.conversation.send(dialogUid, text);
                if (ui.input.value.trim() === text) ui.input.value = '';
                status('Шифротекст сохранён сервером. Ключ остался на устройстве.');
                await refreshMessages();
            } catch (error) {
                status(error?.message || 'Защищённая отправка отклонена', true);
            } finally {
                setBusy(false);
            }
        }

        async function sendFile() {
            const file = ui.file.files?.[0];
            ui.file.value = '';
            if (!file || !state.active || !state.conversation || !app.currentDialog?.uid || state.pending) return;
            if (file.size < 1 || file.size > 10 * 1024 * 1024) {
                status('Размер файла должен быть от 1 байта до 10 МиБ.', true);
                return;
            }
            setBusy(true);
            status('Файл шифруется на вашем устройстве…');
            try {
                await state.conversation.sendFile(
                    app.currentDialog.uid, new Uint8Array(await file.arrayBuffer()), file.name
                );
                status('Зашифрованный файл отправлен. Исходный файл серверу недоступен.');
                await refreshMessages();
            } catch (error) {
                status(error?.message || 'Не удалось отправить зашифрованный файл', true);
            } finally {
                setBusy(false);
            }
        }

        const originalOpenDialog = app.openDialog.bind(app);
        app.openDialog = (uid) => {
            togglePanel(false);
            originalOpenDialog(uid);
        };
        toggle.addEventListener('click', () => togglePanel(!state.active));
        ui.activate.addEventListener('click', () => void initialize());
        ui.refresh.addEventListener('click', () => void refreshDevices().catch(
            (error) => status(error?.message || 'Не удалось получить устройства', true)
        ));
        ui.send.addEventListener('click', () => void sendMessage());
        ui.input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                void sendMessage();
            }
        });
        ui.file.addEventListener('change', () => void sendFile());

        // Даже если старый обработчик загрузок активен, никакие файлы из
        // защищённой вкладки не должны попадать в обычный серверный Messenger.
        for (const name of ['drop', 'paste']) {
            app.el.chatActive.addEventListener(name, (event) => {
                if (!state.active) return;
                if (name === 'paste' && event.target === ui.input
                    && !(event.clipboardData?.files?.length)) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                status('Используйте отдельное поле или кнопку шифрования файла.');
            }, true);
        }
        const legacyFileInput = document.getElementById('message-file-input');
        legacyFileInput?.addEventListener('change', (event) => {
            if (!state.active) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            legacyFileInput.value = '';
            status('Обычная загрузка заблокирована в защищённой вкладке.', true);
        }, true);

        pageScope.setInterval(() => {
            if (state.active && state.conversation && !state.pending) void refreshMessages();
        }, 7000);
        pageScope.onDispose(() => {
            state.active = false;
            state.generation += 1;
            state.database?.close?.();
            state.machine?.close?.();
        });
        setReady(false);
    });
})();
});
{/literal}
