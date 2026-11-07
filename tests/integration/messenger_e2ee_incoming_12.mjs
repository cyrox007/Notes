import test from 'node:test';
import assert from 'node:assert/strict';
import { receiveDevicePackages, decryptRoomTimeline } from '../../modules/messenger/crypto/matrix-incoming.mjs';

const senderUid = '11111111-1111-4111-8111-111111111111';
const dialogUid = '77777777-7777-4777-8777-777777777777';
const envelope = {algorithm:'m.olm.v1.curve25519-aes-sha2',sender_key:'opaque-key',ciphertext:{device:{body:'encrypted',type:0}}};
const packet = {id:7,sender_user_uid:senderUid,sender_device_id:'DEVICE_001',envelope};
const sdk = {DeviceLists:class {},RoomId:class {constructor(value){this.value=value;}},DecryptionSettings:class {constructor(value){this.value=value;}},TrustRequirement:{Untrusted:0,CrossSigned:3}};

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
    const events=[{id:3,sender_uid:senderUid,sender_device_id:'DEVICE_001',encrypted,created_at:'2026-10-31 08:01:03'}];
    const machine={decryptRoomEvent:async(event,room,settings)=>{
        assert.equal(JSON.parse(event).type,'m.room.encrypted');
        assert.equal(room.value,'!'+dialogUid+':notes.local');
        assert.equal(settings.value,0);
        return {sender:{toString:()=> '@'+senderUid+':notes.local'},
            senderDevice:{toString:()=> 'DEVICE_001'},
            senderClaimedEd25519Key:'signed-key',senderCurve25519Key:'opaque-key',
            event:JSON.stringify({type:'m.room.message',sender:'@'+senderUid+':notes.local',content:{msgtype:'m.text',body:'Шифрованное сообщение'}})};
    }};
    const result=await decryptRoomTimeline(sdk,machine,dialogUid,async()=>({events}),0,async details=>{
        assert.equal(details.senderDeviceId,'DEVICE_001');
        assert.equal(details.senderEd25519,'signed-key');
        assert.equal(details.senderCurve25519,'opaque-key');
        return true;
    });
    assert.deepEqual(result.messages.map(x=>x.text),['Шифрованное сообщение']);
    assert.equal(result.next_cursor,3);
});

test('не доверяем подменённому отправителю и отсутствующим настройкам проверки', async()=>{
    const events=[{id:3,sender_uid:senderUid,sender_device_id:'DEVICE_001',encrypted:{algorithm:'m.megolm.v1.aes-sha2',ciphertext:'foo',sender_key:'key'},created_at:'2026-10-31 08:01:03'}];
    const fake={decryptRoomEvent:async()=>({sender:{toString:()=> '@'+senderUid+':notes.local'},senderDevice:{toString:()=> 'DEVICE_001'},event:JSON.stringify({type:'m.room.message',sender:'@hacker:notes.local',content:{msgtype:'m.text',body:'Фальшивка'}})})};
    await assert.rejects(decryptRoomTimeline(sdk,fake,dialogUid,async()=>({events}),0,async()=>true));
    await assert.rejects(decryptRoomTimeline({...sdk,TrustRequirement:{}},fake,dialogUid,async()=>({events}),0,async()=>true));
});

test('расшифрованное имя вложения доступно только локальному клиенту',async()=>{
    const uid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const secret={version:1,algorithm:'AES-256-GCM',attachment_uid:uid,key:'S'.repeat(43),iv:'I'.repeat(16)};
    const rows=[{id:8,sender_uid:senderUid,sender_device_id:'DEVICE_001',created_at:'2026-10-31 08:01:03',
        encrypted:{algorithm:'m.megolm.v1.aes-sha2',sender_key:'key',ciphertext:'opaque'}}];
    const machine={decryptRoomEvent:async()=>({
        sender:{toString:()=> '@'+senderUid+':notes.local'},
        senderDevice:{toString:()=> 'DEVICE_001'},
        event:JSON.stringify({type:'m.room.message',sender:'@'+senderUid+':notes.local',
            content:{msgtype:'m.file',body:'private.jpg',file:{uri:'notes-e2ee://'+uid,secret}}})
    })};
    const received=await decryptRoomTimeline(sdk,machine,dialogUid,async()=>({events:rows}),0,async()=>true);
    assert.equal(received.messages[0].type,'file');
    assert.equal(received.messages[0].attachment.secret.key,secret.key);
});

