// Проверяем фактическое состояние формы без внешней сети и почтовых паролей.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(
    new URL('../../modules/admin/assets/notification-settings.js', import.meta.url), 'utf8'
);

async function checkDelivery(state, expectedIndication, guidance) {
    const feedback = { hidden: true, textContent: '', dataset: {} };
    const button = { disabled: false };
    const password = { value: 'одноразовое-поле' };
    const form = {
        action: '/admin/notifications/test',
        attributes: {},
        querySelector(selector) {
            if (selector === '[type="submit"]') return button;
            if (selector === '[name="current_password"]') return password;
            return null;
        },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    const page = {
        querySelector(selector) {
            return selector === '[data-notification-feedback]' ? feedback : null;
        },
        querySelectorAll(selector) {
            return selector === '[data-notification-ajax-form]' ? [form] : [];
        },
    };
    let submit;
    const scope = {
        onInit(callback) { callback(); },
        listen(target, name, callback) {
            assert.equal(target, form);
            assert.equal(name, 'submit');
            submit = callback;
        },
        async fetch() {
            return {
                ok: true,
                async json() {
                    return {
                        status: 'ok',
                        message: 'Итог пробной доставки',
                        job_id: 42,
                        state,
                        last_result_code: state === 'sent' ? 'smtp_sent' : 'smtp_connect_failed',
                        guidance,
                    };
                },
            };
        },
    };
    vm.runInNewContext(source, {
        window: { wspace: { page: { register(_name, initializer) { initializer(scope); } } } },
        document: { querySelector: () => page },
        FormData: class {},
        Error,
    }, { filename: 'notification-settings.js' });

    assert.equal(typeof submit, 'function', 'должен регистрироваться обработчик формы');
    let prevented = false;
    await submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(button.disabled, false, 'кнопка должна снова стать доступной');
    assert.equal(form.attributes['aria-busy'], undefined, 'состояние загрузки должно сниматься');
    assert.equal(password.value, '', 'пароль администратора не должен оставаться в поле');
    assert.equal(feedback.dataset.state, expectedIndication);
    assert(feedback.textContent.includes(guidance), 'пользователь должен видеть рекомендацию');
    assert.equal(feedback.textContent.includes('<script>'), false);
}

await checkDelivery('sent', 'success', 'Сервер принял письмо; получение не подтверждено.');
await checkDelivery('retry', 'warning', 'Проверьте доступность сервера.');
await checkDelivery('pending', 'warning', 'Запуск обработчика ещё ожидается.');
await checkDelivery('failed', 'error', 'Проверьте настройки SMTP и соединение.');

console.log('Проверена правильная индикация SMTP: принято, ожидание, повтор и отказ');
