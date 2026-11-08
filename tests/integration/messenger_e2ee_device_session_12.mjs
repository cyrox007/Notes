import test from 'node:test';
import assert from 'node:assert/strict';
import {acquireDeviceSession} from '../../modules/messenger/crypto/matrix-device-session.mjs';

const user='11111111-1111-4111-8111-111111111111';

function createLocks(){
    const active=new Set();
    const calls=[];
    return {
        calls,
        async request(name,options,callback){
            calls.push({name,options});
            if(active.has(name))return callback(null);
            active.add(name);
            try{return await callback({name,mode:'exclusive'});}
            finally{active.delete(name);}
        },
    };
}

test('одна вкладка удерживает устройство, вторая немедленно получает отказ',async()=>{
    const locks=createLocks();
    const first=await acquireDeviceSession(user,'DEVICE_EXAMPLE_001',locks);
    await assert.rejects(
        acquireDeviceSession(user,'DEVICE_EXAMPLE_001',locks),/другой вкладке/
    );
    assert.equal(locks.calls.length,2);
    assert.equal(locks.calls[0].options.mode,'exclusive');
    assert.equal(locks.calls[0].options.ifAvailable,true);
    assert.equal(locks.calls[0].name,locks.calls[1].name);
    first.release();
    first.release();
    await new Promise(resolve=>setImmediate(resolve));
    const second=await acquireDeviceSession(user,'DEVICE_EXAMPLE_001',locks);
    second.release();
});
test('отдельные устройства пользователя могут работать независимо',async()=>{
    const locks=createLocks();
    const a=await acquireDeviceSession(user,'DEVICE_EXAMPLE_001',locks);
    const b=await acquireDeviceSession(user,'DEVICE_EXAMPLE_002',locks);
    assert.notEqual(locks.calls[0].name,locks.calls[1].name);
    a.release();
    b.release();
});
test('без блокировок браузера и с неправильным устройством fail closed',async()=>{
    await assert.rejects(acquireDeviceSession(user,'DEVICE_EXAMPLE_001',{}),/не поддерживает/);
    await assert.rejects(acquireDeviceSession(user,'bad',createLocks()),/идентификатор/);
    await assert.rejects(acquireDeviceSession('не-uuid','DEVICE_EXAMPLE_001',createLocks()),/учётной записи/);
    const locks={request:async()=>{throw new Error('Хранилище недоступно');}};
    await assert.rejects(acquireDeviceSession(user,'DEVICE_EXAMPLE_001',locks),/Хранилище недоступно/);
});
