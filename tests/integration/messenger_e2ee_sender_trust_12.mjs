import test from 'node:test';
import assert from 'node:assert/strict';
import {verifyPinnedRoomSender} from '../../modules/messenger/crypto/matrix-sdk-trust.mjs';

const uid='11111111-1111-4111-8111-111111111111';
const deviceId='DEVICE_001';
const sender='@'+uid+':notes.local';
const ed=Buffer.alloc(32,3).toString('base64');
const curve=Buffer.alloc(32,4).toString('base64');
const sdk={
    UserId:class{constructor(value){this.value=value}},
    DeviceId:class{constructor(value){this.value=value}}
};

function fixture(){
    const row={device_id:deviceId,signing_key:ed,agreement_key:curve};
    const matrix={
        ed25519Key:{toBase64:()=>ed},
        curve25519Key:{toBase64:()=>curve},
        isDeleted:()=>false,isBlacklisted:()=>false,isLocallyTrusted:()=>true
    };
    const args={
        sdk,
        machine:{getDevice:async()=>matrix},
        localTrust:{isTrusted:async()=>true},
        fetchDevices:async()=>({devices:[row]}),
        senderUid:uid,senderDeviceId:deviceId,
        senderEd25519:ed,senderCurve25519:curve,
        matrixSender:sender,encryptedSenderKey:curve,
    };
    return {args,row,matrix};
}
test('подтверждённый ключ совпадает в Megolm, устройстве Matrix и локальном хранилище',async()=>{
    const {args}=fixture();
    assert.equal(await verifyPinnedRoomSender(args),true);
});
test('подмена ключа, идентичности, доверия и отозванное устройство отвергаются',async()=>{
    const {args,row,matrix}=fixture();
    const reject=async changes=>assert.rejects(
        verifyPinnedRoomSender({...args,...changes}),/не подтвержден|отлича|ключ|отозван|устройств|недоступен|неоднозначно|Matrix/i
    );
    await reject({matrixSender:'@other:notes.local'});
    await reject({senderDeviceId:'DEVICE_999'});
    await reject({senderEd25519:Buffer.alloc(32,5).toString('base64')});
    await reject({senderCurve25519:Buffer.alloc(32,6).toString('base64')});
    await reject({encryptedSenderKey:Buffer.alloc(32,6).toString('base64')});
    await reject({localTrust:{isTrusted:async()=>false}});
    await reject({fetchDevices:async()=>({devices:[]})});
    await reject({fetchDevices:async()=>({devices:[row,row]})});
    await reject({machine:{getDevice:async()=>null}});
    await reject({machine:{getDevice:async()=>({...matrix,isBlacklisted:()=>true})}});
    await reject({machine:{getDevice:async()=>({...matrix,isLocallyTrusted:()=>false})}});
    await reject({machine:{getDevice:async()=>({...matrix,curve25519Key:{toBase64:()=>ed}})}});
});
