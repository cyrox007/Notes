import { notesMatrixRoomId, notesMatrixUserId } from './matrix-device-bootstrap.mjs';

/**
 * Обработка шифрованных сообщений исключительно в локальном криптодвижке.
 * Ни один расшифрованный текст не передаётся обратно серверу Notes.
 */
function positiveId(value) {
    if (!Number.isSafeInteger(value) || value <= 0) {
        throw new TypeError('Некорректный курсор зашифрованного сообщения');
    }
    return value;
}

function checkedMailbox(messages, targetDevice) {
    if (!Array.isArray(messages) || messages.length > 50) {
        throw new TypeError('Некорректная порция зашифрованных пакетов');
    }
    return messages.map((row) => {
        positiveId(row?.id);
        if (row?.recipient_device_id && row.recipient_device_id !== targetDevice) {
            throw new Error('Пакет получен не для текущего устройства');
        }
        const content = row?.envelope;
        if (!content || content.algorithm !== 'm.olm.v1.curve25519-aes-sha2'
            || typeof content.sender_key !== 'string' || !content.ciphertext
            || typeof row.sender_device_id !== 'string') {
            throw new Error('Некорректный зашифрованный пакет для устройства');
        }
        const sender = notesMatrixUserId(row.sender_user_uid);
        return {
            type: 'm.room.encrypted',
            sender,
            content,
        };
    });
}

function checkedRoomEvents(events, dialogUid) {
    if (!Array.isArray(events) || events.length > 100) {
        throw new TypeError('Некорректная страница зашифрованных событий');
    }
    return events.map((row) => {
        positiveId(row?.id);
        const content = row?.encrypted;
        if (!content || content.algorithm !== 'm.megolm.v1.aes-sha2'
            || typeof content.ciphertext !== 'string' || !content.sender_key) {
            throw new Error('Обнаружено незашифрованное сообщение');
        }
        const sender = notesMatrixUserId(row.sender_uid);
        const created = String(row.created_at || '').replace(' ', 'T');
        const timestamp = Date.parse(created.endsWith('Z') ? created : created + 'Z');
        if (!Number.isFinite(timestamp)) {
            throw new Error('Неверная дата зашифрованного сообщения');
        }
        return {
            event_id: '$notes-' + row.id + ':notes.local',
            origin_server_ts: timestamp,
            room_id: notesMatrixRoomId(dialogUid),
            sender,
            type: 'm.room.encrypted',
            content,
        };
    });
}

/**
 * Подтверждаем получение лишь после успешной записи ключей криптодвижком.
 * Не подтверждаем незнакомые пакеты или неразбираемые ответы.
 */
export async function receiveDevicePackages(sdk, machine, deviceId, fetchInbox, acknowledge) {
    if (!sdk?.DeviceLists || !machine?.receiveSyncChangesMsc4186
        || typeof fetchInbox !== 'function' || typeof acknowledge !== 'function') {
        throw new Error('Защищённый приёмник устройства не подготовлен');
    }
    const response = await fetchInbox(deviceId);
    const messages = response?.messages;
    const converted = checkedMailbox(messages, deviceId);
    if (converted.length === 0) {
        return { received: 0, acknowledged: 0 };
    }
    // Модифицированная семантика /sync: отсутствующие счётчики ключей
    // не обнуляют состояние локального криптодвижка.
    const result = await machine.receiveSyncChangesMsc4186(
        JSON.stringify(converted), new sdk.DeviceLists(), new Map()
    );
    if (!Array.isArray(result) || result.length !== converted.length) {
        throw new Error('Криптодвижок не обработал всю порцию ключевых пакетов');
    }
    // Для неизвестного формата библиотека возвращает необработанное событие.
    // Такая порция остаётся на сервере и должна пройти диагностику.
    if (result.some((event) => event?.error || event?.kind === 'invalid')) {
        throw new Error('Неподдерживаемое ключевое событие, подтверждение запрещено');
    }
    const ids = messages.map((row) => row.id);
    const confirmation = await acknowledge(deviceId, ids);
    if (confirmation?.acknowledged !== true) {
        throw new Error('Подтверждение серверу не завершено');
    }
    return { received: messages.length, acknowledged: ids.length };
}

/**
 * Для показа текста требуется подтверждённая идентичность отправителя.
 * Отказ одного события не превращается в пустое сообщение.
 */
export async function decryptRoomTimeline(sdk, machine, dialogUid, fetchEvents, after = 0) {
    if (!sdk?.RoomId || !sdk?.DecryptionSettings
        || sdk?.TrustRequirement?.CrossSigned === undefined || !machine?.decryptRoomEvent) {
        throw new Error('Криптодвижок с проверкой доверия недоступен');
    }
    if (!Number.isSafeInteger(after) || after < 0) {
        throw new TypeError('Некорректный курсор сообщений');
    }
    const rows = (await fetchEvents(dialogUid, after))?.events;
    const converted = checkedRoomEvents(rows, dialogUid);
    const room = new sdk.RoomId(notesMatrixRoomId(dialogUid));
    const settings = new sdk.DecryptionSettings(sdk.TrustRequirement.CrossSigned);
    const messages = [];
    for (let index = 0; index < converted.length; index++) {
        const result = await machine.decryptRoomEvent(JSON.stringify(converted[index]), room, settings);
        const decrypted = JSON.parse(result?.event || '');
        if (decrypted.type !== 'm.room.message' || decrypted.content?.msgtype !== 'm.text'
            || typeof decrypted.content?.body !== 'string'
            || decrypted.content.body.length > 4096
            || decrypted.sender !== converted[index].sender) {
            throw new Error('Содержимое расшифрованного события не соответствует отправителю');
        }
        messages.push({
            id: rows[index].id,
            sender_uid: rows[index].sender_uid,
            text: decrypted.content.body,
            created_at: rows[index].created_at,
            verified: true,
        });
    }
    return { messages, next_cursor: rows.length ? rows[rows.length - 1].id : after };
}
