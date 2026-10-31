import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {deviceFingerprint,createLocalDeviceTrust} from '../../modules/messenger/crypto/matrix-device-trust.mjs';
globalThis.crypto??=webcrypto;
globalThis.atob??=(x)=>Buffer.from(x,'base64').toString('binary');
const uid='11111111-1111-4111-8111-111111111111';
const device='DEVICE_001';
const key=Buffer.alloc(32,3).toString('base64');
const changedKey=Buffer.alloc(32,4).toString('base64');
function fakeDatabase(){
    const saved=new Map();
    return { transaction(name,mode){
        const txn={oncomplete:null,onerror:null,onabort:null};
        txn.objectStore=()=>({
            get(id){const r={onsuccess:null,onerror:null,result:saved.get(id)};queueMicrotask(()=>{r.onsuccess?.();txn.oncomplete?.()});return r;},
            add(value,id){const r={onsuccess:null,onerror:null,result:id};saved.set(id,value);queueMicrotask(()=>{r.onsuccess?.();txn.oncomplete?.()});return r;},
            delete(id){const r={onsuccess:null,onerror:null,result:undefined};saved.delete(id);queueMicrotask(()=>{r.onsuccess?.();txn.oncomplete?.()});return r;}
        });
        return txn;
    }};
}
test('до ручного подтверждения устройство не считается доверенным',async()=>{
    const trust=createLocalDeviceTrust(fakeDatabase());
    assert.equal(await trust.isTrusted(uid,device,key),false);
    await assert.rejects(trust.pin(uid,device,key,async()=>false));
    assert.equal(await trust.isTrusted(uid,device,key),false);
    const printed=await deviceFingerprint(key);
    assert.equal(printed.replaceAll(' ','').length,64);
    assert.equal(await trust.pin(uid,device,key,async obj=>obj.fingerprint===printed),true);
    assert.equal(await trust.isTrusted(uid,device,key),true);
    assert.equal(await trust.isTrusted(uid,device,changedKey),false);
    await assert.rejects(trust.pin(uid,device,changedKey,async()=>true));
    await trust.unpin(uid,device);
    assert.equal(await trust.isTrusted(uid,device,key),false);
});
test('неполные и повреждённые ключи не получают подтверждения',async()=>{
    await assert.rejects(deviceFingerprint('короткий'));
    await assert.rejects(deviceFingerprint('AAAA'));
    const trust=createLocalDeviceTrust(fakeDatabase());
    await assert.rejects(trust.pin(uid,'bad',key,async()=>true));
});
