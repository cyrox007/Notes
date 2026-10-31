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
