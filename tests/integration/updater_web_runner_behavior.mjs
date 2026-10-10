import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../assets/js/update-web-runner.js', import.meta.url), 'utf8');
async function scenario(replies) {
    let calls = 0;
    const window = {
        location: { href: 'https://notes.test/workspace/admin/updates', origin: 'https://notes.test' },
        setTimeout(callback, delay) { if (delay < 90000) queueMicrotask(callback); return 1; },
        clearTimeout() {},
    };
    class HTMLFormElement {}
    const context = vm.createContext({
        window, HTMLFormElement, AbortController, URL,
        FormData: class {},
        fetch: async (url) => {
            const reply = replies[Math.min(calls++, replies.length - 1)];
            if (reply.expectedUrl) assert.equal(url, reply.expectedUrl);
            if (reply instanceof Error) throw reply;
            return { ok: reply.status < 400, status: reply.status, json: async () => {
                if (reply.invalidJson) throw new Error('HTML вместо JSON');
                return reply.payload;
            } };
        },
    });
    vm.runInContext(source, context);
    const form = new HTMLFormElement();
    form.dataset = { updateStartUrl: '/start', updateStepUrl: '/step' };
    try { return { result: await window.wspace.updateWebRunner.run(form), calls }; }
    catch (error) { return { error, calls }; }
}
const start = { status: 200, payload: { success: true, result: {
    status: 'in_progress', transaction_id: 'transaction', continuation_token: 'secret',
} } };
const done = { status: 200, payload: { success: true, result: { status: 'committed' } } };
for (const status of [400, 403, 409, 422, 500]) {
    const result = await scenario([start, { status, payload: { retryable: false, message: 'Отказ' } }]);
    assert.equal(result.calls, 2, `Окончательный HTTP ${status} не должен повторяться`);
    assert.ok(result.error);
}
const waf = await scenario([start, { status: 403, invalidJson: true }]);
assert.equal(waf.calls, 2, 'HTML-отказ WAF не должен повторяться');
for (const transient of [
    { status: 409, payload: { retryable: true } },
    { status: 503, invalidJson: true },
    new TypeError('Обрыв сети'),
]) {
    const result = await scenario([start, transient, done]);
    assert.equal(result.calls, 3);
    assert.equal(result.result.status, 'committed');
}
const exhausted = await scenario([start, { status: 503, payload: { retryable: true } }]);
assert.equal(exhausted.calls, 10, 'После восьми повторов операция должна остановиться');
assert.ok(exhausted.error);
const externalStart = { status: 200, payload: { success: true, result: {
    ...start.payload.result, continuation_url: '/workspace/update-continuations/frozen.php',
} } };
const external = await scenario([externalStart, { ...done, expectedUrl: 'https://notes.test/workspace/update-continuations/frozen.php' }]);
assert.equal(external.result.status, 'committed');
const foreign = await scenario([{ status: 200, payload: { success: true, result: {
    ...start.payload.result, continuation_url: 'https://evil.test/collect',
} } }]);
assert.equal(foreign.calls, 1, 'Токен не должен уходить на чужой origin');
assert.ok(foreign.error);
const handedOff = await scenario([start, { status: 200, payload: externalStart.payload },
    { ...done, expectedUrl: 'https://notes.test/workspace/update-continuations/frozen.php' }]);
assert.equal(handedOff.result.status, 'committed', 'Контроллер 1.0.14 должен получить URL на первом шаге');
console.log('[OK] Окончательные отказы, WAF, обрыв сети и ограниченные повторы');

const diagnostic = await scenario([start, { status: 403, payload: { success: false, error: 'continuation_invalid', retryable: false } }]);
assert.match(diagnostic.error.message, /HTTP 403/);
assert.match(diagnostic.error.message, /continuation_invalid/);
assert.match(diagnostic.error.message, /transaction/);
