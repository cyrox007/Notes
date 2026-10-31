import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {createE2eeConversation} from '../../modules/messenger/crypto/matrix-conversation.mjs';
globalThis.crypto??=webcrypto;

const uid='11111111-1111-4111-8111-111111111111';
const deviceId='DEVICE_001',room='77777777-7777-4777-8777-777777777777';
const matrixUid='@'+uid+':notes.local';
const envelope={algorithm:'m.megolm.v1.aes-sha2',device_id:deviceId,ciphertext:'A'.repeat(100),sender_key:'A'.repeat(43),session_id:'A'.repeat(43)};
const sdk={
 UserId:class{constructor(v){this.value=v}},
 DeviceId:class{constructor(v){this.value=v}},
 RoomId:class{constructor(v){this.value=v}},
 EncryptionSettings:class{},
 CollectStrategy:{onlyTrustedDevices:()=>({trusted:true})},
 LocalTrust:{Verified:1}
};
function harness(fingerprints=['a'.repeat(64),'a'.repeat(64)]){
 let checks=0,posts=0;
 const machine={
  queryKeysForUsers:()=>({id:'query-1',type:1,body:JSON.stringify({device_keys:{[matrixUid]:[]}})}),
  markRequestAsSent:async()=>{},
  getDevice:async()=>({ed25519Key:{toBase64:()=> 'A'.repeat(43)},setLocalTrust:async()=>{}}),
  getMissingSessions:async()=>null,
  invalidateGroupSession:async()=>{},
  shareRoomKey:async()=>[],
  encryptRoomEvent:async()=>JSON.stringify(envelope)
 };
 const transport={
  participants:async()=>({members:[uid],fingerprint:fingerprints[Math.min(checks++,fingerprints.length-1)]}),
  getDevices:async()=>({devices:[{device_id:deviceId,signing_key:'A'.repeat(43),identity:{device_keys:{user_id:matrixUid,device_id:deviceId}}}]}),
  sendRoomEvent:async(payload)=>{posts++;assert.equal(payload.encrypted.algorithm,envelope.algorithm);assert.equal(JSON.stringify(payload).includes('секрет'),false);return{stored:true};},
 };
 return {client:createE2eeConversation({sdk,machine,transport,trust:{isTrusted:async()=>true},userUid:uid,deviceId}),posts:()=>posts};
}
test('подтверждённая защищённая отправка не отправляет открытый текст',async()=>{
 const h=harness();
 await h.client.send(room,'секрет');
 assert.equal(h.posts(),1);
});
test('изменение состава во время раздачи блокирует отправку',async()=>{
 const h=harness(['a'.repeat(64),'b'.repeat(64)]);
 await assert.rejects(h.client.send(room,'секрет'));
 assert.equal(h.posts(),0);
});
test('состав участников без владельца или отпечатка запрещает отправку',async()=>{
 const client=createE2eeConversation({sdk,machine:{},transport:{participants:async()=>({members:[],fingerprint:'x'})},trust:{},userUid:uid,deviceId});
 await assert.rejects(client.send(room,'секрет'));
});
