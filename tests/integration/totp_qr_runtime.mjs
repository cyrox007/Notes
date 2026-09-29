import crypto from 'node:crypto';
import fs from 'node:fs';
import vm from 'node:vm';

const root = new URL('../../', import.meta.url);
const source = fs.readFileSync(new URL('assets/js/totp-qr.js', root), 'utf8');
vm.runInThisContext(source, { filename: 'assets/js/totp-qr.js' });

const qr = globalThis.WorkspaceTotpQr;
if (!qr || typeof qr.matrixFor !== 'function') {
    throw new Error('Локальный генератор TOTP QR не экспортирует matrixFor');
}

const uri = 'otpauth://totp/Workspace%20Organizer%3Aalex%40example.test'
    + '?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
    + '&issuer=Workspace%20Organizer&algorithm=SHA1&digits=6&period=30';
const matrix = qr.matrixFor(uri);

if (!Array.isArray(matrix) || matrix.length !== 57) {
    throw new Error('Ожидалась QR-матрица версии 10 размером 57x57');
}
if (!matrix.every((row) => (
    Array.isArray(row)
    && row.length === matrix.length
    && row.every((cell) => typeof cell === 'boolean')
))) {
    throw new Error('QR-матрица содержит некорректные ячейки');
}

const digest = crypto
    .createHash('sha256')
    .update(matrix.flat().map((cell) => cell ? '1' : '0').join(''))
    .digest('hex');
if (digest !== '37f6503e582266b934285fc8585323cc35197ba7358466d0aee960030f25ea5a') {
    throw new Error('Эталонная TOTP QR-матрица изменилась');
}

const longUri = 'otpauth://totp/Workspace:' + 'a'.repeat(350)
    + '?secret=ABCDEFGHIJKLMNOPQRSTUVWXYZ234567&issuer=Workspace';
const longMatrix = qr.matrixFor(longUri);
if (!Array.isArray(longMatrix) || longMatrix.length !== 77) {
    throw new Error('Длинный TOTP URI не переключился на QR версии 15');
}

try {
    qr.matrixFor('https://example.test/not-totp');
    throw new Error('Генератор принял URI вне схемы otpauth://totp/');
} catch (error) {
    if (String(error?.message || '').includes('принял URI')) {
        throw error;
    }
}

for (const forbidden of ['fetch(', 'XMLHttpRequest', 'sendBeacon(', 'WebSocket(']) {
    if (source.includes(forbidden)) {
        throw new Error('Локальный генератор QR содержит сетевой API: ' + forbidden);
    }
}

console.log('Локальный TOTP QR runtime: OK');
