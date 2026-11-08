import { notesMatrixUserId } from './matrix-device-bootstrap.mjs';

/**
 * Перенос ВРУЧНУЮ подтверждённого отпечатка в хранилище Matrix Rust Crypto.
 * Не доверяет ответу сервера и не подтверждает автоматически новые устройства.
 */
export async function applyPinnedDeviceTrust(sdk, machine, localTrust, userUid, deviceId, signingKey) {
    if (!sdk?.UserId || !sdk?.DeviceId || sdk?.LocalTrust?.Verified === undefined
        || !machine?.getDevice || typeof localTrust?.isTrusted !== 'function') {
        throw new Error('Проверенный криптодвижок и местные подтверждения недоступны');
    }
    if (await localTrust.isTrusted(userUid, deviceId, signingKey) !== true) {
        throw new Error('Отпечаток устройства не подтверждён владельцем');
    }
    const device = await machine.getDevice(
        new sdk.UserId(notesMatrixUserId(userUid)),
        new sdk.DeviceId(deviceId)
    );
    if (!device || device.isDeleted?.() === true || device.isBlacklisted?.() === true
        || typeof device.ed25519Key?.toBase64 !== 'function'
        || typeof device.setLocalTrust !== 'function') {
        throw new Error('Подтверждённое устройство не найдено в Matrix или отозвано');
    }
    const stored = device.ed25519Key.toBase64().replace(/=+$/,'');
    const expected = signingKey.replace(/=+$/,'');
    if (stored !== expected) {
        throw new Error('Ключ устройства в Matrix отличается от локально подтверждённого');
    }
    await device.setLocalTrust(sdk.LocalTrust.Verified);
    return { user_uid: userUid, device_id: deviceId, trusted: true };
}

/**
 * Проверка отправителя после криптографической расшифровки Megolm.
 * Локально подтверждённый отпечаток обязателен: не полагаемся на
 * непроверенный серверный флаг, идентификатор автора или старый
 * механизм перекрёстных подписей, не настроенный в Notes.
 */
export async function verifyPinnedRoomSender({
    sdk, machine, localTrust, fetchDevices, senderUid, senderDeviceId,
    senderEd25519, senderCurve25519, matrixSender, encryptedSenderKey,
}) {
    if (!sdk?.UserId || !sdk?.DeviceId || !machine?.getDevice
        || typeof localTrust?.isTrusted !== 'function'
        || typeof fetchDevices !== 'function') {
        throw new Error('Не настроена проверка доверия отправителя');
    }
    const expectedUser = notesMatrixUserId(senderUid);
    if (matrixSender !== expectedUser
        || !/^[A-Za-z0-9_-]{8,64}$/.test(senderDeviceId)
        || typeof senderEd25519 !== 'string'
        || typeof senderCurve25519 !== 'string'
        || typeof encryptedSenderKey !== 'string') {
        throw new Error('Сведения об устройстве отправителя не подтверждены');
    }
    const stripPadding = value => value.replace(/=+$/, '');
    if (stripPadding(senderCurve25519) !== stripPadding(encryptedSenderKey)) {
        throw new Error('Ключ Megolm не соответствует исходному зашифрованному сообщению');
    }
    const response = await fetchDevices(senderUid);
    const devices = response?.devices;
    if (!Array.isArray(devices) || devices.length > 12) {
        throw new Error('Недоступен актуальный список устройств отправителя');
    }
    const current = devices.filter(row => row?.device_id === senderDeviceId);
    if (current.length !== 1 || !current[0].signing_key || !current[0].agreement_key) {
        throw new Error('Устройство отправителя отсутствует, отозвано или неоднозначно');
    }
    const { signing_key: signingKey, agreement_key: agreementKey } = current[0];
    if (stripPadding(signingKey) !== stripPadding(senderEd25519)
        || stripPadding(agreementKey) !== stripPadding(senderCurve25519)
        || await localTrust.isTrusted(senderUid, senderDeviceId, signingKey) !== true) {
        throw new Error('Шифрованное сообщение получено от неподтверждённого устройства');
    }
    const device = await machine.getDevice(
        new sdk.UserId(expectedUser), new sdk.DeviceId(senderDeviceId)
    );
    if (!device || device.isDeleted?.() === true || device.isBlacklisted?.() === true
        || device.isLocallyTrusted?.() !== true
        || typeof device.ed25519Key?.toBase64 !== 'function'
        || typeof device.curve25519Key?.toBase64 !== 'function'
        || stripPadding(device.ed25519Key.toBase64()) !== stripPadding(senderEd25519)
        || stripPadding(device.curve25519Key.toBase64()) !== stripPadding(senderCurve25519)) {
        throw new Error('Matrix не подтвердил ранее проверенное устройство отправителя');
    }
    return true;
}
