import assert from 'node:assert/strict';
import test from 'node:test';
import { writeFile } from 'node:fs/promises';
import * as sdk from '@matrix-org/matrix-sdk-crypto-wasm';
import { notesMatrixUserId } from '../../modules/messenger/crypto/matrix-device-bootstrap.mjs';

test('настоящий движок Matrix Rust Crypto создаёт подписанные ключи устройства', async () => {
    await sdk.initAsync();
    const user = notesMatrixUserId('11111111-1111-4111-8111-111111111111');
    const machine = await sdk.OlmMachine.initialize(
        new sdk.UserId(user), new sdk.DeviceId('DEVICE_001')
    );
    try {
        const requests = await machine.outgoingRequests();
        const uploads = requests.filter(request => {
            try { return !!JSON.parse(request.body).device_keys; } catch { return false; }
        });
        assert.ok(uploads.length >= 1, 'движок выдал хотя бы один запрос публикации идентичности');
        const requestWithIdentity = uploads.map(r => JSON.parse(r.body).device_keys)
            .find(keys => keys?.user_id === user && keys?.device_id === 'DEVICE_001');
        assert.ok(requestWithIdentity, 'есть подписанная идентичность текущего устройства');
        const keys = requestWithIdentity;
        assert.equal(keys.user_id, user);
        assert.equal(keys.device_id, 'DEVICE_001');
        assert.ok(keys.keys['ed25519:DEVICE_001']);
        assert.ok(keys.keys['curve25519:DEVICE_001']);
        assert.ok(keys.signatures[user]['ed25519:DEVICE_001']);
        if (process.env.E2EE_MATRIX_KEY_FIXTURE) {
            await writeFile(process.env.E2EE_MATRIX_KEY_FIXTURE, JSON.stringify(keys));
        }
    } finally {
        await machine.close();
    }
});
