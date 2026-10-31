import test from 'node:test';
import assert from 'node:assert/strict';
import { receiveDevicePackages, decryptRoomTimeline } from '../../modules/messenger/crypto/matrix-incoming.mjs';

const senderUid = '11111111-1111-4111-8111-111111111111';
const dialogUid = '77777777-7777-4777-8777-777777777777';
const envelope = {algorithm:'m.olm.v1.curve25519-aes-sha2',sender_key:'opaque-key',ciphertext:{device:{body:'encrypted',type:0}}};
const packet = {id:7,sender_user_uid:senderUid,sender_device_id:'DEVICE_001',envelope};
const sdk = {DeviceLists:class {},RoomId:class {constructor(value){this.value=value;}},DecryptionSettings:class {constructor(value){this.value=value;}},TrustRequirement:{CrossSigned:3}};

test('ключи подтверждаются только после успешной обработки библиотекой', async () => {
    let committed = [];
    const machine = {receiveSyncChangesMsc4186: async (events,deviceLists,count) => {
        assert.equal(JSON.parse(events)[0].sender, '@'+senderUid+':notes.local');
        assert.ok(deviceLists instanceof sdk.DeviceLists);
        assert.ok(count instanceof Map);
        return [{}];
    }};
    const result = await receiveDevicePackages(sdk,machine,'DEVICE_002',async()=>({messages:[packet]}),async(device,ids)=>{committed=ids;return{acknowledged:true};});
    assert.deepEqual(result,{received:1,acknowledged:1});
    assert.deepEqual(committed,[7]);
});

test('невозможность обработки и чужой пакет никогда не подтверждаются', async () => {
    let acknowledged=0;
    const acknowledge=async()=>{acknowledged++;return{acknowledged:true};};
    await assert.rejects(receiveDevicePackages(sdk,{receiveSyncChangesMsc4186:async()=>[]},'DEVICE_002',async()=>({messages:[packet]}),acknowledge));
    await assert.rejects(receiveDevicePackages(sdk,{receiveSyncChangesMsc4186:async()=>[{}]},'DEVICE_002',async()=>({messages:[{...packet,recipient_device_id:'CHANGED'}]}),acknowledge));
    assert.equal(acknowledged,0);
});

test('текст расшифровывается только при проверенном отправителе', async () => {
    const encrypted = {algorithm:'m.megolm.v1.aes-sha2',sender_key:'opaque-key',ciphertext:'base64-ciphertext'};
    const events=[{id:3,sender_uid:senderUid,encrypted,created_at:'2026-10-31 08:01:03'}];
    const machine={decryptRoomEvent:async(event,room,settings)=>{
        assert.equal(JSON.parse(event).type,'m.room.encrypted');
        assert.equal(room.value,'!'+dialogUid+':notes.local');
        assert.equal(settings.value,3);
        return {event:JSON.stringify({type:'m.room.message',sender:'@'+senderUid+':notes.local',content:{msgtype:'m.text',body:'Шифрованное сообщение'}})};
    }};
    const result=await decryptRoomTimeline(sdk,machine,dialogUid,async()=>({events}));
    assert.deepEqual(result.messages.map(x=>x.text),['Шифрованное сообщение']);
    assert.equal(result.next_cursor,3);
});

test('не доверяем подменённому отправителю и отсутствующим настройкам проверки', async()=>{
    const events=[{id:3,sender_uid:senderUid,encrypted:{algorithm:'m.megolm.v1.aes-sha2',ciphertext:'foo',sender_key:'key'},created_at:'2026-10-31 08:01:03'}];
    const fake={decryptRoomEvent:async()=>({event:JSON.stringify({type:'m.room.message',sender:'@hacker:notes.local',content:{msgtype:'m.text',body:'Фальшивка'}})})};
    await assert.rejects(decryptRoomTimeline(sdk,fake,dialogUid,async()=>({events})));
    await assert.rejects(decryptRoomTimeline({...sdk,TrustRequirement:{}},fake,dialogUid,async()=>({events})));
});
