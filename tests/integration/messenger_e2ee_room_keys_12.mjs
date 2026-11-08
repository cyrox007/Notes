import test from 'node:test';
import assert from 'node:assert/strict';
import { rotateAndShareRoomKey } from '../../modules/messenger/crypto/matrix-room-keys.mjs';

const user='11111111-1111-4111-8111-111111111111';
const device='DEVICE_001';
const room='77777777-7777-4777-8777-777777777777';
const matrixUser='@'+user+':notes.local';
const sdk={
    RoomId:class { constructor(v){this.value=v;} },
    UserId:class { constructor(v){this.value=v;} },
    EncryptionSettings:class {},
    CollectStrategy:{onlyTrustedDevices:()=>({mode:'strict'})}
};
function setup() {
    const calls=[], acknowledged=[];
    const machine={
        queryKeysForUsers:()=>({id:'query-1',type:1,body:JSON.stringify({device_keys:{[matrixUser]:[]}})}),
        getMissingSessions:async()=>({id:'claim-1',type:2,body:JSON.stringify({one_time_keys:{[matrixUser]:{DEVICE_001:'signed_curve25519'}}})}),
        markRequestAsSent:async(...args)=>acknowledged.push(args),
        invalidateGroupSession:async(...args)=>calls.push(['rotate',...args]),
        shareRoomKey:async(...args)=>{calls.push(['share',...args]);return [];}
    };
    return {machine,calls,acknowledged};
}
const getDevices=async()=>({devices:[{
    device_id:device,signing_key:'valid-public-key',
    identity:{device_keys:{user_id:matrixUser,device_id:device}}
}]});
const claimPrekey=async()=>({prekey:{
    device_id:device,key_id:'signed_curve25519:7',
    key:{key:'public',signatures:{[matrixUser]:{'ed25519:DEVICE_001':'signed'}}}
}});

test('сессия запускается только после подтверждения отпечатка каждого устройства',async()=>{
    const {machine,calls,acknowledged}=setup();
    const result=await rotateAndShareRoomKey({sdk,machine,dialogUid:room,memberUids:[user],deviceId:device,getDevices,claimPrekey,sendToDevice:async()=>{},trustedDevice:async()=>true});
    assert.equal(result.devices_checked,1);
    assert.equal(calls[0][0],'rotate');
    assert.equal(calls[1][0],'share');
    assert.equal(calls[1][3].sharingStrategy.mode,'strict');
    assert.equal(acknowledged.length,2);
});

test('неподтверждённое устройство блокирует комнатный ключ до отправки',async()=>{
    const {machine,calls}=setup();
    await assert.rejects(rotateAndShareRoomKey({sdk,machine,dialogUid:room,memberUids:[user],deviceId:device,getDevices,claimPrekey,sendToDevice:async()=>{},trustedDevice:async()=>false}));
    assert.equal(calls.length,0);
});

test('неуспешный запрос одноразового ключа блокирует ротацию',async()=>{
    const {machine,calls,acknowledged}=setup();
    await assert.rejects(rotateAndShareRoomKey({sdk,machine,dialogUid:room,memberUids:[user],deviceId:device,getDevices,claimPrekey:async()=>{throw new Error('Нет ключа');},sendToDevice:async()=>{},trustedDevice:async()=>true}));
    assert.equal(calls.length,0);
    assert.equal(acknowledged.length,1);
});

test('ошибка одного получателя не подтверждает раздачу',async()=>{
    const {machine,acknowledged}=setup();
    machine.shareRoomKey=async()=>[{id:'send1',type:3,body:JSON.stringify({messages:{[matrixUser]:{DEVICE_001:{algorithm:'m.olm.v1.curve25519-aes-sha2',sender_key:'key',ciphertext:{key:{type:0,body:'encrypted'}}}}}})}];
    await assert.rejects(rotateAndShareRoomKey({sdk,machine,dialogUid:room,memberUids:[user],deviceId:device,getDevices,claimPrekey,sendToDevice:async()=>{throw new Error('Не доставлено');},trustedDevice:async()=>true}));
    assert.equal(acknowledged.length,2);
});
