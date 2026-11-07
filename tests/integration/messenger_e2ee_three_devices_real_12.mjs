import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto, createHash } from 'node:crypto';
import * as sdk from '@matrix-org/matrix-sdk-crypto-wasm';
import { createE2eeConversation } from '../../modules/messenger/crypto/matrix-conversation.mjs';
import {
    deviceComparisonCode, confirmIndependentDevice
} from '../../modules/messenger/crypto/matrix-device-pairing.mjs';
import {
    notesMatrixUserId, publishInitialKeys
} from '../../modules/messenger/crypto/matrix-device-bootstrap.mjs';

globalThis.crypto ??= webcrypto;
const first = '11111111-1111-4111-8111-111111111111';
const second = '22222222-2222-4222-8222-222222222222';
const roomUid = '77777777-7777-4777-8777-777777777777';
const pcId = 'DEVICE_PC_ALICE';
const phoneId = 'DEVICE_PHONE_ALICE';
const peerId = 'DEVICE_PEER_BOB';

test('Matrix Rust Crypto: второй аппарат того же пользователя получает новый шифротекст только после проверки кода',async()=>{
    await sdk.initAsync();
    const devices = new Map([[first,[]],[second,[]]]);
    const availableKeys = new Map();
    const mailboxes = new Map();
    const events = [];
    const opened = [];
    const members = [first,second];
    const fingerprint = createHash('sha256').update(members.join('\n')).digest('hex');
    let nextPacket=0;

    const getDevices = async uid => ({devices:(devices.get(uid)||[]).map(row=>({...row}))});
    const claim = async (uid,id) => {
        const item=(availableKeys.get(uid+':'+id)||[]).shift();
        return {prekey:item||null};
    };
    const trustStore = () => {
        const confirmed = new Map();
        return {
            pin:async (uid,id,key,confirm)=>{
                if(await confirm({fingerprint:key})!==true) throw Error('Не подтверждено');
                confirmed.set(uid+':'+id,key);
                return true;
            },
            isTrusted:async(uid,id,key)=>confirmed.get(uid+':'+id)===key,
        };
    };
    const register = async (uid,deviceId,route,body) => {
        if(route==='/e2ee/devices'){
            const identity=body.identity;
            devices.get(uid).push({
                device_id:deviceId,
                signing_key:identity.signing_key,
                agreement_key:identity.agreement_key,
                identity,
                verified:false,
            });
            return {registered:true};
        }
        if(route==='/e2ee/prekeys'){
            const key=uid+':'+deviceId;
            const list=availableKeys.get(key)||[];
            for(const [key_id,value] of Object.entries(body.keys)) {
                list.push({device_id:deviceId,key_id,key:value});
            }
            availableKeys.set(key,list);
            return {published:Object.keys(body.keys).length};
        }
        throw Error('Неизвестная операция');
    };
    const transport = (senderUid,senderDevice) => ({
        participants: async()=>({members,fingerprint}),
        getDevices,
        claimPrekey:claim,
        sendDevicePacket:async packet=>{
            const name=packet.recipient_user_uid+':'+packet.recipient_device_id;
            const list=mailboxes.get(name)||[];
            list.push({
                id:++nextPacket,
                sender_user_uid:senderUid,
                sender_device_id:senderDevice,
                recipient_device_id:packet.recipient_device_id,
                envelope:packet.envelope,
            });
            mailboxes.set(name,list);
            return {queued:true};
        },
        inbox:async id=>({messages:mailboxes.get(senderUid+':'+id)||[]}),
        acknowledge:async (id,ids)=>{
            const name=senderUid+':'+id;
            mailboxes.set(name,(mailboxes.get(name)||[]).filter(row=>!ids.includes(row.id)));
            return {acknowledged:true};
        },
        sendRoomEvent:async packet=>{
            events.push({
                id:events.length+1,
                sender_uid:senderUid,
                sender_device_id:senderDevice,
                encrypted:packet.encrypted,
                created_at:'2026-11-07 21:00:00',
            });
            return {stored:true};
        },
        roomEvents:async (uid,after)=>({events:events.filter(row=>row.id>after)}),
    });
    const createDevice = async(uid,deviceId)=>{
        const machine=await sdk.OlmMachine.initialize(
            new sdk.UserId(notesMatrixUserId(uid)),new sdk.DeviceId(deviceId)
        );
        opened.push(machine);
        await publishInitialKeys(machine,uid,deviceId,
            (route,body)=>register(uid,deviceId,route,body));
        const trust=trustStore();
        return {
            uid,deviceId,machine,trust,
            conversation:createE2eeConversation({
                sdk,machine,trust,transport:transport(uid,deviceId),userUid:uid,deviceId
            }),
        };
    };
    const pin = async(target,uid,id)=>{
        const row=(devices.get(uid)||[]).find(row=>row.device_id===id);
        assert.ok(row);
        await target.trust.pin(uid,id,row.signing_key,async()=>true);
    };

    try {
        const pc=await createDevice(first,pcId);
        const peer=await createDevice(second,peerId);
        const phone=await createDevice(first,phoneId);

        // Исходные устройства уже сравнили ключи друг друга.
        for(const client of [pc,peer,phone]) {
            await pin(client,client.uid,client.deviceId);
            await pin(client,first,pcId);
            await pin(client,second,peerId);
        }
        // Но ключ нового телефона ещё не подтверждён на компьютере.
        await assert.rejects(
            pc.conversation.send(roomUid,'Нельзя отправлять до подтверждения телефона'),
            /Отпечаток устройства не подтверждён|неподтверждённое устройство/
        );
        assert.equal(events.length,0);
        assert.equal((mailboxes.get(first+':'+phoneId)||[]).length,0);

        const phoneKeys=phone.machine.identityKeys;
        const code=await deviceComparisonCode({
            userUid:first,deviceId:phoneId,
            signingKey:phoneKeys.ed25519.toBase64(),
            agreementKey:phoneKeys.curve25519.toBase64(),
        });
        const serverRow=devices.get(first).find(x=>x.device_id===phoneId);
        await assert.rejects(confirmIndependentDevice({
            userUid:first,device:serverRow,input:'0000 '.repeat(7)+'0000',
            fetchDevices:getDevices,localTrust:pc.trust,
        }),/Код не совпал/);
        assert.equal(await pc.trust.isTrusted(first,phoneId,serverRow.signing_key),false);

        await confirmIndependentDevice({
            userUid:first,device:serverRow,input:code,
            fetchDevices:getDevices,localTrust:pc.trust,
        });
        await pin(phone,first,phoneId);
        const plaintext='Теперь сообщение получают компьютер, телефон и собеседник';
        await pc.conversation.send(roomUid,plaintext);
        assert.equal(events.length,1);
        assert.equal(JSON.stringify(events).includes(plaintext),false);
        assert.ok((mailboxes.get(first+':'+phoneId)||[]).length>0,
            'Ключ отправлен новому устройству того же владельца');
        assert.ok((mailboxes.get(second+':'+peerId)||[]).length>0,
            'Ключ отправлен второму участнику');

        const onPhone=await phone.conversation.receive(roomUid,0);
        const onPeer=await peer.conversation.receive(roomUid,0);
        assert.deepEqual(onPhone.messages.map(item=>item.text),[plaintext]);
        assert.deepEqual(onPeer.messages.map(item=>item.text),[plaintext]);
        assert.equal((mailboxes.get(first+':'+phoneId)||[]).length,0,
            'Устройство подтверждает получение обработанных ключей');

        // Теперь телефон — самостоятельный отправитель. Собеседник должен
        // сверить его код, иначе будущие отправки останутся заблокированными.
        await confirmIndependentDevice({
            userUid:first,device:serverRow,input:code,
            fetchDevices:getDevices,localTrust:peer.trust,
        });
        const answer='Ответ отправлен с нового телефона без участия компьютера';
        await phone.conversation.send(roomUid,answer);
        assert.equal(events.length,2);
        assert.equal(JSON.stringify(events).includes(answer),false);
        const onPc=await pc.conversation.receive(roomUid,1);
        const onPeerAgain=await peer.conversation.receive(roomUid,1);
        assert.deepEqual(onPc.messages.map(item=>item.text),[answer]);
        assert.deepEqual(onPeerAgain.messages.map(item=>item.text),[answer]);
    }finally{
        for(const machine of opened)await machine.close();
    }
});
