import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { notesUserFromMatrix, handleKeyQuery, handleKeyClaim, handleToDevice } from '../../modules/messenger/crypto/matrix-transport-bridge.mjs';

globalThis.crypto ??= webcrypto;
const uid = '22222222-2222-4222-8222-222222222222';
const matrixUid = '@' + uid + ':notes.local';
const keys = { user_id: matrixUid, device_id: 'DEVICE_002', signatures: {} };
const machine = () => {
    const marked = [];
    return { marked, markRequestAsSent: async (...args) => marked.push(args) };
};
const request = (body) => ({ id: 'repeatable-request-001', type: 2, body: JSON.stringify(body) });

test('выдача устройств передаёт криптодвижку именно подписанные объекты Matrix', async () => {
    const state = machine();
    const result = await handleKeyQuery(state, request({ device_keys: { [matrixUid]: [] } }),
        async () => ({ devices: [{ device_id: 'DEVICE_002', identity: { device_keys: keys } }] }));
    assert.deepEqual(result.device_keys[matrixUid].DEVICE_002, keys);
    assert.equal(state.marked.length, 1);
    assert.equal(notesUserFromMatrix(matrixUid), uid);
    assert.throws(() => notesUserFromMatrix('@other:server'));
});

test('одноразовые ключи доступны только для ожидаемого устройства и алгоритма', async () => {
    const state = machine();
    const signed = { key: 'public', signatures: { [matrixUid]: { 'ed25519:DEVICE_002': 'signature' } } };
    const result = await handleKeyClaim(state, request({ one_time_keys: { [matrixUid]: { DEVICE_002: 'signed_curve25519' } } }),
        async () => ({ prekey: { device_id: 'DEVICE_002', key_id: 'signed_curve25519:1', key: signed } }));
    assert.equal(result.one_time_keys[matrixUid].DEVICE_002['signed_curve25519:1'], signed);
    assert.equal(state.marked.length, 1);
});

test('неудачный сетевой запрос не отмечается успешным', async () => {
    const state = machine();
    await assert.rejects(handleKeyQuery(state, request({ device_keys: { [matrixUid]: [] } }),
        async () => { throw new Error('Нет связи'); }));
    assert.equal(state.marked.length, 0);
});

test('доставка пакетов при повторе получает постоянный идентификатор', async () => {
    const sent = [];
    const event = { algorithm: 'm.olm.v1.curve25519-aes-sha2', sender_key: 'key', ciphertext: { key: { type: 0, body: 'cipher' } } };
    const r = request({ messages: { [matrixUid]: { DEVICE_002: event } } });
    const first = machine();
    await handleToDevice(first, r, 'DEVICE_001', '77777777-7777-4777-8777-777777777777',
        async payload => { sent.push(payload); });
    const second = machine();
    await handleToDevice(second, r, 'DEVICE_001', '77777777-7777-4777-8777-777777777777',
        async payload => { sent.push(payload); });
    assert.equal(sent[0].transaction_id, sent[1].transaction_id);
    assert.equal(first.marked.length, 1);
    assert.equal(second.marked.length, 1);
});

test('отказ по одному устройству не подтверждает групповую передачу', async () => {
    const state = machine();
    const envelope = { algorithm: 'm.olm.v1.curve25519-aes-sha2', sender_key: 'key', ciphertext: { key: {} } };
    await assert.rejects(handleToDevice(state,
        request({ messages: { [matrixUid]: { DEVICE_002: envelope, DEVICE_003: envelope } } }),
        'DEVICE_001', '77777777-7777-4777-8777-777777777777',
        async payload => { if (payload.recipient_device_id === 'DEVICE_003') throw new Error('Нет связи'); }));
    assert.equal(state.marked.length, 0);
});
