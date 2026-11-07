import { notesMatrixUserId } from './matrix-device-bootstrap.mjs';

const DEVICE_PATTERN = /^[A-Za-z0-9_-]{8,64}$/;

/**
 * Каждая вкладка обязана получить исключительный доступ к хранилищу
 * одного криптографического устройства до открытия OlmMachine.
 * Нельзя заменять блокировку таймером, localStorage или молчаливым ожиданием:
 * два писателя способны повредить ключевое состояние.
 */
export async function acquireDeviceSession(
    userUid, deviceId, locks = globalThis.navigator?.locks
) {
    const user = notesMatrixUserId(userUid);
    if (typeof deviceId !== 'string' || !DEVICE_PATTERN.test(deviceId)) {
        throw new TypeError('Некорректный идентификатор защищённого устройства');
    }
    if (!locks || typeof locks.request !== 'function') {
        throw new Error('Браузер не поддерживает безопасную межвкладочную блокировку E2EE');
    }

    // Имя относится только к текущему origin и не содержит парольных фраз.
    const name = 'notes:e2ee:machine:v1:' + user + ':' + deviceId;
    let releaseHeld;
    const held = new Promise(resolve => { releaseHeld = resolve; });
    let settled = false;
    const acquired = new Promise((resolve, reject) => {
        Promise.resolve().then(() => locks.request(
            name, { mode: 'exclusive', ifAvailable: true }, async lock => {
                if (!lock) {
                    settled = true;
                    reject(new Error(
                        'Это E2EE-устройство уже открыто в другой вкладке. '
                        + 'Закройте защищённую переписку в предыдущей вкладке.'
                    ));
                    return;
                }
                let released = false;
                settled = true;
                resolve(Object.freeze({
                    release() {
                        if (released) return;
                        released = true;
                        releaseHeld();
                    }
                }));
                await held;
            }
        )).catch(error => {
            if (!settled) {
                settled = true;
                reject(error);
            }
        });
    });
    return acquired;
}
