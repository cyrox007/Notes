import { notesMatrixUserId } from './matrix-device-bootstrap.mjs';

const FORMAT = 'notes-e2ee-room-key-archive-v1';
const MAX_FILE_SIZE = 12 * 1024 * 1024;
const MAX_KEYS = 10000;
const ROUNDS = 250000;
const ROOM_ID = /^![0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:notes\.local$/i;
const ENCRYPTED_HEADER = '-----BEGIN MEGOLM SESSION DATA-----';

/**
 * Секрет для архивного файла независим от фразы разблокировки устройства.
 * Архив хранится только в выбранном пользователем файле, а не на сервере.
 */
function checkPassphrase(passphrase) {
    if (typeof passphrase !== 'string' || passphrase.length < 16
        || passphrase.length > 1024 || passphrase.trim() !== passphrase) {
        throw new TypeError('Парольная фраза архива: от 16 до 1024 символов без пробелов по краям');
    }
    return passphrase;
}

function parseKeys(raw) {
    if (typeof raw !== 'string' || raw.length > MAX_FILE_SIZE) {
        throw new Error('Слишком большой экспорт комнатных ключей');
    }
    let keys;
    try {
        keys = JSON.parse(raw);
    } catch {
        throw new Error('Криптодвижок вернул повреждённый список ключей');
    }
    if (!Array.isArray(keys) || keys.length < 1 || keys.length > MAX_KEYS) {
        throw new Error('Нет ключей истории для переноса или превышено ограничение');
    }
    for (const key of keys) {
        if (!key || typeof key !== 'object' || Array.isArray(key)
            || !ROOM_ID.test(key.room_id)
            || key.algorithm !== 'm.megolm.v1.aes-sha2'
            || typeof key.session_key !== 'string'
            || key.session_key.length < 16
            || typeof key.session_id !== 'string'
            || typeof key.sender_key !== 'string'
            || typeof key.sender_claimed_keys?.ed25519 !== 'string') {
            throw new Error('В архиве найдены чужие комнаты или неправильные сеансы');
        }
    }
    return keys.length;
}

/**
 * Получаем ключи лишь из локального Matrix. Шифрование Matrix выполняется
 * до того, как архив может быть скачан. Открытый JSON нигде не сохраняется.
 */
export async function createEncryptedRoomKeyArchive({sdk,machine,userUid,passphrase}) {
    notesMatrixUserId(userUid);
    const secret = checkPassphrase(passphrase);
    if (!sdk?.OlmMachine?.encryptExportedRoomKeys
        || typeof machine?.exportRoomKeys !== 'function') {
        throw new Error('Криптодвижок не умеет создавать защищённый архив');
    }
    const raw = await machine.exportRoomKeys(() => true);
    const count = parseKeys(raw);
    const encrypted = sdk.OlmMachine.encryptExportedRoomKeys(raw,secret,ROUNDS);
    if (typeof encrypted !== 'string' || !encrypted.startsWith(ENCRYPTED_HEADER)
        || encrypted.length > MAX_FILE_SIZE || encrypted.includes('"session_key"')) {
        throw new Error('Криптодвижок не создал допустимый защищённый архив');
    }
    const archive = JSON.stringify({
        format:FORMAT,
        user_uid:userUid.toLowerCase(),
        created_at:new Date().toISOString(),
        key_count:count,
        encrypted,
    });
    if (archive.length > MAX_FILE_SIZE) {
        throw new Error('Архив превышает максимально допустимый размер');
    }
    return {archive,keyCount:count};
}

function readArchive(archive,userUid) {
    notesMatrixUserId(userUid);
    if (typeof archive !== 'string' || archive.length > MAX_FILE_SIZE) {
        throw new Error('Защищённый файл отсутствует или превышает лимит 12 МБ');
    }
    let envelope;
    try {
        envelope = JSON.parse(archive);
    } catch {
        throw new Error('Файл не является архивом ключей Notes');
    }
    if (!envelope || typeof envelope !== 'object' || Array.isArray(envelope)
        || envelope.format !== FORMAT
        || envelope.user_uid !== userUid.toLowerCase()
        || !Number.isSafeInteger(envelope.key_count)
        || envelope.key_count < 1 || envelope.key_count > MAX_KEYS
        || typeof envelope.encrypted !== 'string'
        || !envelope.encrypted.startsWith(ENCRYPTED_HEADER)
        || envelope.encrypted.length > MAX_FILE_SIZE) {
        throw new Error('Чужая учётная запись или повреждённый архив E2EE');
    }
    return envelope;
}

/**
 * Ни архив, ни пароль, ни расшифрованные комнатные ключи не передаются
 * серверу. Новое устройство сохраняет импорт в собственном OlmMachine.
 */
export async function restoreEncryptedRoomKeyArchive({
    sdk,machine,userUid,passphrase,archive,
}) {
    const envelope = readArchive(archive,userUid);
    const secret = checkPassphrase(passphrase);
    if (!sdk?.OlmMachine?.decryptExportedRoomKeys
        || typeof machine?.importExportedRoomKeys !== 'function') {
        throw new Error('Локальный криптодвижок не поддерживает восстановление ключей');
    }
    let raw;
    try {
        raw = sdk.OlmMachine.decryptExportedRoomKeys(envelope.encrypted,secret);
    } catch {
        throw new Error('Пароль неверен или архив повреждён. Ключи не импортированы.');
    }
    const count = parseKeys(raw);
    if (count !== envelope.key_count) {
        throw new Error('Количество ключей не соответствует данным архива');
    }
    let importedProgress = 0;
    const result = await machine.importExportedRoomKeys(raw,(progress,total)=>{
        importedProgress = Number(progress);
        if (Number(total) !== count) {
            throw new Error('Криптодвижок обнаружил несогласованный набор ключей');
        }
    });
    let imported;
    try {
        imported = Number(result?.importedCount ?? result?.imported_count ?? importedProgress);
        if (!Number.isSafeInteger(imported) || imported < 0 || imported > count) {
            throw new Error('Не удалось подтвердить сохранение импортированных ключей');
        }
    } finally {
        // Объект Rust обязан освободить ссылки на внутреннее состояние до
        // закрытия OlmMachine; иначе повторный импорт может удержать заимствование.
        result?.free?.();
    }
    // Передаём в локальное хранилище только открытые метаданные импортов.
    // Ни session_key, ни пароль, ни исходный JSON не покидают криптодвижок.
    const sessions=JSON.parse(raw).map(item=>({
        room_id:item.room_id,
        session_id:item.session_id,
        sender_key:item.sender_key,
        sender_claimed_keys:{ed25519:item.sender_claimed_keys.ed25519}
    }));
    return {totalCount:count,importedCount:imported,sessions};
}
