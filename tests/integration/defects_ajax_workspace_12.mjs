import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../../', import.meta.url);
const load = (file) => readFileSync(new URL(file, root), 'utf8');
const page = load('modules/defects/views/index.php');
const code = load('modules/defects/assets/defects.js');
const styles = load('modules/defects/assets/defects.css');

const mustContain = (text, phrase, description) => {
    assert.ok(text.includes(phrase), description);
};

for (const [phrase, description] of [
    ['data-defects-create-overlay', 'Форма создания должна открываться внутри страницы'],
    ['data-defects-detail-overlay', 'Карточка должна открываться внутри страницы'],
    ['data-defects-detail-fields', 'Карточке требуется область полей'],
    ['data-defects-history-list', 'История должна быть доступна из карточки'],
    ['data-defects-close', 'Закрытие требует отдельной формы'],
    ['data-defects-loading', 'У реестра должно быть состояние загрузки'],
    ['aria-modal="true"', 'Панели должны объявляться модальными'],
    ['data-defects-services', 'Назначение служб должно быть доступным без Ctrl'],
    ["moduleAsset('defects', 'defects.css')", 'Модуль подключает собственные адаптивные стили'],
]) mustContain(page, phrase, description);

for (const [phrase, description] of [
    ["window.wspace.page.register('modules/defects/assets/defects.js'", 'Нужна инициализация внутри жизненного цикла страниц ядра'],
    ['pageScope.fetch(', 'HTTP-запросы должны отменяться с жизненным циклом страницы'],
    ["pageScope.listen(document, 'keydown'", 'Обработка клавиатуры должна удаляться при переходах'],
    ['newCommand()', 'Идентификаторы команд не зависят от защищённого контекста браузера'],
    ['loadHistory(', 'История должна подгружаться по мере необходимости'],
    ['state.detailSequence', 'Нужна защита от запоздавших AJAX-ответов карточки'],
    ['state.sequence', 'Нужна защита от запоздавших AJAX-ответов реестра'],
    ['document.createElement', 'Пользовательские данные нужно выводить как текстовые узлы'],
]) mustContain(code, phrase, description);

assert.ok(!code.includes('window.prompt('), 'Закрытие не должно использовать системный prompt');
assert.ok(!code.includes('innerHTML'), 'Запрещена небезопасная вставка серверных данных');
assert.ok(!code.includes('window.location.reload'), 'Обновление данных должно выполняться без перезагрузки');
assert.ok(!code.includes('crypto.randomUUID'), 'Идентификаторы команд не должны зависеть от HTTPS');
assert.ok(styles.includes('prefers-reduced-motion'), 'Нужно учитывать пользователей с ограничением анимации');
assert.ok(styles.includes('@media(max-width:520px)'), 'Интерфейс должен учитывать узкие экраны');
assert.ok(styles.includes('var(--ui-surface'), 'Цвета должны использовать палитру ядра');

console.log('Интерфейс Дефектов: AJAX, формы, история, мобильная адаптация и безопасность проверены');
