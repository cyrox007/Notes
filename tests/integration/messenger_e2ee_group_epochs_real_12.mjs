import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash, webcrypto } from 'node:crypto';
import * as sdk from '@matrix-org/matrix-sdk-crypto-wasm';
import { createE2eeConversation } from '../../modules/messenger/crypto/matrix-conversation.mjs';
import { notesMatrixUserId, notesMatrixRoomId, publishInitialKeys }
    from '../../modules/messenger/crypto/matrix-device-bootstrap.mjs';

globalThis.crypto ??= webcrypto;

const alice = '11111111-1111-4111-8111-111111111111';
const bob = '22222222-2222-4222-8222-222222222222';
const carol = '33333333-3333-4333-8333-333333333333';
const roomUid = '77777777-7777-4777-8777-777777777777';

test('Matrix Rust Crypto: вступление и исключение участника меняют ключ будущих сообщений', async () => {
    await sdk.initAsync();
    const devices = new Map([[alice, []], [bob, []], [carol, []]]);
    const prekeys = new Map();
    const inboxes = new Map();
    const timeline = [];
    const machines = [];
    const joined = new Map([[alice, 1], [bob, 1]]);
    let members = [alice, bob];
    let epoch = 1;
    let packetId = 0;

    const participants = () => ({
        members: [...members],
        fingerprint: createHash('sha256').update([...members].sort().join('\n')).digest('hex'),
        membership_version: epoch,
    });
    const getDevices = async uid => ({
        devices: (devices.get(uid) || []).map(item => ({...item}))
    });
    const claimPrekey = async (uid, deviceId) => ({
        prekey: (prekeys.get(uid + ':' + deviceId) || []).shift() || null
    });
    const trustStore = () => {
        const pins = new Map();
        return {
            pin: async (uid, deviceId, key) => {
                pins.set(uid + ':' + deviceId, key);
            },
            isTrusted: async (uid, deviceId, key) => pins.get(uid + ':' + deviceId) === key,
        };
    };
    const transport = (uid, deviceId) => ({
        participants: async () => participants(),
        getDevices,
        claimPrekey,
        sendDevicePacket: async payload => {
            if (payload.membership_version !== epoch
                || !members.includes(uid)
                || !members.includes(payload.recipient_user_uid)) {
                throw new Error('Пакет устаревшей эпохи отклонён');
            }
            const destination = payload.recipient_user_uid + ':' + payload.recipient_device_id;
            const queue = inboxes.get(destination) || [];
            queue.push({
                id: ++packetId,
                sender_user_uid: uid,
                sender_device_id: deviceId,
                recipient_device_id: payload.recipient_device_id,
                envelope: payload.envelope,
                epoch,
            });
            inboxes.set(destination, queue);
            return {queued: true};
        },
        inbox: async id => {
            if (!members.includes(uid)) throw new Error('Участник исключён');
            return {messages: (inboxes.get(uid + ':' + id) || []).filter(
                item => item.epoch >= joined.get(uid)
            )};
        },
        acknowledge: async (id, ids) => {
            const key = uid + ':' + id;
            inboxes.set(key, (inboxes.get(key) || []).filter(row => !ids.includes(row.id)));
            return {acknowledged: true};
        },
        sendRoomEvent: async item => {
            if (item.membership_version !== epoch || !members.includes(uid)) {
                throw new Error('Состав изменён до записи сообщения');
            }
            timeline.push({
                id: timeline.length + 1,
                membership_version: epoch,
                sender_uid: uid,
                sender_device_id: deviceId,
                encrypted: item.encrypted,
                created_at: '2026-11-08 08:00:00',
            });
            return {stored: true};
        },
        roomEvents: async (_, after) => ({
            events: timeline.filter(row =>
                row.id > after && members.includes(uid)
                && row.membership_version >= (joined.get(uid) || Number.MAX_SAFE_INTEGER))
        }),
    });
    const register = async (uid, deviceId, endpoint, payload) => {
        if (endpoint === '/e2ee/devices') {
            devices.get(uid).push({
                device_id: deviceId,
                signing_key: payload.identity.signing_key,
                agreement_key: payload.identity.agreement_key,
                identity: payload.identity,
                verified: false,
            });
            return {registered: true};
        }
        if (endpoint === '/e2ee/prekeys') {
            const key = uid + ':' + deviceId;
            const list = prekeys.get(key) || [];
            for (const [key_id, value] of Object.entries(payload.keys)) {
                list.push({device_id: deviceId, key_id, key: value});
            }
            prekeys.set(key, list);
            return {published: Object.keys(payload.keys).length};
        }
        throw new Error('Неизвестная операция криптодвижка');
    };
    const openDevice = async (uid, id) => {
        const machine = await sdk.OlmMachine.initialize(
            new sdk.UserId(notesMatrixUserId(uid)), new sdk.DeviceId(id)
        );
        machines.push(machine);
        await publishInitialKeys(machine, uid, id, (endpoint, payload) =>
            register(uid, id, endpoint, payload)
        );
        const trust = trustStore();
        return {
            uid, id, machine, trust,
            conversation: createE2eeConversation({
                sdk, machine, trust, transport: transport(uid, id), userUid: uid, deviceId: id,
            }),
        };
    };
    try {
        const first = await openDevice(alice, 'DEVICE_ALICE_GROUP');
        const second = await openDevice(bob, 'DEVICE_BOB_GROUP');
        const third = await openDevice(carol, 'DEVICE_CAROL_GROUP');
        for (const client of [first, second, third]) {
            for (const uid of [alice, bob, carol]) {
                for (const row of devices.get(uid)) {
                    await client.trust.pin(uid, row.device_id, row.signing_key);
                }
            }
        }

        await first.conversation.send(roomUid, 'До добавления третьего');
        assert.deepEqual(
            (await second.conversation.receive(roomUid, 0)).messages.map(row => row.text),
            ['До добавления третьего']
        );
        await assert.rejects(third.conversation.receive(roomUid, 0),
            /Состав участников защищённого диалога/);

        // Новому участнику выдаётся только свежий Megolm-сеанс.
        members = [alice, bob, carol];
        epoch = 2;
        joined.set(carol, epoch);
        await first.conversation.send(roomUid, 'После добавления третьего');
        assert.deepEqual(
            (await third.conversation.receive(roomUid, 0)).messages.map(row => row.text),
            ['После добавления третьего']
        );
        assert.deepEqual(
            (await second.conversation.receive(roomUid, 1)).messages.map(row => row.text),
            ['После добавления третьего']
        );

        // После исключения прежние ключи Боба не должны открыть новую эпоху.
        members = [alice, carol];
        epoch = 3;
        inboxes.delete(bob + ':DEVICE_BOB_GROUP');
        await first.conversation.send(roomUid, 'После исключения второго');
        assert.deepEqual(
            (await third.conversation.receive(roomUid, 2)).messages.map(row => row.text),
            ['После исключения второго']
        );
        await assert.rejects(second.conversation.receive(roomUid, 2),
            /Состав участников защищённого диалога/);
        assert.equal((inboxes.get(bob + ':DEVICE_BOB_GROUP') || []).length, 0,
            'исключённое устройство не получило нового ключевого пакета');
        const latest = timeline[2];
        const encryptedEvent = {
            event_id: '$notes-' + latest.id + ':notes.local',
            origin_server_ts: Date.parse('2026-11-08T08:00:00Z'),
            room_id: notesMatrixRoomId(roomUid),
            sender: notesMatrixUserId(alice),
            type: 'm.room.encrypted',
            content: latest.encrypted,
        };
        await assert.rejects(second.machine.decryptRoomEvent(
            JSON.stringify(encryptedEvent),
            new sdk.RoomId(notesMatrixRoomId(roomUid)),
            new sdk.DecryptionSettings(sdk.TrustRequirement.Untrusted)
        ), 'старый Megolm-сеанс не должен расшифровать новую эпоху');

        // Смена эпохи между раздачей ключей и записью отменяет публикацию.
        const before = timeline.length;
        const prior = first.conversation;
        const oldEpoch = epoch;
        // Воссоздаём транспорт с точечным отказом на последней проверке.
        const changingTransport = transport(alice, first.id);
        const originalParticipants = changingTransport.participants;
        let count = 0;
        changingTransport.participants = async () => {
            const snapshot = await originalParticipants();
            if (++count === 2) {
                epoch = oldEpoch + 1;
                return participants();
            }
            return snapshot;
        };
        const changingClient = createE2eeConversation({
            sdk, machine: first.machine, trust: first.trust, transport: changingTransport,
            userUid: alice, deviceId: first.id,
        });
        await assert.rejects(changingClient.send(roomUid, 'Нельзя при смене состава'),
            /Состав диалога изменился|Состав участников изменился/);
        assert.equal(timeline.length, before);
        assert.ok(prior);
    } finally {
        for (const machine of machines) await machine.close();
    }
});
