import { notesMatrixUserId } from './matrix-device-bootstrap.mjs';

const DEVICE = /^[A-Za-z0-9_-]{8,64}$/;
const PUBLIC_KEY = /^[A-Za-z0-9+/_-]{42,44}={0,2}$/;

/**
 * Код сравнения вычисляется из двух публичных ключей, владельца и устройства.
 * Он НЕ является паролем и НЕ переносит приватные ключи.
 * Код необходимо считать с экрана другого устройства по независимому каналу.
 */
function normalizeKey(value) {
    if (typeof value !== 'string' || !PUBLIC_KEY.test(value) || !globalThis.atob) {
        throw new TypeError('Повреждён открытый ключ устройства');
    }
    const raw = atob(value.replace(/-/g, '+').replace(/_/g, '/')
        .replace(/=+$/, '').padEnd(44, '='));
    if (raw.length !== 32) {
        throw new TypeError('Ключ устройства должен содержать 32 байта');
    }
    return btoa(raw).replace(/=+$/, '');
}

function checkedDescriptor({ userUid, deviceId, signingKey, agreementKey }) {
    const matrixId = notesMatrixUserId(userUid);
    if (typeof deviceId !== 'string' || !DEVICE.test(deviceId)) {
        throw new TypeError('Неизвестный идентификатор устройства');
    }
    const signing = normalizeKey(signingKey);
    const agreement = normalizeKey(agreementKey);
    if (signing === agreement) {
        throw new Error('Ключи подписи и обмена совпадают');
    }
    return [matrixId, deviceId, signing, agreement].join('\n');
}

export async function deviceComparisonCode(descriptor) {
    if (!globalThis.crypto?.subtle || !globalThis.TextEncoder) {
        throw new Error('Не поддерживается безопасное сравнение ключей устройства');
    }
    const input = 'notes-e2ee-device-compare-v1\n' + checkedDescriptor(descriptor);
    const hash = new Uint8Array(await crypto.subtle.digest(
        'SHA-256', new TextEncoder().encode(input)
    ));
    // 128 бит не позволяют реалистично подобрать другой комплект ключей
    // с тем же сравнительным кодом; группа по 4 символа упрощает сверку.
    return Array.from(hash.slice(0, 16), n => n.toString(16).padStart(2, '0'))
        .join('').toUpperCase().match(/.{4}/g).join(' ');
}

export function normalizeComparisonCode(value) {
    if (typeof value !== 'string' || value.length > 96) {
        throw new TypeError('Введите код с экрана другого устройства');
    }
    const normalized = value.toUpperCase().replace(/[\s-]/g, '');
    if (!/^[0-9A-F]{32}$/.test(normalized)) {
        throw new TypeError('Код сравнения должен содержать 32 шестнадцатеричных знака');
    }
    return normalized;
}

function equalCodes(left, right) {
    // Сравнение без преждевременного выхода по первому несовпадению.
    let difference = 0;
    for (let i = 0; i < left.length; i++) {
        difference |= left.charCodeAt(i) ^ right.charCodeAt(i);
    }
    return difference === 0;
}

/**
 * Доверие выдаётся локально лишь после сравнения кода с экрана второго
 * устройства и повторной проверки действующего серверного реестра.
 * Серверный флаг verified не используется.
 */
export async function confirmIndependentDevice({
    userUid, device, input, fetchDevices, localTrust,
}) {
    if (typeof fetchDevices !== 'function'
        || typeof localTrust?.pin !== 'function') {
        throw new TypeError('Проверка нового устройства не подготовлена');
    }
    const expected = await deviceComparisonCode({
        userUid, deviceId: device?.device_id,
        signingKey: device?.signing_key, agreementKey: device?.agreement_key,
    });
    const actual = normalizeComparisonCode(input);
    if (!equalCodes(actual, normalizeComparisonCode(expected))) {
        throw new Error('Код не совпал с устройством. Не подтверждайте ключи.');
    }

    const latest = (await fetchDevices(userUid))?.devices;
    if (!Array.isArray(latest) || latest.length > 12) {
        throw new Error('Не удалось проверить действующий реестр устройств');
    }
    const matching = latest.filter(row => row?.device_id === device.device_id);
    if (matching.length !== 1) {
        throw new Error('Устройство отозвано, отсутствует или неоднозначно');
    }
    const current = matching[0];
    const currentCode = await deviceComparisonCode({
        userUid, deviceId: current.device_id,
        signingKey: current.signing_key, agreementKey: current.agreement_key,
    });
    if (!equalCodes(actual, normalizeComparisonCode(currentCode))) {
        throw new Error('Реестр изменился во время подтверждения. Повторите сверку.');
    }
    return localTrust.pin(userUid, current.device_id, current.signing_key,
        ({fingerprint}) => typeof fingerprint === 'string' && fingerprint.length > 0);
}
