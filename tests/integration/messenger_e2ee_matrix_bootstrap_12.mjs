import test from 'node:test';
import assert from 'node:assert/strict';
import { notesMatrixUserId, notesMatrixRoomId, openClientMachine, publishInitialKeys } from '../../modules/messenger/crypto/matrix-device-bootstrap.mjs';

const user = '11111111-1111-4111-8111-111111111111';
const device = 'DEVICE_001';
const userId = '@' + user + ':notes.local';
const id = {
    user_id: userId,
    device_id: device,
    keys: { ['ed25519:' + device]: 'sign', ['curve25519:' + device]: 'agreement' },
    signatures: { [userId]: { ['ed25519:' + device]: 'signature' } },
};

test('идентификаторы Matrix однозначны и не принимают произвольный ввод', () => {
    assert.equal(notesMatrixUserId(user), userId);
    assert.equal(notesMatrixRoomId(user), '!' + user + ':notes.local');
    assert.throws(() => notesMatrixUserId('some-name'));
    assert.throws(() => notesMatrixRoomId('/etc/passwd'));
});

test('движок открывает постоянное зашифрованное хранилище устройства', async () => {
    const events = [];
    class UserId { constructor(value) { this.value = value; } }
    class DeviceId { constructor(value) { this.value = value; } }
    const sdk = {
        UserId, DeviceId,
        initAsync: async () => events.push('инициализация'),
        OlmMachine: { initialize: async (...args) => {
            events.push(args.map(x => x?.value ?? x));
            return { secure: true };
        } },
    };
    const opened = await openClientMachine(sdk, user, device, 'длинная-парольная-фраза-для-ключей');
    assert.equal(opened.machine.secure, true);
    assert.equal(events.length, 2);
    assert.deepEqual(events[1].slice(0, 2), [userId, device]);
    assert.ok(events[1][2].includes('notes-e2ee-v1-'));
    assert.throws(() => openClientMachine(sdk, user, device, 'short'));
});

test('публикуются только подписанные публичные ключи и одноразовые ключи', async () => {
    const calls = [], marked = [];
    const machine = {
        outgoingRequests: async () => [{
            id: 'request-1', type: 2, body: JSON.stringify({
                device_keys: id,
                one_time_keys: { 'signed_curve25519:77': { key: 'public-only', signatures: {} } },
            }),
        }],
        markRequestAsSent: async (...args) => { marked.push(args); return true; },
    };
    const result = await publishInitialKeys(machine, user, device, async (...args) => calls.push(args));
    assert.deepEqual(result, { registered: true, published: 1 });
    assert.equal(calls.length, 2);
    assert.equal(calls[0][0], '/e2ee/devices');
    assert.equal(calls[0][1].identity.device_keys.keys['ed25519:' + device], 'sign');
    assert.equal(calls[1][0], '/e2ee/prekeys');
    assert.equal(marked.length, 1);
    assert.equal(marked[0][0], 'request-1');
});

test('неподписанный ключ, резервный ключ и сетевой отказ не подтверждаются движку', async () => {
    const marked = [];
    const machine = (body) => ({
        outgoingRequests: async () => [{ id: 'r', type: 2, body: JSON.stringify(body) }],
        markRequestAsSent: async (...args) => marked.push(args),
    });
    await assert.rejects(publishInitialKeys(machine({ device_keys: { ...id, signatures: {} } }), user, device, async () => {}));
    await assert.rejects(publishInitialKeys(machine({ device_keys: id, fallback_keys: { k: 'unsupported' } }), user, device, async () => {}));
    await assert.rejects(publishInitialKeys(machine({ device_keys: id }), user, device, async () => { throw new Error('network'); }));
    assert.equal(marked.length, 0);
});
