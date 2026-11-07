import { notesMatrixUserId, notesMatrixRoomId } from './matrix-device-bootstrap.mjs';

function assertDeviceId(deviceId) {
    if (typeof deviceId !== 'string' || !/^[A-Za-z0-9_-]{8,64}$/.test(deviceId)) {
        throw new TypeError('Неверный идентификатор устройства');
    }
}

function decodePublicKey(key) {
    if (typeof key !== 'string' || !/^[A-Za-z0-9+\/_-]+={0,2}$/.test(key)) {
        throw new TypeError('Некорректный открытый ключ Ed25519');
    }
    const raw = atob(key.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(key.length/4)*4,'='));
    if (raw.length !== 32) {
        throw new TypeError('Открытый ключ Ed25519 должен иметь длину 32 байта');
    }
    return Uint8Array.from(raw, c=>c.charCodeAt(0));
}

/** Отпечаток подтверждается пользователями по независимому каналу. */
export async function deviceFingerprint(signingKey) {
    const raw = decodePublicKey(signingKey);
    if (!globalThis.crypto?.subtle) {
        throw new Error('Для проверки отпечатка требуется безопасный контекст');
    }
    const digest = await globalThis.crypto.subtle.digest('SHA-256',raw);
    return Array.from(new Uint8Array(digest),v=>v.toString(16).padStart(2,'0'))
        .join('').toUpperCase().match(/.{1,8}/g).join(' ');
}


// Теги импортированной истории НЕ содержат секретных комнатных ключей.
// Они нужны, чтобы отдельный обработчик не выдавал импортированный сеанс
// за криптографически подтверждённого отправителя текущего устройства.
const ROOM = /^![0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:notes\.local$/i;
const BASE64 = /^[A-Za-z0-9+/_-]{32,256}={0,2}$/;
function archivedKeyIdentity(row) {
    if (!row || !ROOM.test(row.room_id)
        || !BASE64.test(row.session_id || '')
        || !BASE64.test(row.sender_key || '')
        || !BASE64.test(row.sender_claimed_keys?.ed25519 || '')) {
        throw new TypeError('Недопустимая метка импортированного комнатного ключа');
    }
    return {
        id:'restored:'+row.room_id.toLowerCase()+':'+row.session_id+':'+row.sender_key,
        signingKey:row.sender_claimed_keys.ed25519.replace(/=+$/,''),
    };
}

const STORE = 'devices';
const VERSION = 1;
export function openTrustDatabase(ownerUid) {
    notesMatrixUserId(ownerUid);
    if (!globalThis.indexedDB) {
        throw new Error('Для доверенных устройств требуется IndexedDB');
    }
    return new Promise((resolve,reject)=>{
        const request = indexedDB.open('notes-e2ee-trust-v1-'+ownerUid.toLowerCase(),VERSION);
        request.onupgradeneeded=()=>{
            const db=request.result;
            if(!db.objectStoreNames.contains(STORE))db.createObjectStore(STORE);
        };
        request.onerror=()=>reject(new Error('Недоступно хранилище доверенных устройств'));
        request.onsuccess=()=>resolve(request.result);
    });
}

function storeTransaction(database,mode,fn){
    return new Promise((resolve,reject)=>{
        const txn=database.transaction(STORE,mode);
        const store=txn.objectStore(STORE);
        const req=fn(store);
        let result;
        req.onsuccess=()=>{result=req.result;};
        req.onerror=()=>{reject(new Error('Операция с доверенным устройством не выполнена'));};
        txn.oncomplete=()=>resolve(result);
        txn.onerror=()=>reject(new Error('Не удалось сохранить сведения о доверенных устройствах'));
        txn.onabort=()=>reject(new Error('Сохранение доверенных устройств отменено'));
    });
}

/**
 * Список подтверждений локальный и НЕ зависит от значения verified сервера.
 * Первая запись принимается только после личного подтверждения отпечатка.
 */
export function createLocalDeviceTrust(database){
    if(!database?.transaction)throw new TypeError('Хранилище подтверждений не открыто');
    const key=(uid,deviceId)=>{
        notesMatrixUserId(uid);
        assertDeviceId(deviceId);
        return uid.toLowerCase()+':'+deviceId;
    };
    return Object.freeze({
        async fingerprint(signingKey){return deviceFingerprint(signingKey);},
        async isTrusted(uid,deviceId,signingKey){
            const fingerprint=await deviceFingerprint(signingKey);
            const record=await storeTransaction(database,'readonly',s=>s.get(key(uid,deviceId)));
            return record?.fingerprint===fingerprint;
        },
        async pin(uid,deviceId,signingKey,confirm){
            if(typeof confirm!=='function')throw new TypeError('Требуется явное подтверждение отпечатка');
            const fingerprint=await deviceFingerprint(signingKey);
            const id=key(uid,deviceId);
            const existing=await storeTransaction(database,'readonly',s=>s.get(id));
            if(existing && existing.fingerprint!==fingerprint){
                throw new Error('Ключ существующего устройства изменён: требуется отдельная проверка замены');
            }
            if(await confirm({user_uid:uid,device_id:deviceId,fingerprint})!==true){
                throw new Error('Отпечаток устройства не подтверждён');
            }
            if(existing)return false;
            await storeTransaction(database,'readwrite',s=>s.add({fingerprint,confirmed_at:new Date().toISOString()},id));
            return true;
        },
        async unpin(uid,deviceId){
            await storeTransaction(database,'readwrite',s=>s.delete(key(uid,deviceId)));
        },
        async recordRestoredRoomKeys(rows){
            if(!Array.isArray(rows) || rows.length<1 || rows.length>10000) {
                throw new TypeError('Некорректное количество импортированных ключей');
            }
            const entries=rows.map(archivedKeyIdentity);
            // Записываем метки одной транзакцией, после успеха импорта Matrix.
            // Сервер Notes не участвует в этой операции.
            await new Promise((resolve,reject)=>{
                const txn=database.transaction(STORE,'readwrite');
                const store=txn.objectStore(STORE);
                for(const row of entries){
                    store.put({signingKey:row.signingKey,origin:'local-archive'},row.id);
                }
                txn.oncomplete=()=>resolve();
                txn.onerror=()=>reject(new Error('Не удалось сохранить метки восстановленных ключей'));
                txn.onabort=()=>reject(new Error('Сохранение меток истории отменено'));
            });
            return entries.length;
        },
        async isRestoredRoomKey({dialogUid,sessionId,senderKey,senderClaimedEd25519Key}){
            const identity=archivedKeyIdentity({
                room_id:notesMatrixRoomId(dialogUid),session_id:sessionId,
                sender_key:senderKey,
                sender_claimed_keys:{ed25519:senderClaimedEd25519Key}
            });
            const record=await storeTransaction(database,'readonly',
                store=>store.get(identity.id));
            return record?.origin==='local-archive' &&
                record.signingKey===identity.signingKey;
        }
    });
}
