import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

class Element {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.listeners = {};
        this.value = '';
        this.hidden = false;
        this.disabled = false;
        this.textContent = '';
        this.classList = { toggle() {} };
        this.dataset = {};
    }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = [...nodes]; }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    querySelector(selector) {
        if (selector === '[name="csrf_token"]') return csrf;
        if (selector === '[type="submit"]') return submit;
        return null;
    }
    reset() {}
    focus() {}
    select() {}
}

const csrf = new Element('input');
csrf.value = 'test-csrf';
const submit = new Element('button');
const form = new Element('form');
const status = new Element('p');
const list = new Element('div');
const secretPanel = new Element('section');
const secretValue = new Element('input');
const refresh = new Element('button');
const copy = new Element('button');
const hide = new Element('button');
secretPanel.hidden = true;

const page = new Element('main');
page.dataset = {
    listUrl: '/tasks/api-tokens/',
    createUrl: '/tasks/api-tokens/',
    revokeRoot: '/tasks/api-tokens'
};
const refs = {
    '[data-token-create]': form,
    '[data-token-status]': status,
    '[data-token-list]': list,
    '[data-token-secret]': secretPanel,
    '[data-token-secret-value]': secretValue,
    '[data-token-refresh]': refresh,
    '[data-token-copy]': copy,
    '[data-token-hide]': hide
};
page.querySelector = selector => refs[selector] || null;

const requests = [];
const active = {
    id: 4, name: '<script>не HTML</script>', scope: 'tasks.read',
    expires_at: '2030-01-01 00:00:00', revoked_at: null
};
const revoked = {
    id: 2, name: 'Старое подключение', scope: 'tasks.read',
    expires_at: '2025-01-01 00:00:00', revoked_at: '2025-01-01 00:00:00'
};
let secretCopied = '';
let confirmed = false;

const context = {
    URLSearchParams,
    Date,
    Number,
    String,
    Array,
    document: {
        querySelector: selector => selector === '[data-tasks-api-keys]' ? page : null,
        createElement: tag => new Element(tag)
    },
    window: {
        addEventListener() {},
        confirm() { confirmed = true; return true; }
    },
    navigator: { clipboard: { async writeText(value) { secretCopied = value; } } },
    FormData: class {
        constructor(owner) { assert.equal(owner, form); }
    },
    async fetch(url, options = {}) {
        requests.push({ url, method: options.method || 'GET', options });
        let data = {};
        if (options.method === 'POST' && url.endsWith('/revoke')) {
            data = { revoked: true };
        } else if (options.method === 'POST') {
            data = { created: { token: 'ntk1_' + 'a'.repeat(64) } };
        } else {
            data = { items: [revoked, active] };
        }
        return {
            ok: true,
            headers: { get() { return 'application/json'; } },
            async json() { return data; }
        };
    }
};
const path = new URL('../../modules/tasks/assets/api-tokens.js', import.meta.url);
runInNewContext(readFileSync(path, 'utf8'), context);

async function flush() {
    for (let attempt = 0; attempt < 4; attempt++) {
        await new Promise(resolve => setImmediate(resolve));
    }
}
await flush();
assert.equal(requests[0].method, 'GET');
assert.equal(list.children[0].textContent, 'Действующие');
assert.equal(list.children[2].textContent, 'История');
assert.equal(list.children[1].children[0].children[0].textContent, active.name);

await form.listeners.submit({ preventDefault() {} });
await flush();
assert.equal(secretPanel.hidden, false);
assert.ok(secretValue.value.startsWith('ntk1_'));
assert.equal(requests.find(item => item.method === 'POST').url, '/tasks/api-tokens/');

await copy.listeners.click();
assert.equal(secretCopied, secretValue.value);
hide.listeners.click();
assert.equal(secretValue.value, '');
assert.equal(secretPanel.hidden, true);

const revoke = list.children[1].children.find(child => child.tag === 'button');
await revoke.listeners.click();
await flush();
assert.equal(confirmed, true);
const call = requests.find(item => item.url.endsWith('/4/revoke'));
assert.equal(call.method, 'POST');
assert.equal(call.options.body.get('csrf_token'), 'test-csrf');

console.log('Страница ключей Tasks: загрузка, безопасный текст, выпуск, копирование и отзыв проверены');
