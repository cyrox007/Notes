import { rotateAndShareRoomKey } from './matrix-room-keys.mjs';
import { applyPinnedDeviceTrust } from './matrix-sdk-trust.mjs';
import { prepareEncryptedText, sendPreparedEncryptedText } from './matrix-room-messages.mjs';
import { receiveDevicePackages, decryptRoomTimeline } from './matrix-incoming.mjs';

const secureTxn = () => {
    if (!globalThis.crypto?.randomUUID) {
        throw new Error('Необходим надёжный генератор случайных идентификаторов');
    }
    return globalThis.crypto.randomUUID().replace(/-/g, '');
};

function validateParticipants(response, ownUserUid) {
    const users = response?.members;
    if (!Array.isArray(users) || users.length < 1 || users.length > 100
        || new Set(users).size !== users.length || !users.includes(ownUserUid)
        || typeof response.fingerprint !== 'string'
        || !/^[a-f0-9]{64}$/.test(response.fingerprint)) {
        throw new Error('Состав участников защищённого диалога не подтверждён');
    }
    return { members:users, fingerprint:response.fingerprint };
}

/**
 * Изолированный клиент E2EE. Не подключать к обычным чатам, пока нет
 * завершённого интерфейса и реальной многодевайсной криптографической приёмки.
 */
export function createE2eeConversation({sdk,machine,transport,trust,userUid,deviceId}) {
    if (!sdk || !machine || !transport || !trust) {
        throw new Error('Защищённый клиент не инициализирован');
    }
    const activeRoomKeys = new Set();
    async function send(dialogUid,plaintext) {
        const original = validateParticipants(await transport.participants(dialogUid),userUid);
        if (activeRoomKeys.has(dialogUid)) {
            throw new Error('Защищённая отправка в этот диалог уже выполняется');
        }
        activeRoomKeys.add(dialogUid);
        try {
            await rotateAndShareRoomKey({
                sdk,machine,dialogUid,memberUids:original.members,deviceId,
                getDevices:uid=>transport.getDevices(uid),
                claimPrekey:(uid,device)=>transport.claimPrekey(uid,device),
                sendToDevice:payload=>transport.sendDevicePacket(payload),
                trustedDevice:(uid,device,key)=>applyPinnedDeviceTrust(
                    sdk,machine,trust,uid,device,key
                ).then(()=>true),
            });
            const current = validateParticipants(await transport.participants(dialogUid),userUid);
            if (current.fingerprint !== original.fingerprint) {
                throw new Error('Состав диалога изменился во время раздачи ключей. Повторите отправку');
            }
            const packet = await prepareEncryptedText(
                sdk,machine,dialogUid,deviceId,plaintext,secureTxn()
            );
            return sendPreparedEncryptedText(packet,(_path,payload)=>transport.sendRoomEvent(payload));
        } finally {
            activeRoomKeys.delete(dialogUid);
        }
    }
    async function receive(dialogUid,after=0) {
        const keys = await receiveDevicePackages(
            sdk,machine,deviceId,
            device=>transport.inbox(device),
            (device,ids)=>transport.acknowledge(device,ids)
        );
        const timeline = await decryptRoomTimeline(
            sdk,machine,dialogUid,
            (room,cursor)=>transport.roomEvents(room,cursor),after
        );
        return { ...timeline, new_keys:keys.received };
    }
    return Object.freeze({send,receive});
}
