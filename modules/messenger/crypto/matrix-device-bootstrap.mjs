/**
 * Начальная синхронизация публичных ключей Matrix Rust Crypto с Notes.
 * Выполняется только в отдельном защищённом клиенте после явного согласия.
 * Полноценный E2EE требует последующей синхронизации Olm/Megolm и проверки доверия.
 */
const DEVICE_PATTERN = /^[A-Za-z0-9_-]{8,64}$/;
const UID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function notesMatrixUserId(userUid) {
    if (typeof userUid !== 'string' || !UID_PATTERN.test(userUid)) {
        throw new TypeError('Некорректный идентификатор учётной записи');
    }
    return '@' + userUid.toLowerCase() + ':notes.local';
}

export function notesMatrixRoomId(dialogUid) {
    if (typeof dialogUid !== 'string' || !UID_PATTERN.test(dialogUid)) {
        throw new TypeError('Некорректный идентификатор диалога');
    }
    return '!' + dialogUid.toLowerCase() + ':notes.local';
}

export async function openClientMachine(sdk, userUid, deviceId, passphrase) {
    if (!DEVICE_PATTERN.test(deviceId)) {
        throw new TypeError('Некорректный идентификатор устройства');
    }
    if (typeof passphrase !== 'string' || passphrase.length < 16) {
        throw new TypeError('Для локального хранилища ключей нужна отдельная длинная парольная фраза');
    }
    if (typeof window !== 'undefined' && (!window.isSecureContext || !window.indexedDB)) {
        throw new Error('Шифрование доступно только в безопасном контексте с IndexedDB');
    }
    if (!sdk || typeof sdk.initAsync !== 'function'
        || !sdk.OlmMachine?.initialize || !sdk.UserId || !sdk.DeviceId) {
        throw new Error('Проверенный движок Matrix Rust Crypto не загружен');
    }
    await sdk.initAsync();
    const userId = notesMatrixUserId(userUid);
    const storeName = 'notes-e2ee-v1-' + userUid.toLowerCase() + '-' + deviceId;
    const machine = await sdk.OlmMachine.initialize(
        new sdk.UserId(userId), new sdk.DeviceId(deviceId), storeName, passphrase
    );
    return { machine, userId, deviceId };
}

function outgoingUploads(requests) {
    const matches = [];
    for (const request of requests) {
        if (typeof request?.body !== 'string' || !request.id || request.type === undefined) continue;
        let body;
        try {
            body = JSON.parse(request.body);
        } catch {
            continue;
        }
        if (!body || typeof body !== 'object') continue;
        if ('device_keys' in body || 'one_time_keys' in body || 'fallback_keys' in body) {
            matches.push({ upload: request, body });
        }
    }
    if (matches.length > 20) {
        throw new Error('Слишком много запросов публикации ключей от криптодвижка');
    }
    return matches;
}

function mapIdentity(keys, matrixUserId, deviceId) {
    if (!keys || keys.user_id !== matrixUserId || keys.device_id !== deviceId) {
        throw new Error('Идентичность Matrix не соответствует текущему пользователю и устройству');
    }
    const signingKey = keys.keys?.['ed25519:' + deviceId];
    const agreementKey = keys.keys?.['curve25519:' + deviceId];
    const signature = keys.signatures?.[matrixUserId]?.['ed25519:' + deviceId];
    if (!signingKey || !agreementKey || typeof signature !== 'string' || !signature) {
        throw new Error('Не хватает подписанных публичных ключей устройства');
    }
    return {
        signing_key: signingKey,
        agreement_key: agreementKey,
        device_keys: keys,
    };
}

/**
 * Отправитель `post` принимает только (маршрут, объект) и должен применять
 * авторизацию и CSRF, не добавляя секретные ключи и открытый текст в запрос.
 */
export async function publishInitialKeys(machine, userUid, deviceId, post) {
    if (typeof post !== 'function') {
        throw new TypeError('Не передан защищённый отправитель запросов');
    }
    const matrixUserId = notesMatrixUserId(userUid);
    const uploads = outgoingUploads(await machine.outgoingRequests());
    let registered = false;
    let published = 0;
    for (const { upload, body } of uploads) {
        if (body.fallback_keys && Object.keys(body.fallback_keys).length > 0) {
            throw new Error('Резервные ключи ещё не поддерживаются сервером');
        }
        if (body.device_keys) {
            const identity = mapIdentity(body.device_keys, matrixUserId, deviceId);
            await post('/e2ee/devices', { device_id: deviceId, identity });
            registered = true;
        }
        const oneTimeKeys = body.one_time_keys ?? {};
        if (!oneTimeKeys || typeof oneTimeKeys !== 'object' || Array.isArray(oneTimeKeys)) {
            throw new Error('Некорректный пакет одноразовых ключей');
        }
        const ids = Object.keys(oneTimeKeys);
        for (const id of ids) {
            if (!id.startsWith('signed_curve25519:')) {
                throw new Error('Неизвестный алгоритм одноразового ключа');
            }
        }
        if (ids.length > 0) {
            await post('/e2ee/prekeys', { device_id: deviceId, keys: oneTimeKeys });
            published += ids.length;
        }
        // При частичном сетевом отказе необработанный запрос остаётся в движке.
        await machine.markRequestAsSent(upload.id, upload.type,
            JSON.stringify({ one_time_key_counts: { signed_curve25519: published } })
        );
    }
    return { registered, published };
}
