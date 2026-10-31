import test from 'node:test';
import assert from 'node:assert/strict';
import {createMessengerE2eeTransport} from '../../modules/messenger/crypto/matrix-http-transport.mjs';

test('защищённые операции используют браузерную сессию и CSRF',async()=>{
    const requests=[];
    const client=createMessengerE2eeTransport({
        getCsrfToken:()=> 'csrf_token_for_test_123456',
        fetchRequest:async(path,opts)=>{
            requests.push([path,opts]);
            return {ok:true,json:async()=>({status:'ok',queued:true})};
        }
    });
    await client.sendDevicePacket({sender_device_id:'DEVICE_001',envelope:{ciphertext:'cipher'}});
    assert.equal(requests.length,1);
    assert.equal(requests[0][0],'/messenger/e2ee/send');
    assert.equal(requests[0][1].credentials,'same-origin');
    assert.equal(requests[0][1].cache,'no-store');
    assert.equal(requests[0][1].headers['X-CSRF-Token'],'csrf_token_for_test_123456');
    assert.deepEqual(JSON.parse(new URLSearchParams(requests[0][1].body).get('payload')).envelope,{ciphertext:'cipher'});
    await client.participants('room-uid');
    assert.ok(requests[1][0].includes('/e2ee/participants?dialog_uid=room-uid'));
    assert.equal(requests[1][1].method,'GET');
});

test('отсутствующий CSRF и ошибочный ответ не подтверждаются',async()=>{
    const client=createMessengerE2eeTransport({getCsrfToken:()=>'',fetchRequest:async()=>({ok:true,json:async()=>({status:'ok'})})});
    await assert.rejects(client.registerDevice('DEVICE_001',{}));
    const fail=createMessengerE2eeTransport({getCsrfToken:()=> '0123456789abcdef',fetchRequest:async()=>({ok:true,json:async()=>({status:'error'})})});
    await assert.rejects(fail.sendDevicePacket({}));
    const reject=createMessengerE2eeTransport({getCsrfToken:()=> '0123456789abcdef',fetchRequest:async()=>({ok:false,status:403})});
    await assert.rejects(reject.sendDevicePacket({}));
    assert.throws(()=>createMessengerE2eeTransport({messengerPath:'https://other.example/messenger',getCsrfToken:()=>'',fetchRequest:async()=>{}}));
});

test('загрузка отправляет только бинарный шифротекст с общим именем encrypted.bin',async()=>{
    const uid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const sent=[];
    const client=createMessengerE2eeTransport({
        getCsrfToken:()=> 'csrf_token_for_test_123456',
        fetchRequest:async(path,opts)=>{
            sent.push({path,opts});
            return {ok:true,json:async()=>({status:'ok',attachment:{uid,size:33}})};
        }
    });
    const result=await client.uploadCiphertext('dialog-uid',uid,new Uint8Array(33));
    assert.equal(result.uid,uid);
    assert.equal(sent[0].path,'/messenger/e2ee/upload');
    assert.equal(sent[0].opts.headers['X-CSRF-Token'],'csrf_token_for_test_123456');
    assert.equal(sent[0].opts.body.get('attachment_uid'),uid);
    assert.equal(sent[0].opts.body.get('file').name,'encrypted.bin');
    assert.equal(sent[0].opts.body.get('file').size,33);
    assert.equal(sent[0].opts.body.has('key'),false);
    await assert.rejects(client.uploadCiphertext('dialog-uid','malformed',new Uint8Array(33)));
    await assert.rejects(client.uploadCiphertext('dialog-uid',uid,new Uint8Array(1)));
});
test('скачивание выдаёт только ограниченный шифротекст',async()=>{
    const uid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const client=createMessengerE2eeTransport({
        getCsrfToken:()=> 'csrf_token_for_test_123456',
        fetchRequest:async()=>({ok:true,arrayBuffer:async()=>new Uint8Array(33).buffer})
    });
    assert.equal((await client.downloadCiphertext(uid)).byteLength,33);
});
