import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {
    deviceComparisonCode, confirmIndependentDevice, normalizeComparisonCode,
} from '../../modules/messenger/crypto/matrix-device-pairing.mjs';

globalThis.crypto ??= webcrypto;
const user = '11111111-1111-4111-8111-111111111111';
const deviceId = 'DEVICE_PHONE_001';
const signing = Buffer.alloc(32, 3).toString('base64');
const agreement = Buffer.alloc(32, 4).toString('base64');
const altered = Buffer.alloc(32, 5).toString('base64');
const device = {device_id:deviceId,signing_key:signing,agreement_key:agreement,verified:true};

function fixture() {
    let count = 0;
    const trust = {pin:async(uid,id,key,confirm)=>{
        assert.equal(uid,user);
        assert.equal(id,deviceId);
        assert.equal(key,signing);
        assert.equal(await confirm({fingerprint:'ДОКАЗАННЫЙ КЛЮЧ'}),true);
        count++;
        return true;
    }};
    return {
        trust, get count(){return count;},
        fetchDevices:async()=>({devices:[device]}),
    };
}

test('код одинаков на обоих устройствах, не содержит секретов и имеет 128 бит',async()=>{
    const desc={userUid:user,deviceId,signingKey:signing,agreementKey:agreement};
    const first=await deviceComparisonCode(desc);
    const second=await deviceComparisonCode({...desc,signingKey:signing.replace(/=+$/,'')});
    assert.equal(first,second);
    assert.match(first,/^(?:[A-F0-9]{4} ){7}[A-F0-9]{4}$/);
    assert.equal(normalizeComparisonCode(first),first.replaceAll(' ',''));
    assert.notEqual(await deviceComparisonCode({...desc,agreementKey:altered}),first);
    assert.notEqual(await deviceComparisonCode({...desc,signingKey:altered}),first);
    assert.notEqual(await deviceComparisonCode({...desc,deviceId:'DEVICE_PHONE_002'}),first);
});
test('локальное доверие появляется только при полном совпадении кода и реестра',async()=>{
    const f=fixture();
    const code=await deviceComparisonCode({
        userUid:user,deviceId,signingKey:signing,agreementKey:agreement,
    });
    const result=await confirmIndependentDevice({
        userUid:user,device,input:code,fetchDevices:f.fetchDevices,localTrust:f.trust,
    });
    assert.equal(result,true);
    assert.equal(f.count,1);
});
test('ошибка кода не выдаёт доверия',async()=>{
    const f=fixture();
    await assert.rejects(confirmIndependentDevice({
        userUid:user,device,input:'0000 '.repeat(7)+'0000',
        fetchDevices:f.fetchDevices,localTrust:f.trust,
    }),/Код не совпал/);
    assert.equal(f.count,0);
});
test('подмена ключа и отзыв в реестре блокируют доверие',async()=>{
    const code=await deviceComparisonCode({
        userUid:user,deviceId,signingKey:signing,agreementKey:agreement,
    });
    const cases=[
        {devices:[]},
        {devices:[device,device]},
        {devices:[{...device,signing_key:altered}]},
        {devices:[{...device,agreement_key:altered}]},
    ];
    for(const response of cases){
        const f=fixture();
        await assert.rejects(confirmIndependentDevice({
            userUid:user,device,input:code,fetchDevices:async()=>response,localTrust:f.trust,
        }),/отозвано|отсутствует|неоднозначно|изменился/);
        assert.equal(f.count,0);
    }
});
test('повреждённые идентификаторы, ключи и коды отвергаются',async()=>{
    const base={userUid:user,deviceId,signingKey:signing,agreementKey:agreement};
    await assert.rejects(deviceComparisonCode({...base,deviceId:'../'}));
    await assert.rejects(deviceComparisonCode({...base,agreementKey:signing}));
    await assert.rejects(deviceComparisonCode({...base,signingKey:'ABCDE'}));
    await assert.rejects(deviceComparisonCode({...base,userUid:'not-a-uid'}));
    assert.throws(()=>normalizeComparisonCode('short'));
    assert.throws(()=>normalizeComparisonCode('X'.repeat(500)));
});
