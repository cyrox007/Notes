import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import * as sdk from '@matrix-org/matrix-sdk-crypto-wasm';
import {notesMatrixUserId,notesMatrixRoomId,publishInitialKeys}
    from '../../modules/messenger/crypto/matrix-device-bootstrap.mjs';
import {handleKeyQuery,handleKeyClaim,handleToDevice}
    from '../../modules/messenger/crypto/matrix-transport-bridge.mjs';
import {receiveDevicePackages,decryptRoomTimeline}
    from '../../modules/messenger/crypto/matrix-incoming.mjs';
import {verifyPinnedRoomSender}
    from '../../modules/messenger/crypto/matrix-sdk-trust.mjs';
import {createEncryptedRoomKeyArchive,restoreEncryptedRoomKeyArchive}
    from '../../modules/messenger/crypto/matrix-room-key-archive.mjs';

globalThis.crypto ??= webcrypto;

const senderUid='11111111-1111-4111-8111-111111111111';
const recipientUid='22222222-2222-4222-8222-222222222222';
const dialogUid='77777777-7777-4777-8777-777777777777';
const oldId='DEVICE_BOB_PC',newId='DEVICE_BOB_PHONE',aliceId='DEVICE_ALICE';
const password='Отдельная парольная фраза архива из двадцати пяти символов 2026';

