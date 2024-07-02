{extends file="core/base.tpl"}
{block name=title}
    Админпанель
{/block}
{block name=body}
<style>
    {include file="admin-page/style.css"}
</style>
<section class="admin-tab">
    <div class="admin-tab__item active">Пользователи</div>
</section>

<section class="admin-content">
    <div class="custom-fields-form">
        <h3>Настройка кастомных полей профиля пользователя</h3>
        <form action="{route_path name='save_custom_fields'}" method="post">
            {csrf_token}
            <div id="custom-fields-container">
                {if $customFields}
                    {foreach $customFields as $field}
                        <div class="custom-field">
                            <input type="hidden" name="fields[{$field.id}][id]" value="{$field.id}">
                            
                            <label for="field_name_{$field.id}">Название:</label>
                            <input type="text" id="field_name_{$field.id}" name="fields[{$field.id}][field_name]" value="{$field.field_name}" required>
                            
                            <label for="field_label_{$field.id}">Метка:</label>
                            <input type="text" id="field_label_{$field.id}" name="fields[{$field.id}][field_label]" value="{$field.field_label}" required>
                            
                            <label for="field_type_{$field.id}">Тип:</label>
                            <select id="field_type_{$field.id}" name="fields[{$field.id}][field_type]" required>
                                <option value="text" {if $field.field_type == 'text'}selected{/if}>Text</option>
                                <option value="number" {if $field.field_type == 'number'}selected{/if}>Number</option>
                                <option value="date" {if $field.field_type == 'date'}selected{/if}>Date</option>
                                <!-- Add other field types as needed -->
                            </select>
                            
                            <label for="is_required_{$field.id}">Обязательное поле:</label>
                            <input type="checkbox" id="is_required_{$field.id}" name="fields[{$field.id}][is_required]" {if $field.is_required}checked{/if}>
                            
                            <a href="#" class="remove-field" onclick="removeField(this); return false;">Удалить</a>
                        </div>
                    {/foreach}
                {/if}
            </div>
            <div>
                <button type="button" id="add-field-btn">Добавить поле</button>
                <button type="submit">Сохранить</button>
            </div>
        </form>
    </div>
</section>
<script>
{literal}
    document.getElementById('add-field-btn').addEventListener('click', function() {
        var container = document.getElementById('custom-fields-container');
        var newFieldId = container.children.length + 1;

        var newFieldHtml =
            `<div class="custom-field">
                <input type="hidden" name="fields[new_${newFieldId}][id]" value="">
                
                <label for="field_name_new_${newFieldId}">Название:</label>
                <input type="text" id="field_name_new_${newFieldId}" name="fields[new_${newFieldId}][field_name]" required>
                
                <label for="field_label_new_${newFieldId}">Метка:</label>
                <input type="text" id="field_label_new_${newFieldId}" name="fields[new_${newFieldId}][field_label]" required>
                
                <label for="field_type_new_${newFieldId}">Тип:</label>
                <select id="field_type_new_${newFieldId}" name="fields[new_${newFieldId}][field_type]" required>
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <!-- Add other field types as needed -->
                </select>
                
                <label for="is_required_new_${newFieldId}">Обязательное поле:</label>
                <input type="checkbox" id="is_required_new_${newFieldId}" name="fields[new_${newFieldId}][is_required]">
                
                <a href="#" class="remove-field" onclick="removeField(this); return false;">Удалить</a>
            </div>`;
        
        container.insertAdjacentHTML('beforeend', newFieldHtml);
    });

    function removeField(element) {
        var field = element.closest('.custom-field');
        field.remove();
    }
{/literal}
</script>
{/block}