test('неподтверждённое, удалённое и неустановленное устройство блокируют текст',async()=>{
    const rows=[{id:12,sender_uid:senderUid,sender_device_id:'DEVICE_001',
        encrypted:{algorithm:'m.megolm.v1.aes-sha2',sender_key:'curve-key',ciphertext:'ciphertext'},
        created_at:'2026-11-07 12:00:00'}];
    const decrypted={sender:{toString:()=> '@'+senderUid+':notes.local'},
        senderDevice:{toString:()=> 'DEVICE_001'},
        senderCurve25519Key:'curve-key',senderClaimedEd25519Key:'ed25519-key',
        event:JSON.stringify({type:'m.room.message',sender:'@'+senderUid+':notes.local',
            content:{msgtype:'m.text',body:'секрет'}})};
    const machine={decryptRoomEvent:async()=>decrypted};
    const fetch=async()=>({events:rows});
    await assert.rejects(decryptRoomTimeline(sdk,machine,dialogUid,fetch,0,async()=>false),
        /не подтверждён/);
    await assert.rejects(decryptRoomTimeline(sdk,machine,dialogUid,fetch,0,async()=>{throw Error('Ключ заменён');}),
        /Ключ заменён/);
    await assert.rejects(decryptRoomTimeline(sdk,{decryptRoomEvent:async()=>({...decrypted,senderDevice:undefined})},
        dialogUid,fetch,0,async()=>{throw Error('Ключ отправителя подменён');}),/локальным архивом/);
    await assert.rejects(decryptRoomTimeline(sdk,machine,dialogUid,fetch),
        /проверкой доверия/);
});

test('архивная история не выдаётся за проверенную без явной локальной метки',async()=>{
    const rows=[{id:19,sender_uid:senderUid,sender_device_id:'DEVICE_001',
        encrypted:{algorithm:'m.megolm.v1.aes-sha2',sender_key:'original-curve',
            session_id:'session-example-1234',ciphertext:'encrypted'},
        created_at:'2026-11-07 12:00:00'}];
    const decrypted={sender:{toString:()=> '@'+senderUid+':notes.local'},senderDevice:null,
        senderCurve25519Key:'original-curve',senderClaimedEd25519Key:'original-ed',
        event:JSON.stringify({type:'m.room.message',sender:'@'+senderUid+':notes.local',
            content:{msgtype:'m.text',body:'архивное сообщение'}})};
    const machine={decryptRoomEvent:async()=>decrypted};
    const fetch=async()=>({events:rows});
    const disallowNormal=async()=>{throw Error('Не выдавать импорт за verified');};
    await assert.rejects(decryptRoomTimeline(sdk,machine,dialogUid,fetch,0,disallowNormal),
        /локальным архивом/);
    await assert.rejects(decryptRoomTimeline(sdk,machine,dialogUid,fetch,0,disallowNormal,
        async()=>false),/локальным архивом/);
    const received=await decryptRoomTimeline(sdk,machine,dialogUid,fetch,0,disallowNormal,
        async details=>{
            assert.equal(details.sessionId,'session-example-1234');
            assert.equal(details.senderKey,'original-curve');
            assert.equal(details.senderClaimedEd25519Key,'original-ed');
            return true;
        });
    assert.deepEqual(received.messages.map(x=>x.text),['архивное сообщение']);
    assert.equal(received.messages[0].verified,false);
    assert.equal(received.messages[0].restored,true);
    await assert.rejects(decryptRoomTimeline(sdk,{
        decryptRoomEvent:async()=>({...decrypted,senderClaimedEd25519Key:null})
    },dialogUid,fetch,0,disallowNormal,async()=>false),/локальным архивом/);
    await assert.rejects(decryptRoomTimeline(sdk,{
        decryptRoomEvent:async()=>({...decrypted,senderDevice:{toString:()=> 'DEVICE_SUBSTITUTED'}})
    },dialogUid,fetch,0,disallowNormal,async()=>true),/не подтвердил/);
});
