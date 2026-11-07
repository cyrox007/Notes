import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createEncryptedRoomKeyArchive,restoreEncryptedRoomKeyArchive
} from '../../modules/messenger/crypto/matrix-room-key-archive.mjs';

const user='11111111-1111-4111-8111-111111111111';
const other='22222222-2222-4222-8222-222222222222';
const passphrase='Фраза-для-секретного-архива-на-двух-устройствах';
const room='!77777777-7777-4777-8777-777777777777:notes.local';
const original=[{room_id:room,algorithm:'m.megolm.v1.aes-sha2',session_id:'session-example',
    sender_key:'sender_key_example',session_key:'СЕКРЕТНЫЙ КЛЮЧ НЕ ДОЛЖЕН ВЫХОДИТЬ В ФАЙЛ'}];
function fixture(){
    const records=[];
    const sdk={OlmMachine:{
        encryptExportedRoomKeys:(raw,pass,rounds)=>{
            records.push({operation:'encrypt',rounds,pass});
            return '-----BEGIN MEGOLM SESSION DATA-----\n'
                +Buffer.from(raw).toString('base64')+'\n-----END MEGOLM SESSION DATA-----';
        },
        decryptExportedRoomKeys:(cipher,pass)=>{
            if(pass!==passphrase)throw new Error('Неверный пароль');
            return Buffer.from(cipher.split('\n')[1],'base64').toString();
        },
    }};
    const machine={
        exportRoomKeys:async callback=>{
            assert.equal(typeof callback,'function');
            return JSON.stringify(original);
        },
        importExportedRoomKeys:async(raw,progress)=>{
            records.push({operation:'import',keys:JSON.parse(raw)});
            progress(1n,1n);
            return {importedCount:1,totalCount:1};
        },
    };
    return {sdk,machine,records};
}
test('архив переносится локально с отдельной фразой и 250 тысячами итераций',async()=>{
    const f=fixture();
    const result=await createEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase
    });
    assert.equal(result.keyCount,1);
    assert.equal(f.records[0].rounds,250000);
    assert.equal(result.archive.includes('СЕКРЕТНЫЙ КЛЮЧ'),false);
    const restored=await restoreEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase,archive:result.archive
    });
    assert.equal(restored.importedCount,1);
    assert.equal(restored.totalCount,1);
});
test('чужая учётная запись или неверный пароль не импортируют ключи',async()=>{
    const f=fixture();
    const {archive}=await createEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase
    });
    await assert.rejects(restoreEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:other,passphrase,archive
    }),/Чужая учётная запись/);
    await assert.rejects(restoreEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase:'Неверная фраза минимум 16 символов',archive
    }),/Пароль неверен/);
    assert.equal(f.records.filter(x=>x.operation==='import').length,0);
});
test('подмена перечня комнат и пустой экспорт не проходят',async()=>{
    const f=fixture();
    f.machine.exportRoomKeys=async()=>JSON.stringify([{...original[0],room_id:'!outside:example.org'}]);
    await assert.rejects(createEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase
    }),/чужие комнаты/);
    f.machine.exportRoomKeys=async()=>JSON.stringify([]);
    await assert.rejects(createEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase
    }),/Нет ключей истории/);
});
test('содержимое расшифрованного архива проверяется до импорта',async()=>{
    const f=fixture();
    const {archive}=await createEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase
    });
    const bad=JSON.parse(archive);
    bad.key_count=3;
    await assert.rejects(restoreEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase,archive:JSON.stringify(bad)
    }),/Количество ключей/);
    assert.equal(f.records.filter(x=>x.operation==='import').length,0);
});
test('архив без поддержки криптодвижка и чрезмерные размеры не принимаются',async()=>{
    const f=fixture();
    await assert.rejects(createEncryptedRoomKeyArchive({
        sdk:{},machine:f.machine,userUid:user,passphrase
    }),/не умеет/);
    await assert.rejects(createEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase:'коротко'
    }),/Парольная фраза/);
    await assert.rejects(restoreEncryptedRoomKeyArchive({
        sdk:f.sdk,machine:f.machine,userUid:user,passphrase,archive:'X'.repeat(12*1024*1024+1)
    }),/превышает/);
});
