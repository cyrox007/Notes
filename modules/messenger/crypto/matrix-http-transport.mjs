/**
 * HTTP-транспорт клиента Matrix Rust Crypto для Notes.
 * Передаёт серверу только открытые ключи и ЗАШИФРОВАННЫЕ пакеты.
 * Без защищённого сеанса и CSRF изменяющие запросы не отправляются.
 */
export function createMessengerE2eeTransport({
    messengerPath = '/messenger', getCsrfToken, fetchRequest = globalThis.fetch,
}) {
    if (!/^\/(?!\/)[A-Za-z0-9/_-]*\/messenger\/?$/.test(messengerPath)
        && messengerPath !== '/messenger') {
        throw new TypeError('Некорректный путь локального Messenger');
    }
    if (typeof fetchRequest !== 'function' || typeof getCsrfToken !== 'function') {
        throw new TypeError('Не настроен защищённый транспорт Messenger');
    }
    const root = messengerPath.replace(/\/$/,'');
    async function request(path, method = 'GET', payload = null, params = null) {
        const url = new URL(root + '/e2ee/' + path, 'https://notes.local');
        if (params) {
            for (const [name,value] of Object.entries(params)) url.searchParams.set(name,String(value));
        }
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        let body;
        if (method === 'POST') {
            const token = String(getCsrfToken() || '');
            if (!token || token.length < 10) {
                throw new Error('Отсутствует действующий защитный токен страницы');
            }
            headers['X-CSRF-Token'] = token;
            headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            const raw = JSON.stringify(payload);
            if (raw.length > 64000) throw new RangeError('Превышен лимит E2EE-запроса');
            body = new URLSearchParams({payload:raw}).toString();
        }
        const response = await fetchRequest(url.pathname + url.search,{
            method,headers,body,credentials:'same-origin',cache:'no-store',redirect:'error',
        });
        if (!response?.ok) {
            throw new Error('Зашифрованный транспорт отклонён сервером: HTTP '+String(response?.status || ''));
        }
        const result = await response.json();
        if (!result || result.status !== 'ok') {
            throw new Error('Не подтверждена E2EE-операция сервера');
        }
        return result;
    }

    function attachmentUid(uid) {
        if (typeof uid !== 'string'
            || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(uid)) {
            throw new TypeError('Некорректный идентификатор E2EE-файла');
        }
        return uid;
    }
    async function uploadCiphertext(dialogUid,uid,bytes) {
        attachmentUid(uid);
        if (!(bytes instanceof Uint8Array) || bytes.byteLength < 17
            || bytes.byteLength > 10 * 1024 * 1024 + 16) {
            throw new TypeError('Допускаются только зашифрованные байты допустимого размера');
        }
        const token=String(getCsrfToken()||'');
        if(token.length < 10 || typeof FormData === 'undefined' || typeof Blob === 'undefined') {
            throw new Error('Защищённая загрузка файла недоступна');
        }
        const body=new FormData();
        body.append('dialog_uid',dialogUid);
        body.append('attachment_uid',uid);
        body.append('file',new Blob([bytes],{type:'application/octet-stream'}),'encrypted.bin');
        const response=await fetchRequest(root+'/e2ee/upload',{
            method:'POST',body,
            credentials:'same-origin',cache:'no-store',redirect:'error',
            headers:{'X-CSRF-Token':token,Accept:'application/json','X-Requested-With':'XMLHttpRequest'}
        });
        if(!response?.ok)throw new Error('Сервер отклонил зашифрованный файл');
        const data=await response.json();
        if(data?.status!=='ok'||data?.attachment?.uid!==uid) {
            throw new Error('Сервер не подтвердил сохранение зашифрованного файла');
        }
        return data.attachment;
    }
    async function downloadCiphertext(uid) {
        attachmentUid(uid);
        const response=await fetchRequest(root+'/e2ee/media/'+uid,{
            method:'GET',credentials:'same-origin',cache:'no-store',redirect:'error'
        });
        if(!response?.ok)throw new Error('Зашифрованный файл недоступен');
        const bytes=new Uint8Array(await response.arrayBuffer());
        if(bytes.byteLength<17||bytes.byteLength>10*1024*1024+16) {
            throw new Error('Некорректный размер зашифрованного вложения');
        }
        return bytes;
    }

    return Object.freeze({
        participants: dialogUid=>request('participants','GET',null,{dialog_uid:dialogUid}),
        getDevices: userUid=>request('devices','GET',null,{user_uid:userUid}),
        registerDevice: (deviceId,identity)=>request('devices','POST',{device_id:deviceId,identity}),
        publishPrekeys: (deviceId,keys)=>request('prekeys','POST',{device_id:deviceId,keys}),
        claimPrekey: (userUid,deviceId)=>request('claim','POST',{user_uid:userUid,device_id:deviceId}),
        sendDevicePacket: payload=>request('send','POST',payload),
        inbox: (deviceId,after=0)=>request('inbox','GET',null,{device_id:deviceId,after}),
        acknowledge: (deviceId,ids)=>request('ack','POST',{device_id:deviceId,ids}),
        sendRoomEvent:payload=>request('room-events','POST',payload),
        roomEvents: (dialogUid,after=0)=>request('room-events','GET',null,{dialog_uid:dialogUid,after}),
        revokeDevice:deviceId=>request('revoke','POST',{device_id:deviceId}),
        uploadCiphertext,
        downloadCiphertext,
    });
}
