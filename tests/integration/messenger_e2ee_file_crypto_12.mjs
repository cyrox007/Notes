import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import test from 'node:test';

globalThis.crypto ??= webcrypto;
const { encryptAttachment, decryptAttachment } = await import(
    '../../modules/messenger/crypto/e2ee-file-crypto.mjs'
);

const dialog = '77777777-7777-4777-8777-777777777777';
const file = 'attachment-00000001';

test('зашифрованный файл возвращает исходные байты только с секретом', async () => {
    const input = new TextEncoder().encode('Только у получателя должен быть текст 📁');
    const result = await encryptAttachment(input, dialog, file);
    assert.notDeepEqual(result.ciphertext, input);
    assert.equal(result.ciphertext.length, input.length + 16);
    assert.equal(result.secret.version, 1);
    assert.equal(result.secret.key.length, 43);
    assert.deepEqual(await decryptAttachment(result.ciphertext, dialog, file, result.secret), input);
});

test('каждое шифрование получает независимый случайный ключ и nonce', async () => {
    const data = new Uint8Array([11, 22, 33]);
    const a = await encryptAttachment(data, dialog, file);
    const b = await encryptAttachment(data, dialog, file);
    assert.notEqual(a.secret.key, b.secret.key);
    assert.notEqual(a.secret.iv, b.secret.iv);
    assert.notDeepEqual(a.ciphertext, b.ciphertext);
});

test('изменение шифротекста и контекста запрещает расшифровку', async () => {
    const a = await encryptAttachment(new Uint8Array([4, 5, 6]), dialog, file);
    const modified = a.ciphertext.slice();
    modified[modified.length - 1] ^= 1;
    await assert.rejects(decryptAttachment(modified, dialog, file, a.secret));
    await assert.rejects(decryptAttachment(a.ciphertext, 'other-dialog', file, a.secret));
    await assert.rejects(decryptAttachment(a.ciphertext, dialog, 'other-file', a.secret));
    await assert.rejects(decryptAttachment(a.ciphertext, dialog, file, { ...a.secret, key: 'malformed' }));
});

test('отсутствие криптографии и слишком большие файлы обрабатываются безопасно', async () => {
    await assert.rejects(encryptAttachment(new Uint8Array(0), dialog, file));
    await assert.rejects(encryptAttachment(new Uint8Array(10 * 1024 * 1024 + 1), dialog, file));
    await assert.rejects(encryptAttachment(new Uint8Array([1]), '', file));
    await assert.rejects(decryptAttachment(new Uint8Array([1]), dialog, file, { algorithm: 'none' }));
});
