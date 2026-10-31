import assert from 'node:assert/strict';
import test from 'node:test';
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
        assert.equal(uploads.length, 1);
        const keys = JSON.parse(uploads[0].body).device_keys;
        assert.equal(keys.user_id, user);
        assert.equal(keys.device_id, 'DEVICE_001');
        assert.ok(keys.keys['ed25519:DEVICE_001']);
        assert.ok(keys.keys['curve25519:DEVICE_001']);
        assert.ok(keys.signatures[user]['ed25519:DEVICE_001']);
    } finally {
        await machine.close();
    }
});
