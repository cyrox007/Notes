import test from 'node:test';
import assert from 'node:assert/strict';
import * as sdk from '@matrix-org/matrix-sdk-crypto-wasm';
import {webcrypto} from 'node:crypto';
import {notesMatrixUserId,notesMatrixRoomId,publishInitialKeys} from '../../modules/messenger/crypto/matrix-device-bootstrap.mjs';
import {handleKeyQuery,handleKeyClaim,handleToDevice} from '../../modules/messenger/crypto/matrix-transport-bridge.mjs';
import {receiveDevicePackages,decryptRoomTimeline} from '../../modules/messenger/crypto/matrix-incoming.mjs';
import {verifyPinnedRoomSender} from '../../modules/messenger/crypto/matrix-sdk-trust.mjs';

globalThis.crypto??=webcrypto;

const a='11111111-1111-4111-8111-111111111111';
const b='22222222-2222-4222-8222-222222222222';
const d='77777777-7777-4777-8777-777777777777';
const alice='DEVICE_ALICE',bob='DEVICE_BOB';

test('настоящий Matrix Rust Crypto: два клиента, Olm, Megolm и доверенная расшифровка',async()=>{
    await sdk.initAsync();
    const machines=new Map();
    const registered=new Map();
    const prekeys=new Map();
    const inbox=new Map();
    const participants=[a,b];
    const own=[{uid:a,id:alice},{uid:b,id:bob}];

    const getDevices=async uid=>({devices:(registered.get(uid)||[]).map(v=>({...v}))});
    const claim=async(uid,deviceId)=>{
        const key=uid+':'+deviceId;
        const available=prekeys.get(key)||[];
        const item=available.shift();
        return {prekey:item??null};
    };
    const post=(uid,deviceId)=>async(route,body)=>{
        if(route==='/e2ee/devices'){
            const identity=body.identity;
            const row={device_id:deviceId,signing_key:identity.signing_key,
                agreement_key:identity.agreement_key,identity};
            registered.set(uid,[row]);
            return {registered:true};
        }
        if(route==='/e2ee/prekeys'){
            const key=uid+':'+deviceId;
            const items=Object.entries(body.keys).map(([key_id,key])=>({device_id:deviceId,key_id,key}));
            prekeys.set(key,(prekeys.get(key)||[]).concat(items));
            return {published:items.length};
        }
        throw Error('Неизвестная операция публикации ключей');
    };
    try{
        for(const {uid,id} of own){
            const m=await sdk.OlmMachine.initialize(
                new sdk.UserId(notesMatrixUserId(uid)),new sdk.DeviceId(id)
            );
            machines.set(uid,m);
            await publishInitialKeys(m,uid,id,post(uid,id));
        }
        const [sender,receiver]=[machines.get(a),machines.get(b)];
        for(const {uid} of own){
            const m=machines.get(uid);
            const query=await m.queryKeysForUsers(participants.map(u=>new sdk.UserId(notesMatrixUserId(u))));
            if(query)await handleKeyQuery(m,query,getDevices);
        }
        // Здесь нет доверия к серверному claimed-verified: тестируем явное
        // подтверждение устройств в машинном хранилище.
        for(const {uid} of own){
            const m=machines.get(uid);
            for(const other of own){
                const device=await m.getDevice(
                    new sdk.UserId(notesMatrixUserId(other.uid)),new sdk.DeviceId(other.id)
                );
                assert.ok(device,'Обе стороны должны увидеть подписанную идентичность');
                await device.setLocalTrust(sdk.LocalTrust.Verified);
            }
        }
        const missing=await sender.getMissingSessions(participants.map(uid=>new sdk.UserId(notesMatrixUserId(uid))));
        if(missing)await handleKeyClaim(sender,missing,claim);

        const room=new sdk.RoomId(notesMatrixRoomId(d));
        const settings=new sdk.EncryptionSettings();
        settings.sharingStrategy=sdk.CollectStrategy.onlyTrustedDevices();
        const keyPackets=await sender.shareRoomKey(
            room,participants.map(uid=>new sdk.UserId(notesMatrixUserId(uid))),settings
        );
        assert.ok(Array.isArray(keyPackets),'Отправитель должен получить пакетные запросы ключей');
        for(const packet of keyPackets){
            await handleToDevice(sender,packet,alice,d,async data=>{
                const target=data.recipient_user_uid+':'+data.recipient_device_id;
                inbox.set(target,[...(inbox.get(target)||[]),{
                    id:inbox.size+1,
                    sender_user_uid:a,sender_device_id:alice,
                    recipient_device_id:data.recipient_device_id,
                    envelope:data.envelope,
                }]);
                return {queued:true};
            });
        }
        const received=await receiveDevicePackages(
            sdk,receiver,bob,
            async()=>({messages:inbox.get(b+':'+bob)||[]}),
            async()=>({acknowledged:true})
        );
        assert.ok(received.received>0,'Получатель должен получить комнатный ключ по Olm');
        const original='Проверка настоящего Olm и Megolm между двумя клиентами';
        const ciphertext=JSON.parse(await sender.encryptRoomEvent(
            room,'m.room.message',JSON.stringify({msgtype:'m.text',body:original})
        ));
        assert.equal(ciphertext.algorithm,'m.megolm.v1.aes-sha2');
        const rows=[{
            id:1,created_at:'2026-11-07 15:00:00',sender_uid:a,
            sender_device_id:alice,encrypted:ciphertext
        }];
        const localTrust={isTrusted:async(uid,deviceId,key)=>{
            const value=(registered.get(uid)||[]).find(x=>x.device_id===deviceId);
            return Boolean(value&&value.signing_key===key);
        }};
        const timeline=await decryptRoomTimeline(
            sdk,receiver,d,async()=>({events:rows}),0,
            details=>verifyPinnedRoomSender({
                ...details,sdk,machine:receiver,localTrust,
                fetchDevices:getDevices
            })
        );
        assert.deepEqual(timeline.messages.map(m=>m.text),[original]);
        assert.equal(timeline.next_cursor,1);
    }finally{
        for(const machine of machines.values())await machine.close();
    }
});
