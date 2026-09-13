document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('custom-fields-container');
    const addButton = document.getElementById('add-field-btn');

    if (!container || !addButton) {
        return;
    }

    let counter = container.querySelectorAll('.custom-field').length;

    function createField() {
        counter += 1;
        const key = `new_${Date.now()}_${counter}`;
        const wrapper = document.createElement('div');
        wrapper.className = 'custom-field';
        wrapper.dataset.fieldKey = key;

        const nameId = `field_name_${key}`;
        const labelId = `field_label_${key}`;
        const typeId = `field_type_${key}`;
        const requiredId = `is_required_${key}`;

        wrapper.innerHTML = `
            <div class="custom-field__grid">
                <div class="custom-field__control">
                    <label for="${nameId}">Техническое имя</label>
                    <input type="text" id="${nameId}" name="fields[${key}][field_name]"
                           maxlength="50" pattern="[a-z][a-z0-9_]{0,49}" required
                           placeholder="department_code" autocomplete="off">
                </div>
                <div class="custom-field__control">
                    <label for="${labelId}">Метка</label>
                    <input type="text" id="${labelId}" name="fields[${key}][field_label]"
                           maxlength="100" required placeholder="Отдел" autocomplete="off">
                </div>
                <div class="custom-field__control">
                    <label for="${typeId}">Тип</label>
                    <select id="${typeId}" name="fields[${key}][field_type]" required>
                        <option value="text">Текст</option>
                        <option value="textarea">Многострочный текст</option>
                        <option value="number">Число</option>
                        <option value="date">Дата</option>
                        <option value="checkbox">Флажок</option>
                        <option value="select">Select (legacy, без вариантов)</option>
                    </select>
                </div>
                <label class="custom-field__required" for="${requiredId}">
                    <input type="checkbox" id="${requiredId}" name="fields[${key}][is_required]">
                    Обязательное
                </label>
                <button type="button" class="remove-field" aria-label="Удалить пользовательское поле">
                    <i class="fa fa-trash" aria-hidden="true"></i>
                    Удалить
                </button>
            </div>`;

        container.appendChild(wrapper);
        const nameInput = wrapper.querySelector(`#${CSS.escape(nameId)}`);
        if (nameInput) {
            nameInput.focus();
        }
    }

    addButton.addEventListener('click', createField);

    container.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-field');
        if (!button) {
            return;
        }
        const field = button.closest('.custom-field');
        if (field) {
            field.remove();
        }
    });

    document.querySelectorAll('[data-confirm-deactivate]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const username = form.dataset.confirmDeactivate || 'этого пользователя';
            if (!window.confirm(`Деактивировать ${username}? Данные сохранятся, но вход будет заблокирован.`)) {
                event.preventDefault();
            }
        });
    });
});
