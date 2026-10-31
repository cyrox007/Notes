import test from 'node:test';
import assert from 'node:assert/strict';
import {applyPinnedDeviceTrust} from '../../modules/messenger/crypto/matrix-sdk-trust.mjs';
const uid='11111111-1111-4111-8111-111111111111';
const deviceId='DEVICE_001';
const key='A'.repeat(43);
const sdk={UserId:class{constructor(value){this.value=value;}},DeviceId:class{constructor(value){this.value=value;}},LocalTrust:{Verified:1}};
test('только подтверждённая точная копия ключа получает доверие Matrix',async()=>{
    const accepted=[];
    const machine={getDevice:async()=>({
        ed25519Key:{toBase64:()=>key},
        isDeleted:()=>false,
        isBlacklisted:()=>false,
        setLocalTrust:async value=>accepted.push(value)
    })};
    const confirmed={isTrusted:async()=>true};
    const result=await applyPinnedDeviceTrust(sdk,machine,confirmed,uid,deviceId,key);
    assert.equal(result.trusted,true);
    assert.deepEqual(accepted,[1]);
});
test('подмена ключа и неподтверждённый отпечаток запрещают доверие',async()=>{
    const accepted=[];
    const machine={getDevice:async()=>({ed25519Key:{toBase64:()=>'B'.repeat(43)},setLocalTrust:async v=>accepted.push(v)})};
    await assert.rejects(applyPinnedDeviceTrust(sdk,machine,{isTrusted:async()=>true},uid,deviceId,key));
    await assert.rejects(applyPinnedDeviceTrust(sdk,machine,{isTrusted:async()=>false},uid,deviceId,key));
    assert.deepEqual(accepted,[]);
});
