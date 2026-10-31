import { notesMatrixRoomId, notesMatrixUserId } from './matrix-device-bootstrap.mjs';
import { handleKeyQuery, handleKeyClaim, handleToDevice } from './matrix-transport-bridge.mjs';

const inFlightRooms = new Set();

/**
 * Раздача нового комнатного ключа с принудительной проверкой ВСЕХ устройств.
 * Неавторизованное или не подтверждённое владельцем устройство не получает ключ.
 *
 * trustedDevice(userUid, deviceId, signingKey): Promise<boolean> —
 * решение только локального клиента (никогда не серверное поле verified).
 */
export async function rotateAndShareRoomKey({
    sdk, machine, dialogUid, memberUids, deviceId, getDevices, claimPrekey,
    sendToDevice, trustedDevice,
}) {
    if (!sdk?.RoomId || !sdk?.UserId || !sdk?.EncryptionSettings
        || !sdk?.CollectStrategy?.onlyTrustedDevices
        || !machine?.queryKeysForUsers || !machine?.getMissingSessions
        || !machine?.shareRoomKey || !machine?.invalidateGroupSession
        || typeof getDevices !== 'function' || typeof claimPrekey !== 'function'
        || typeof sendToDevice !== 'function' || typeof trustedDevice !== 'function') {
        throw new Error('Проверенный протокол обмена комнатными ключами недоступен');
    }
    if (!Array.isArray(memberUids) || memberUids.length < 1 || memberUids.length > 100
        || new Set(memberUids).size !== memberUids.length
        || !memberUids.every(uid => /^[a-f0-9-]{36}$/i.test(uid))) {
        throw new TypeError('Некорректный состав участников защищённого диалога');
    }
    const roomId = notesMatrixRoomId(dialogUid);
    if (inFlightRooms.has(roomId)) {
        throw new Error('Уже выполняется смена ключей для этого диалога');
    }
    inFlightRooms.add(roomId);
    try {
        const participants = memberUids.map(uid => notesMatrixUserId(uid));
        const request = machine.queryKeysForUsers(memberUids.map(uid => new sdk.UserId(notesMatrixUserId(uid))));
        await handleKeyQuery(machine, request, async uid => getDevices(uid));

        // Нельзя допустить выборочного пропуска пользователя или устройства.
        // Публичная подпись не равна доверию: нужна локальная запись после подтверждения отпечатка.
        let expectedDevices = 0;
        for (const uid of memberUids) {
            const published = (await getDevices(uid))?.devices;
            if (!Array.isArray(published) || published.length === 0 || published.length > 12) {
                throw new Error('Участник не имеет зарегистрированных устройств');
            }
            const identifiers = new Set();
            for (const device of published) {
                if (!device?.signing_key || !device?.device_id || identifiers.has(device.device_id)) {
                    throw new Error('Каталог устройств содержит подменённую или повторную запись');
                }
                identifiers.add(device.device_id);
                if (await trustedDevice(uid, device.device_id, device.signing_key) !== true) {
                    throw new Error('Обнаружено неподтверждённое устройство: раздача ключа запрещена');
                }
                expectedDevices++;
            }
        }
        if (!expectedDevices) {
            throw new Error('Для раздачи ключа нет подтверждённых устройств');
        }

        const query = await machine.getMissingSessions(
            memberUids.map(uid => new sdk.UserId(notesMatrixUserId(uid)))
        );
        if (query) {
            await handleKeyClaim(machine, query, async (uid, device) => claimPrekey(uid, device));
        }

        const room = new sdk.RoomId(roomId);
        // Делаем новую эпоху перед каждой отправкой: исключает использование
        // старого ключа после отзыва устройства или изменения состава группы.
        await machine.invalidateGroupSession(room);
        const settings = new sdk.EncryptionSettings();
        settings.sharingStrategy = sdk.CollectStrategy.onlyTrustedDevices();
        const requests = await machine.shareRoomKey(
            new sdk.RoomId(roomId),
            memberUids.map(uid => new sdk.UserId(notesMatrixUserId(uid))),
            settings
        );
        if (!Array.isArray(requests)) {
            throw new Error('Библиотека не выдала подтверждённые запросы комнатного ключа');
        }
        for (const item of requests) {
            await handleToDevice(machine, item, deviceId, dialogUid, async body => sendToDevice(body));
        }
        return { room_id: roomId, participants: participants.length, devices_checked: expectedDevices, packets: requests.length };
    } finally {
        inFlightRooms.delete(roomId);
    }
}
