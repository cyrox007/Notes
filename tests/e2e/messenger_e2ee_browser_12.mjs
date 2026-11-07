import test from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {join,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';

const root=dirname(dirname(dirname(fileURLToPath(import.meta.url))));
const base=join(root,'modules','messenger','crypto');
const allowed=new Map([
    ['/messenger/e2ee/sdk/index.mjs','sdk/index.mjs'],
    ['/messenger/e2ee/sdk/pkg/matrix_sdk_crypto_wasm_bg.js','sdk/pkg/matrix_sdk_crypto_wasm_bg.js'],
    ['/messenger/e2ee/sdk/pkg/matrix_sdk_crypto_wasm_bg.wasm','sdk/pkg/matrix_sdk_crypto_wasm_bg.wasm'],
    ['/messenger/e2ee/client/matrix-device-bootstrap.mjs','matrix-device-bootstrap.mjs'],
    ['/messenger/e2ee/client/matrix-device-session.mjs','matrix-device-session.mjs'],
]);
const uid='11111111-1111-4111-8111-111111111111';

test('Chromium: Matrix WASM загружается локально и ключи сохраняются в IndexedDB',async()=>{
    const requests=[];
    const server=createServer(async(req,res)=>{
        const pathname=new URL(req.url,'http://localhost').pathname;
        requests.push(pathname);
        if(pathname==='/'){
            // Та же политика, что выдаёт ядро на /messenger.
            res.writeHead(200,{
                'content-type':'text/html; charset=utf-8',
                'content-security-policy':"default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'; object-src 'none'",
                'cache-control':'no-store'
            });
            res.end('<!doctype html><html lang="ru"><meta charset="utf-8"><title>Испытание E2EE</title></html>');
            return;
        }
        const file=allowed.get(pathname);
        if(!file){
            res.writeHead(404);
            res.end();
            return;
        }
        try{
            const bytes=await readFile(join(base,file));
            res.writeHead(200,{
                'content-type':file.endsWith('.wasm')?'application/wasm':'text/javascript; charset=utf-8',
                'cache-control':'no-store','x-content-type-options':'nosniff',
                'cross-origin-resource-policy':'same-origin'
            });
            res.end(bytes);
        }catch{
            res.writeHead(404);res.end();
        }
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const origin='http://127.0.0.1:'+server.address().port;
    const browser=await chromium.launch({headless:true});
    const context=await browser.newContext();
    try{
        const page=await context.newPage();
        const pageErrors=[];
        page.on('pageerror',error=>pageErrors.push(error.message));
        await page.goto(origin,{waitUntil:'domcontentloaded'});
        const first=await page.evaluate(async(uid)=>{
            const sdk=await import('/messenger/e2ee/sdk/index.mjs');
            const bootstrap=await import('/messenger/e2ee/client/matrix-device-bootstrap.mjs');
            const opened=await bootstrap.openClientMachine(
                sdk,uid,'DEVICE_BROWSER_001','независимый-пароль-криптографии-2026'
            );
            const machine=opened.machine;
            const signer=machine.identityKeys.ed25519.toBase64();
            const result={id:machine.deviceId.toString(),uid:machine.userId.toString(),signer};
            await machine.close();
            return result;
        },uid);
        assert.equal(first.id,'DEVICE_BROWSER_001');
        assert.equal(first.uid,'@'+uid+':notes.local');
        assert.ok(first.signer.length>40);

        await page.reload({waitUntil:'domcontentloaded'});
        const second=await page.evaluate(async(uid)=>{
            const sdk=await import('/messenger/e2ee/sdk/index.mjs');
            const bootstrap=await import('/messenger/e2ee/client/matrix-device-bootstrap.mjs');
            const opened=await bootstrap.openClientMachine(
                sdk,uid,'DEVICE_BROWSER_001','независимый-пароль-криптографии-2026'
            );
            const signer=opened.machine.identityKeys.ed25519.toBase64();
            await opened.machine.close();
            return signer;
        },uid);
        assert.equal(second,first.signer,'Повторное открытие должно восстанавливать то же устройство');

        // Две реальные вкладки в одном браузерном профиле делят IndexedDB.
        // Только одна вправе открывать OlmMachine для одного device_id.
        const key=await page.evaluate(async userUid=>{
            const api=await import('/messenger/e2ee/client/matrix-device-session.mjs');
            window.__notesE2eeLease=await api.acquireDeviceSession(userUid,'DEVICE_BROWSER_001');
            const held=await navigator.locks.query();
            return held.held.find(item=>item.name.includes('DEVICE_BROWSER_001'))?.name;
        },uid);
        assert.ok(key,'Первая вкладка удерживает Web Locks до закрытия устройства');

        const competing=await context.newPage();
        await competing.goto(origin,{waitUntil:'domcontentloaded'});
        const refusal=await competing.evaluate(async userUid=>{
            const api=await import('/messenger/e2ee/client/matrix-device-session.mjs');
            try{
                const lock=await api.acquireDeviceSession(userUid,'DEVICE_BROWSER_001');
                lock.release();
                return 'ОШИБКА: вторая вкладка получила тот же ключ';
            }catch(error){
                return error.message;
            }
        },uid);
        assert.match(refusal,/уже открыто в другой вкладке/);

        // Уход со страницы автоматически освобождает удерживаемую блокировку.
        await page.close();
        await competing.waitForFunction(async name=>{
            const state=await navigator.locks.query();
            return !state.held.some(row=>row.name===name);
        },key);
        const afterClose=await competing.evaluate(async userUid=>{
            const api=await import('/messenger/e2ee/client/matrix-device-session.mjs');
            const lease=await api.acquireDeviceSession(userUid,'DEVICE_BROWSER_001');
            lease.release();
            return true;
        },uid);
        assert.equal(afterClose,true,'После закрытия вкладки второе окно получает доступ к ключам');
        await competing.close();

        assert.equal(pageErrors.length,0,pageErrors.join('; '));
        assert.ok(requests.includes('/messenger/e2ee/sdk/index.mjs'));
        assert.ok(requests.includes('/messenger/e2ee/sdk/pkg/matrix_sdk_crypto_wasm_bg.wasm'));
        assert.equal(requests.some(path=>path.startsWith('https://')),false);
    }finally{
        await context.close();
        await browser.close();
        await new Promise(resolve=>server.close(resolve));
    }
});
