import { notesMatrixRoomId } from './matrix-device-bootstrap.mjs';

/**
 * Подготовка сообщения Megolm после установки сессий и раздачи комнатного ключа.
 * Никакой автоматической отправки обычного текста в серверные маршруты нет.
 */
export async function prepareEncryptedText(sdk, machine, dialogUid, senderDeviceId, text, transactionId) {
    if (typeof text !== 'string' || text.trim().length === 0 || text.length > 4096) {
        throw new TypeError('Сообщение отсутствует или превышает допустимую длину');
    }
    if (typeof transactionId !== 'string' || !/^[A-Za-z0-9_-]{16,80}$/.test(transactionId)) {
        throw new TypeError('Нужен устойчивый идентификатор зашифрованной передачи');
    }
    if (!sdk?.RoomId || !machine?.encryptRoomEvent) {
        throw new Error('Криптографическая сессия Matrix не подготовлена');
    }
    const encryptedText = await machine.encryptRoomEvent(
        new sdk.RoomId(notesMatrixRoomId(dialogUid)),
        'm.room.message',
        JSON.stringify({ msgtype: 'm.text', body: text })
    );
    const encrypted = JSON.parse(encryptedText);
    if (encrypted?.algorithm !== 'm.megolm.v1.aes-sha2'
        || encrypted.device_id !== senderDeviceId
        || !encrypted.ciphertext || !encrypted.session_id || !encrypted.sender_key) {
        throw new Error('Движок вернул неподдерживаемый формат шифротекста');
    }
    return Object.freeze({
        sender_device_id: senderDeviceId,
        dialog_uid: dialogUid,
        transaction_id: transactionId,
        encrypted: Object.freeze(encrypted),
    });
}

/**
 * Файл публикуется как m.file, но его имя и AES-GCM-дескриптор помещаются
 * ТОЛЬКО внутрь Megolm-события. Сервер получает лишь uid вложения.
 */
export async function prepareEncryptedFileMessage(
    sdk, machine, dialogUid, senderDeviceId, attachmentUid,
    secret, originalName, transactionId
) {
    if (typeof attachmentUid !== 'string'
        || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(attachmentUid)
        || secret?.version !== 1 || secret?.algorithm !== 'AES-256-GCM'
        || secret?.attachment_uid !== attachmentUid
        || typeof secret?.key !== 'string' || typeof secret?.iv !== 'string') {
        throw new TypeError('Неверный секретный дескриптор зашифрованного файла');
    }
    if (typeof originalName !== 'string' || !originalName.trim()
        || originalName.length > 255 || /[\\/\x00-\x1F]/.test(originalName)) {
        throw new TypeError('Неверное имя исходного файла');
    }
    if (!/^[A-Za-z0-9_-]{16,80}$/.test(transactionId)
        || !sdk?.RoomId || !machine?.encryptRoomEvent) {
        throw new Error('Шифрованный сеанс сообщения не подготовлен');
    }
    const content = {
        msgtype: 'm.file',
        body: originalName,
        file: {
            uri: 'notes-e2ee://' + attachmentUid,
            secret,
        },
    };
    const encoded = await machine.encryptRoomEvent(
        new sdk.RoomId(notesMatrixRoomId(dialogUid)),
        'm.room.message',
        JSON.stringify(content)
    );
    const encrypted = JSON.parse(encoded);
    if (encrypted?.algorithm !== 'm.megolm.v1.aes-sha2'
        || encrypted.device_id !== senderDeviceId
        || !encrypted.ciphertext || !encrypted.session_id || !encrypted.sender_key) {
        throw new Error('Движок не вернул шифротекст файла');
    }
    return Object.freeze({
        sender_device_id: senderDeviceId,
        dialog_uid: dialogUid,
        transaction_id: transactionId,
        encrypted: Object.freeze(encrypted),
        attachment_uids: [attachmentUid],
    });
}

/**
 * Повторная попытка должна использовать тот же подготовленный пакет и txn,
 * а не повторно шифровать открытый текст.
 */
export async function sendPreparedEncryptedText(prepared, post) {
    if (!prepared?.encrypted || typeof post !== 'function') {
        throw new TypeError('Некорректный подготовленный зашифрованный пакет');
    }
    const result = await post('/e2ee/room-events', prepared);
    if (result?.stored !== true) {
        throw new Error('Сервер не подтвердил сохранение зашифрованного сообщения');
    }
    return result;
}
