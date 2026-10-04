import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../assets/js/update-web-runner.js', import.meta.url), 'utf8');
async function scenario(replies) {
    let calls = 0;
    const window = {
        setTimeout(callback, delay) { if (delay < 90000) queueMicrotask(callback); return 1; },
        clearTimeout() {},
    };
    class HTMLFormElement {}
    const context = vm.createContext({
        window, HTMLFormElement, AbortController,
        FormData: class {},
        fetch: async () => {
            const reply = replies[Math.min(calls++, replies.length - 1)];
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
console.log('[OK] Окончательные отказы, WAF, обрыв сети и ограниченные повторы');
