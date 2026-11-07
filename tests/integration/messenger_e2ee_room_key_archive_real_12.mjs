import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import * as sdk from '@matrix-org/matrix-sdk-crypto-wasm';
import {notesMatrixUserId,notesMatrixRoomId,publishInitialKeys} from '../../modules/messenger/crypto/matrix-device-bootstrap.mjs';
import {handleKeyQuery,handleKeyClaim,handleToDevice} from '../../modules/messenger/crypto/matrix-transport-bridge.mjs';
import {receiveDevicePackages,decryptRoomTimeline} from '../../modules/messenger/crypto/matrix-incoming.mjs';
import {verifyPinnedRoomSender} from '../../modules/messenger/crypto/matrix-sdk-trust.mjs';
import {
    createEncryptedRoomKeyArchive,restoreEncryptedRoomKeyArchive
} from '../../modules/messenger/crypto/matrix-room-key-archive.mjs';

globalThis.crypto??=webcrypto;

const owner='11111111-1111-4111-8111-111111111111';
const senderUid='22222222-2222-4222-8222-222222222222';
const dialog='77777777-7777-4777-8777-777777777777';
const passphrase='Секрет-архива-только-для-восстановления-2026';

test('настоящий Matrix: новая независимая OlmMachine восстанавливает прежнее Megolm-сообщение',async()=>{
    await sdk.initAsync();
    const devices=new Map();
    const prekeys=new Map();
    const inbox=new Map();
    const opened=[];
    let nextPacketId=0;

    const getDevices=async uid=>({devices:(devices.get(uid)||[]).map(v=>({...v}))});
    const claim=async(uid,deviceId)=>{
        const list=prekeys.get(uid+':'+deviceId)||[];
        const item=list.shift();
        return {prekey:item??null};
    };
    const publish=(uid,deviceId)=>async(route,body)=>{
        if(route==='/e2ee/devices'){
            const identity=body.identity;
            devices.set(uid,[{device_id:deviceId,identity,
                signing_key:identity.signing_key,agreement_key:identity.agreement_key}]);
            return {registered:true};
        }
        if(route==='/e2ee/prekeys'){
            const id=uid+':'+deviceId;
            const list=prekeys.get(id)||[];
            for(const [key_id,key] of Object.entries(body.keys)){
                list.push({device_id:deviceId,key_id,key});
            }
            prekeys.set(id,list);
            return {published:list.length};
        }
        throw Error('Неизвестный запрос публикации');
    };
    const start=async(uid,id,register=true)=>{
        const m=await sdk.OlmMachine.initialize(
            new sdk.UserId(notesMatrixUserId(uid)),new sdk.DeviceId(id)
        );
        opened.push(m);
        if(register)await publishInitialKeys(m,uid,id,publish(uid,id));
        return m;
    };
    const trust=async(m,users)=>{
        const query=await m.queryKeysForUsers(users.map(uid=>new sdk.UserId(notesMatrixUserId(uid))));
        if(query)await handleKeyQuery(m,query,getDevices);
        for(const uid of users){
            for(const row of (devices.get(uid)||[])){
                const device=await m.getDevice(
                    new sdk.UserId(notesMatrixUserId(uid)),new sdk.DeviceId(row.device_id)
                );
                assert.ok(device,'Криптодвижок должен знать проверенное устройство');
                await device.setLocalTrust(sdk.LocalTrust.Verified);
            }
        }
    };
    const deliver=async(packet,uid,id)=>handleToDevice(packet,uid,id);

    try {
        const sender=await start(senderUid,'DEVICE_HISTORY_SENDER');
        const originalDevice=await start(owner,'DEVICE_OWNER_OLD');
        await trust(sender,[senderUid,owner]);
        await trust(originalDevice,[senderUid,owner]);
        const users=[owner,senderUid];
        const missing=await sender.getMissingSessions(users.map(uid=>new sdk.UserId(notesMatrixUserId(uid))));
        if(missing)await handleKeyClaim(sender,missing,claim);
        const room=new sdk.RoomId(notesMatrixRoomId(dialog));
        const settings=new sdk.EncryptionSettings();
        settings.sharingStrategy=sdk.CollectStrategy.onlyTrustedDevices();
        const packets=await sender.shareRoomKey(
            room,users.map(uid=>new sdk.UserId(notesMatrixUserId(uid))),settings
        );
        for(const packet of packets){
            await handleToDevice(sender,packet,'DEVICE_HISTORY_SENDER',dialog,async data=>{
                const key=data.recipient_user_uid+':'+data.recipient_device_id;
                inbox.set(key,[...(inbox.get(key)||[]),{
                    id:++nextPacketId,sender_user_uid:senderUid,
                    sender_device_id:'DEVICE_HISTORY_SENDER',
                    recipient_device_id:data.recipient_device_id,envelope:data.envelope
                }]);
                return {queued:true};
            });
        }
        const got=await receiveDevicePackages(
            sdk,originalDevice,'DEVICE_OWNER_OLD',
            async()=>({messages:inbox.get(owner+':DEVICE_OWNER_OLD')||[]}),
            async()=>({acknowledged:true})
        );
        assert.ok(got.received>0,'Первое устройство должно получить Megolm-ключ');
        const plaintext='Старая история до добавления нового телефона';
        const ciphertext=JSON.parse(await sender.encryptRoomEvent(
            room,'m.room.message',JSON.stringify({msgtype:'m.text',body:plaintext})
        ));
        const rows=[{
            id:1,sender_uid:senderUid,sender_device_id:'DEVICE_HISTORY_SENDER',
            created_at:'2026-11-07 18:00:00',encrypted:ciphertext
        }];
        const localTrust={isTrusted:async(uid,id,key)=>{
            const row=(devices.get(uid)||[]).find(d=>d.device_id===id);
            return Boolean(row&&row.signing_key===key);
        }};
        const verifier=machine=>details=>verifyPinnedRoomSender({
            sdk,machine,localTrust,fetchDevices:getDevices,...details
        });
        const oldHistory=await decryptRoomTimeline(
            sdk,originalDevice,dialog,async()=>({events:rows}),0,verifier(originalDevice)
        );
        assert.deepEqual(oldHistory.messages.map(m=>m.text),[plaintext]);

        // Нового устройства не существовало при отправке истории.
        const newDevice=await start(owner,'DEVICE_OWNER_NEW',false);
        await trust(newDevice,[senderUid,owner]);
        await assert.rejects(decryptRoomTimeline(
            sdk,newDevice,dialog,async()=>({events:rows}),0,verifier(newDevice)
        ),'Без архива история на новом устройстве не должна открываться');

        const {archive,keyCount}=await createEncryptedRoomKeyArchive({
            sdk,machine:originalDevice,userUid:owner,passphrase
        });
        assert.ok(keyCount>=1);
        assert.equal(archive.includes(plaintext),false);
        assert.equal(archive.includes('session_key'),false);
        await assert.rejects(restoreEncryptedRoomKeyArchive({
            sdk,machine:newDevice,userUid:owner,
            passphrase:'Неверный-секретный-пароль-восстановления',archive
        }),/Пароль неверен/);

        const restored=await restoreEncryptedRoomKeyArchive({
            sdk,machine:newDevice,userUid:owner,passphrase,archive
        });
        assert.ok(restored.importedCount>=1);
        const newHistory=await decryptRoomTimeline(
            sdk,newDevice,dialog,async()=>({events:rows}),0,verifier(newDevice)
        );
        assert.deepEqual(newHistory.messages.map(m=>m.text),[plaintext]);
        // Повторная попытка не должна повреждать уже импортированный ключ.
        const again=await restoreEncryptedRoomKeyArchive({
            sdk,machine:newDevice,userUid:owner,passphrase,archive
        });
        assert.equal(again.totalCount,restored.totalCount);
        const repeat=await decryptRoomTimeline(
            sdk,newDevice,dialog,async()=>({events:rows}),0,verifier(newDevice)
        );
        assert.deepEqual(repeat.messages.map(m=>m.text),[plaintext]);
    }finally{
        for(const machine of opened)await machine.close();
    }
});
