/**
 * Шифрование вложения на устройстве до отправки на сервер.
 * Секретный дескриптор разрешено пересылать ТОЛЬКО внутри проверенного
 * сквозно зашифрованного сообщения. Не помещать его в метаданные HTTP-загрузки.
 */
const MAX_FILE_BYTES = 10 * 1024 * 1024;
const encoder = new TextEncoder();

function secretContext(dialogUid, attachmentUid) {
    if (!/^[A-Za-z0-9._~-]{1,128}$/.test(dialogUid)
        || !/^[A-Za-z0-9._~-]{8,128}$/.test(attachmentUid)) {
        throw new TypeError('Некорректный контекст зашифрованного вложения');
    }
    return encoder.encode('notes-messenger-file-v1\0' + dialogUid + '\0' + attachmentUid);
}

function requireCrypto() {
    if (!globalThis.crypto?.subtle || typeof globalThis.crypto.getRandomValues !== 'function') {
        throw new Error('Для зашифрованных вложений нужен безопасный контекст Web Crypto');
    }
    return globalThis.crypto;
}

function encode64url(bytes) {
    let text = '';
    for (const byte of bytes) text += String.fromCharCode(byte);
    return btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function decode64url(value, count) {
    if (typeof value !== 'string' || !/^[A-Za-z0-9_-]+$/.test(value)) {
        throw new TypeError('Неверный секретный дескриптор вложения');
    }
    const padded = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
    const decoded = atob(padded);
    if (decoded.length !== count) {
        throw new TypeError('Некорректная длина секретного ключа или nonce');
    }
    return Uint8Array.from(decoded, (value) => value.charCodeAt(0));
}

function fileBytes(input, allowAuthenticationTag = false) {
    const bytes = input instanceof Uint8Array
        ? input
        : input instanceof ArrayBuffer
            ? new Uint8Array(input)
            : null;
    if (!bytes || bytes.byteLength < 1 || bytes.byteLength > MAX_FILE_BYTES + (allowAuthenticationTag ? 16 : 0)) {
        throw new RangeError('Зашифрованное вложение должно быть от 1 байта до 10 МиБ');
    }
    return bytes;
}

/**
 * Возвращает шифротекст для загрузки и отдельный секрет.
 * Секрет необходимо передавать получателю через Olm/Megolm; серверу — никогда.
 */
export async function encryptAttachment(input, dialogUid, attachmentUid) {
    const bytes = fileBytes(input);
    const cryptoProvider = requireCrypto();
    const keyBytes = cryptoProvider.getRandomValues(new Uint8Array(32));
    const ivBytes = cryptoProvider.getRandomValues(new Uint8Array(12));
    const aad = secretContext(dialogUid, attachmentUid);

    try {
        const key = await cryptoProvider.subtle.importKey('raw', keyBytes, 'AES-GCM', false, ['encrypt']);
        const encrypted = await cryptoProvider.subtle.encrypt(
            { name: 'AES-GCM', iv: ivBytes, additionalData: aad, tagLength: 128 },
            key,
            bytes
        );
        return {
            ciphertext: new Uint8Array(encrypted),
            secret: {
                version: 1,
                algorithm: 'AES-256-GCM',
                key: encode64url(keyBytes),
                iv: encode64url(ivBytes),
                attachment_uid: attachmentUid,
            },
        };
    } finally {
        keyBytes.fill(0);
    }
}

/** Расшифровывает только после получения секретного дескриптора из E2EE-сеанса. */
export async function decryptAttachment(input, dialogUid, attachmentUid, secret) {
    const bytes = fileBytes(input, true);
    if (!secret || secret.version !== 1 || secret.algorithm !== 'AES-256-GCM'
        || secret.attachment_uid !== attachmentUid) {
        throw new TypeError('Неизвестный или подменённый формат вложения');
    }
    const provider = requireCrypto();
    const keyBytes = decode64url(secret.key, 32);
    const ivBytes = decode64url(secret.iv, 12);
    const aad = secretContext(dialogUid, attachmentUid);
    try {
        const key = await provider.subtle.importKey('raw', keyBytes, 'AES-GCM', false, ['decrypt']);
        const decrypted = await provider.subtle.decrypt(
            { name: 'AES-GCM', iv: ivBytes, additionalData: aad, tagLength: 128 },
            key,
            bytes
        );
        return new Uint8Array(decrypted);
    } finally {
        keyBytes.fill(0);
    }
}
