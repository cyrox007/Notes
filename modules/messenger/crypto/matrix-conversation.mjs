import { rotateAndShareRoomKey } from './matrix-room-keys.mjs';
import { applyPinnedDeviceTrust, verifyPinnedRoomSender } from './matrix-sdk-trust.mjs';
import { notesMatrixUserId } from './matrix-device-bootstrap.mjs';
import { handleKeyQuery } from './matrix-transport-bridge.mjs';
import { prepareEncryptedText, prepareEncryptedFileMessage, sendPreparedEncryptedText } from './matrix-room-messages.mjs';
import { encryptAttachment, decryptAttachment } from './e2ee-file-crypto.mjs';
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
    async function withVerifiedRoom(dialogUid,action) {
        if(activeRoomKeys.has(dialogUid)){
            throw new Error('Защищённая отправка в этот диалог уже выполняется');
        }
        activeRoomKeys.add(dialogUid);
        try{
            const original=validateParticipants(await transport.participants(dialogUid),userUid);
            await rotateAndShareRoomKey({
                sdk,machine,dialogUid,memberUids:original.members,deviceId,
                getDevices:uid=>transport.getDevices(uid),
                claimPrekey:(uid,device)=>transport.claimPrekey(uid,device),
                sendToDevice:payload=>transport.sendDevicePacket(payload),
                trustedDevice:(uid,device,key)=>applyPinnedDeviceTrust(
                    sdk,machine,trust,uid,device,key
                ).then(()=>true),
            });
            const current=validateParticipants(await transport.participants(dialogUid),userUid);
            if(current.fingerprint!==original.fingerprint){
                throw new Error('Состав диалога изменился во время раздачи ключей. Повторите отправку');
            }
            return await action();
        }finally{
            activeRoomKeys.delete(dialogUid);
        }
    }

    async function send(dialogUid,plaintext){
        return withVerifiedRoom(dialogUid,async()=>{
            const packet=await prepareEncryptedText(sdk,machine,dialogUid,deviceId,plaintext,secureTxn());
            return sendPreparedEncryptedText(packet,(_path,payload)=>transport.sendRoomEvent(payload));
        });
    }

    async function sendFile(dialogUid,input,originalName){
        return withVerifiedRoom(dialogUid,async()=>{
            if(!globalThis.crypto?.randomUUID){
                throw new Error('Недоступен надёжный идентификатор зашифрованного файла');
            }
            const uid=globalThis.crypto.randomUUID();
            const {ciphertext,secret}=await encryptAttachment(input,dialogUid,uid);
            // Сервер видит только шифротекст и UID, но не имя и не ключ.
            await transport.uploadCiphertext(dialogUid,uid,ciphertext);
            const packet=await prepareEncryptedFileMessage(
                sdk,machine,dialogUid,deviceId,uid,secret,originalName,secureTxn()
            );
            return sendPreparedEncryptedText(packet,(_path,payload)=>transport.sendRoomEvent(payload));
        });
    }

    async function receiveFile(dialogUid,attachment){
        if(!attachment?.uid||!attachment?.secret){
            throw new TypeError('Не хватает локального секретного дескриптора вложения');
        }
        const ciphertext=await transport.downloadCiphertext(attachment.uid);
        return decryptAttachment(ciphertext,dialogUid,attachment.uid,attachment.secret);
    }

    async function receive(dialogUid,after=0) {
        const original=validateParticipants(await transport.participants(dialogUid),userUid);
        if (!sdk?.UserId || !machine?.queryKeysForUsers) {
            throw new Error('Криптодвижок не умеет проверять устройства входящих сообщений');
        }
        // Получение не должно зависеть от того, отправлял ли сам пользователь
        // сообщения: перед обработкой ключей узнаём устройства всех участников.
        const request=await machine.queryKeysForUsers(
            original.members.map(uid=>new sdk.UserId(notesMatrixUserId(uid)))
        );
        if (request) {
            await handleKeyQuery(machine,request,uid=>transport.getDevices(uid));
        }
        // На новом устройстве локальная сверка отпечатка должна быть
        // отражена также в Matrix до расшифровки. Без этого серверный
        // список и локальный PIN согласованы, но decrypt отвергает текст.
        // Не подтверждённые владельцем устройства здесь не доверяются.
        for (const uid of original.members) {
            const registered = (await transport.getDevices(uid))?.devices;
            if (!Array.isArray(registered) || registered.length > 12) {
                throw new Error('Каталог устройств недоступен для проверки отправителя');
            }
            for (const device of registered) {
                if (!device?.device_id || !device?.signing_key) {
                    throw new Error('Неизвестная идентичность устройства');
                }
                if (await trust.isTrusted(uid,device.device_id,device.signing_key)) {
                    await applyPinnedDeviceTrust(
                        sdk,machine,trust,uid,device.device_id,device.signing_key
                    );
                }
            }
        }
        const keys = await receiveDevicePackages(
            sdk,machine,deviceId,
            device=>transport.inbox(device),
            (device,ids)=>transport.acknowledge(device,ids)
        );
        const timeline = await decryptRoomTimeline(
            sdk,machine,dialogUid,
            (room,cursor)=>transport.roomEvents(room,cursor),after,
            details=>verifyPinnedRoomSender({
                sdk,machine,localTrust:trust,
                fetchDevices:uid=>transport.getDevices(uid),...details
            })
        );
        const current=validateParticipants(await transport.participants(dialogUid),userUid);
        if (current.fingerprint!==original.fingerprint) {
            throw new Error('Состав участников изменился при чтении зашифрованной истории');
        }
        return { ...timeline, new_keys:keys.received };
    }
    return Object.freeze({send,sendFile,receive,receiveFile});
}
