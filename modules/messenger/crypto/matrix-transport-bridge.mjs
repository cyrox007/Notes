/**
 * Перевод служебных запросов Matrix Rust Crypto в E2EE-маршруты Notes.
 * Никаких операций с секретными ключами и открытым текстом здесь нет.
 * Не вызывать из обычного Messenger до включения полноценного криптоклиента.
 */
const NOTES_USER = /^@([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}):notes\.local$/i;

export function notesUserFromMatrix(matrixUserId) {
    const matched = typeof matrixUserId === 'string' ? NOTES_USER.exec(matrixUserId) : null;
    if (!matched) {
        throw new TypeError('Получатель Matrix не принадлежит пространству Notes');
    }
    return matched[1].toLowerCase();
}

function requestBody(request) {
    if (typeof request?.body !== 'string' || !request.id || request.type === undefined) {
        throw new TypeError('Неизвестный криптографический запрос Matrix');
    }
    const data = JSON.parse(request.body);
    if (!data || typeof data !== 'object' || Array.isArray(data)) {
        throw new TypeError('Некорректный запрос криптографического движка');
    }
    return data;
}

function entries(object, max) {
    if (!object || typeof object !== 'object' || Array.isArray(object)) {
        throw new TypeError('Ожидается объект получателей Matrix');
    }
    const items = Object.entries(object);
    if (items.length < 1 || items.length > max) {
        throw new RangeError('Недопустимое количество получателей');
    }
    return items;
}

async function mark(machine, request, result) {
    await machine.markRequestAsSent(request.id, request.type, JSON.stringify(result));
}

export async function handleKeyQuery(machine, request, getDevices) {
    const body = requestBody(request);
    const deviceKeys = {};
    for (const [matrixUid, wanted] of entries(body.device_keys, 25)) {
        if (!Array.isArray(wanted) || wanted.length > 12) {
            throw new TypeError('Некорректное ограничение устройств');
        }
        const uid = notesUserFromMatrix(matrixUid);
        const response = await getDevices(uid);
        if (!Array.isArray(response?.devices)) {
            throw new Error('Реестр устройств недоступен');
        }
        deviceKeys[matrixUid] = {};
        for (const row of response.devices) {
            const keys = row?.identity?.device_keys;
            if (!keys || keys.user_id !== matrixUid
                || keys.device_id !== row.device_id) {
                throw new Error('Реестр вернул несогласованную идентичность');
            }
            if (wanted.length === 0 || wanted.includes(row.device_id)) {
                deviceKeys[matrixUid][row.device_id] = keys;
            }
        }
    }
    const result = { device_keys: deviceKeys, master_keys: {}, self_signing_keys: {}, user_signing_keys: {} };
    await mark(machine, request, result);
    return result;
}

export async function handleKeyClaim(machine, request, claim) {
    const body = requestBody(request);
    const oneTimeKeys = {};
    for (const [matrixUid, devices] of entries(body.one_time_keys, 25)) {
        const uid = notesUserFromMatrix(matrixUid);
        oneTimeKeys[matrixUid] = {};
        for (const [deviceId, algorithm] of entries(devices, 12)) {
            if (algorithm !== 'signed_curve25519') {
                throw new Error('Запрошен неподдерживаемый алгоритм ключей');
            }
            const result = await claim(uid, deviceId);
            if (result?.prekey === null) {
                continue;
            }
            const key = result?.prekey;
            if (!key || key.device_id !== deviceId
                || !key.key_id?.startsWith('signed_curve25519:')
                || !key.key?.signatures?.[matrixUid]) {
                throw new Error('Получен неподписанный или чужой одноразовый ключ');
            }
            oneTimeKeys[matrixUid][deviceId] = { [key.key_id]: key.key };
        }
    }
    const result = { one_time_keys: oneTimeKeys, failures: {} };
    await mark(machine, request, result);
    return result;
}

async function stableTransactionId(requestId, recipientUid, deviceId) {
    if (!globalThis.crypto?.subtle) {
        throw new Error('Требуется безопасный контекст криптографии');
    }
    const bytes = new TextEncoder().encode([requestId, recipientUid, deviceId].join('\0'));
    const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
}

export async function handleToDevice(machine, request, senderDeviceId, dialogUid, send) {
    const body = requestBody(request);
    const messages = body.messages ?? body;
    for (const [matrixUid, devices] of entries(messages, 25)) {
        const uid = notesUserFromMatrix(matrixUid);
        for (const [deviceId, envelope] of entries(devices, 12)) {
            if (envelope?.algorithm !== 'm.olm.v1.curve25519-aes-sha2'
                || !envelope?.sender_key || !envelope?.ciphertext) {
                throw new Error('Запрос содержит незашифрованный пакет');
            }
            await send({
                sender_device_id: senderDeviceId,
                recipient_user_uid: uid,
                recipient_device_id: deviceId,
                dialog_uid: dialogUid,
                transaction_id: await stableTransactionId(request.id, uid, deviceId),
                envelope,
            });
        }
    }
    // Только после успешной доставки на ВСЕ устройства.
    // При частичной ошибке одинаковый идентификатор исключит дубли при повторе.
    await mark(machine, request, {});
    return { queued: true };
}
