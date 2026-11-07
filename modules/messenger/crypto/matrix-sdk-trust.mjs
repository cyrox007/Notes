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
