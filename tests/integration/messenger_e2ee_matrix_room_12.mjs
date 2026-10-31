import test from 'node:test';
import assert from 'node:assert/strict';
import { prepareEncryptedText, sendPreparedEncryptedText } from '../../modules/messenger/crypto/matrix-room-messages.mjs';

const dialog = '77777777-7777-4777-8777-777777777777';
const encrypted = { algorithm: 'm.megolm.v1.aes-sha2', ciphertext: 'encrypted-base64-not-readable',
    session_id: 'opaque-session-id', sender_key: 'sender-public-key', device_id: 'DEVICE_001' };
const sdk = { RoomId: class { constructor(id) { this.value = id; } } };
const transaction = 'transaction_0000000001';

test('только криптодвижок получает открытый текст, сервер — лишь шифротекст', async () => {
    const seen = [];
    const machine = { encryptRoomEvent: async (...args) => {
        seen.push(args);
        return JSON.stringify(encrypted);
    } };
    const ready = await prepareEncryptedText(sdk, machine, dialog, 'DEVICE_001', 'Привет!', transaction);
    assert.equal(seen[0][0].value, '!' + dialog + ':notes.local');
    assert.equal(JSON.parse(seen[0][2]).body, 'Привет!');
    assert.equal(JSON.stringify(ready).includes('Привет!'), false);
    const sent = [];
    await sendPreparedEncryptedText(ready, async (...args) => {
        sent.push(args);
        return { stored: true };
    });
    assert.equal(sent[0][0], '/e2ee/room-events');
    assert.equal(sent[0][1].encrypted, ready.encrypted);
});

test('запрещена отправка при неподготовленной сессии и подменённом формате', async () => {
    await assert.rejects(prepareEncryptedText(sdk, {}, dialog, 'DEVICE_001', 'Текст', transaction));
    await assert.rejects(prepareEncryptedText(sdk,
        { encryptRoomEvent: async () => JSON.stringify({ body: 'Текст' }) },
        dialog, 'DEVICE_001', 'Текст', transaction));
    await assert.rejects(sendPreparedEncryptedText({ encrypted }, async () => ({ stored: false })));
});
