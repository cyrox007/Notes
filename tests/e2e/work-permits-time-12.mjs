import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

// Исполняем тот же клиентский расчёт, что загружается в настоящем интерфейсе.
const source = readFileSync('modules/work-permits/assets/work-permits-time.js', 'utf8');
const sandbox = { window: {}, Date, Intl, Map, Set, Number, String, Error, Option: class {} };
runInNewContext(source, sandbox, { filename: 'work-permits-time.js' });
const time = sandbox.window.WorkPermitTime;
const utc = (value, zone) => [...time.candidates(value, zone)]
    .map(instant => new Date(instant).toISOString().slice(0, 19).replace('T', ' '));

// Часовой пояс компьютера не используется даже при вводе через datetime-local.
assert.deepEqual(utc('2026-10-30T08:00', 'Europe/Moscow'), ['2026-10-30 05:00:00']);
assert.deepEqual(utc('2026-10-30T08:00', 'UTC'), ['2026-10-30 08:00:00']);

// В марте местного 02:30 нет, а в октябре он соответствует двум разным моментам.
assert.deepEqual(utc('2026-03-29T02:30', 'Europe/Amsterdam'), []);
assert.deepEqual(utc('2026-10-25T02:30', 'Europe/Amsterdam'),
    ['2026-10-25 00:30:00', '2026-10-25 01:30:00']);
assert.deepEqual(utc('2026-03-08T02:30', 'America/Los_Angeles'), []);
assert.deepEqual(utc('2026-11-01T01:30', 'America/Los_Angeles'),
    ['2026-11-01 08:30:00', '2026-11-01 09:30:00']);

// Для старых документов применяется UTC; выданный документ не меняет пояс после настройки организации.
const legacy = { lifecycle: { issuance: { time_zone: 'UTC' } }, organization_time_zone: 'Asia/Yekaterinburg' };
assert.equal(time.zoneFor(legacy), 'UTC');
const issued = { lifecycle: { issuance: { time_zone: 'Europe/Moscow' } },
    organization_time_zone: 'Europe/Amsterdam' };
assert.equal(time.zoneFor(issued), 'Europe/Moscow');
assert.equal(time.display('2026-10-30 05:00:00', issued),
    '2026-10-30 08:00 (Europe/Moscow, UTC+03:00)');
assert.throws(() => time.candidates('2026-02-30T12:00', 'UTC'), /Некорректная/);
assert.throws(() => time.zoneFor({ organization_time_zone: 'Wrong/Zone' }), /Неизвестный/);

console.log('Наряды 1.2: местное время, UTC, перевод часов и снимок пояса — успешно');