test('Настоящий Matrix: зашифрованный экспорт восстанавливает историю ДО регистрации телефона',async()=>{
    await sdk.initAsync();
    const machines=[];
    const devices=new Map();
    const prekeys=new Map();
    const inbox=new Map();
    const uidFor=uid=>new sdk.UserId(notesMatrixUserId(uid));
    const getDevices=async uid=>({devices:devices.get(uid)||[]});
    const claim=async(uid,device)=>{
        const queue=prekeys.get(uid+':'+device)||[];
        return {prekey:queue.shift()||null};
    };
    async function create(uid,device){
        const machine=await sdk.OlmMachine.initialize(uidFor(uid),new sdk.DeviceId(device));
        machines.push(machine);
        await publishInitialKeys(machine,uid,device,async(route,body)=>{
            if(route==='/e2ee/devices'){
                const identity=body.identity;
                devices.set(uid,[...(devices.get(uid)||[]),{
                    device_id:device,signing_key:identity.signing_key,
                    agreement_key:identity.agreement_key,identity,
                }]);
                return {registered:true};
            }
            if(route==='/e2ee/prekeys'){
                const k=uid+':'+device,existing=prekeys.get(k)||[];
                existing.push(...Object.entries(body.keys)
                    .map(([key_id,key])=>({device_id:device,key_id,key})));
                prekeys.set(k,existing);
                return {published:true};
            }
            throw Error('Неподдерживаемый маршрут');
        });
        return machine;
    }
    async function discover(machine,users){
        const request=await machine.queryKeysForUsers(users.map(uidFor));
        if(request)await handleKeyQuery(machine,request,getDevices);
        for(const uid of users){
            for(const row of devices.get(uid)||[]){
                const device=await machine.getDevice(uidFor(uid),new sdk.DeviceId(row.device_id));
                assert.ok(device,'Подписанная идентичность устройства должна быть видна');
                await device.setLocalTrust(sdk.LocalTrust.Verified);
            }
        }
    }
    const trust={
        isTrusted:async(uid,device,key)=>(devices.get(uid)||[])
            .some(x=>x.device_id===device&&x.signing_key===key)
    };
    const restoredSessions=[];
    async function read(machine,event){
        return decryptRoomTimeline(sdk,machine,dialogUid,async()=>({events:[event]}),0,
            details=>verifyPinnedRoomSender({
                ...details,sdk,machine,localTrust:trust,fetchDevices:getDevices
            }),
            details=>restoredSessions.some(session=>
                session.room_id===notesMatrixRoomId(details.dialogUid)
                && session.session_id===details.sessionId
                && session.sender_key===details.senderKey
                && session.sender_claimed_keys.ed25519===details.senderClaimedEd25519Key
            )
        );
    }
    let completed=false;
    try{
        const alice=await create(senderUid,aliceId);
        const bobPc=await create(recipientUid,oldId);
        await discover(alice,[senderUid,recipientUid]);
        await discover(bobPc,[senderUid,recipientUid]);
        const participants=[senderUid,recipientUid].map(uidFor);
        const missing=await alice.getMissingSessions(participants);
        if(missing)await handleKeyClaim(alice,missing,claim);
        const room=new sdk.RoomId(notesMatrixRoomId(dialogUid));
        const options=new sdk.EncryptionSettings();
        options.sharingStrategy=sdk.CollectStrategy.onlyTrustedDevices();
        const packets=await alice.shareRoomKey(room,participants,options);
        for(const item of packets){
            await handleToDevice(alice,item,aliceId,dialogUid,async body=>{
                const target=body.recipient_user_uid+':'+body.recipient_device_id;
                inbox.set(target,[...(inbox.get(target)||[]),{
                    id:inbox.size+1,sender_user_uid:senderUid,
                    sender_device_id:aliceId,recipient_device_id:body.recipient_device_id,
                    envelope:body.envelope,
                }]);
                return {queued:true};
            });
        }
        const received=await receiveDevicePackages(
            sdk,bobPc,oldId,async()=>({messages:inbox.get(recipientUid+':'+oldId)||[]}),
            async()=>({acknowledged:true})
        );
        assert.ok(received.received>0,'Старый компьютер получил комнатный ключ');
        const plaintext='Это старое сообщение было отправлено ещё до подключения нового телефона';
        const encrypted=JSON.parse(await alice.encryptRoomEvent(
            room,'m.room.message',JSON.stringify({msgtype:'m.text',body:plaintext})
        ));
        const event={
            id:1,created_at:'2026-11-07 18:00:00',
            sender_uid:senderUid,sender_device_id:aliceId,encrypted,
        };
        assert.deepEqual((await read(bobPc,event)).messages.map(x=>x.text),[plaintext]);

        // Новый аппарат появляется ПОСЛЕ сообщения и не имеет его сеансового ключа.
        const bobPhone=await create(recipientUid,newId);
        await discover(bobPhone,[senderUid,recipientUid]);
        await assert.rejects(read(bobPhone,event),'До восстановления старая история недоступна');

        const created=await createEncryptedRoomKeyArchive({
            sdk,machine:bobPc,userUid:recipientUid,passphrase:password
        });
        assert.ok(created.keyCount>0);
        assert.equal(created.archive.includes(plaintext),false);
        assert.equal(created.archive.includes('session_key'),false);
        assert.equal(created.archive.includes('sender_key'),false);
        const forged=JSON.parse(created.archive);
        await assert.rejects(restoreEncryptedRoomKeyArchive({
            sdk,machine:bobPhone,userUid:recipientUid,passphrase:password+'НЕВЕРНО',
            archive:created.archive
        }),/Пароль неверен|повреждён/);
        await assert.rejects(restoreEncryptedRoomKeyArchive({
            sdk,machine:bobPhone,userUid:senderUid,passphrase:password,
            archive:created.archive
        }),/Чужая учётная запись/);
        await assert.rejects(restoreEncryptedRoomKeyArchive({
            sdk,machine:bobPhone,userUid:recipientUid,passphrase:password,
            archive:JSON.stringify({...forged,key_count:forged.key_count+1})
        }),/Количество ключей/);
        await assert.rejects(read(bobPhone,event),
            'Ошибочный импорт не должен раскрывать историю');
        const restored=await restoreEncryptedRoomKeyArchive({
            sdk,machine:bobPhone,userUid:recipientUid,passphrase:password,
            archive:created.archive
        });
        assert.ok(restored.importedCount>0,'Новый телефон должен сохранить ключи старой истории');
        restoredSessions.push(...restored.sessions);
        const recovered = await read(bobPhone,event);
        assert.deepEqual(recovered.messages.map(x=>x.text),[plaintext]);
        assert.equal(recovered.messages[0].restored,true);
        assert.equal(recovered.messages[0].verified,false,
            'Импортированная история не должна выдавать себя за живую проверку устройства');
        const duplicate=await restoreEncryptedRoomKeyArchive({
            sdk,machine:bobPhone,userUid:recipientUid,passphrase:password,
            archive:created.archive
        });
        assert.equal(duplicate.totalCount,restored.totalCount);
        assert.deepEqual((await read(bobPhone,event)).messages.map(x=>x.text),[plaintext],
            'Повторный импорт не должен портить уже прочитанную историю');
        completed=true;
    }finally{
        for(const machine of machines){
            try {
                await machine.close();
            } catch(error) {
                // Ошибка закрытия не должна скрывать первопричину падения теста.
                if(completed)throw error;
            }
        }
    }
});
