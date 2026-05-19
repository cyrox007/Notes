{extends file="core/base.tpl"}
{block name="title"}
    Настройки системы
{/block}
{block name="body"}
<section class="admin-tab">
    <div class="admin-tab__item active">Настройки</div>
</section>

<section class="admin-content">
    <div class="settings-form">
        <h3>Настройки системы</h3>
        
        {if $settings}
            {foreach $settings as $category => $categorySettings}
                <div class="settings-category">
                    <h4>{$category}</h4>
                    <form class="settings-category-form" data-category="{$category}">
                        {csrf_token}
                        <div class="settings-list">
                            {foreach $categorySettings as $setting}
                                {if $setting->is_editable}
                                    <div class="setting-item" data-setting-id="{$setting->id}">
                                        <div class="setting-info">
                                            <label for="setting_{$setting->id}">{$setting->description}</label>
                                            <small>Ключ: {$setting->setting_key}</small>
                                        </div>
                                        <div class="setting-control">
                                            {if $setting->setting_type == 'number'}
                                                <input type="number" 
                                                       id="setting_{$setting->id}" 
                                                       name="settings[{$setting->id}]" 
                                                       value="{$setting->setting_value}"
                                                       data-type="number">
                                                <span class="setting-hint">
                                                    {if $setting->setting_key == 'messenger_max_message_size' || $setting->setting_key == 'file_manager_max_file_size' || $setting->setting_key == 'file_manager_max_storage_per_user'}
                                                        (в байтах)
                                                    {/if}
                                                </span>
                                            {elseif $setting->setting_type == 'boolean'}
                                                <input type="checkbox" 
                                                       id="setting_{$setting->id}" 
                                                       name="settings[{$setting->id}]" 
                                                       {if $setting->setting_value == 1}checked{/if}
                                                       data-type="boolean">
                                            {elseif $setting->setting_type == 'json'}
                                                <textarea id="setting_{$setting->id}" 
                                                          name="settings[{$setting->id}]" 
                                                          rows="3"
                                                          data-type="json">{$setting->setting_value}</textarea>
                                            {else}
                                                <input type="text" 
                                                       id="setting_{$setting->id}" 
                                                       name="settings[{$setting->id}]" 
                                                       value="{$setting->setting_value}"
                                                       data-type="string">
                                            {/if}
                                        </div>
                                    </div>
                                {else}
                                    <div class="setting-item readonly">
                                        <div class="setting-info">
                                            <label>{$setting->description}</label>
                                            <small>Ключ: {$setting->setting_key}</small>
                                        </div>
                                        <div class="setting-control">
                                            <span class="readonly-value">{$setting->setting_value}</span>
                                        </div>
                                    </div>
                                {/if}
                            {/foreach}
                        </div>
                        <button type="submit" class="save-settings-btn">Сохранить настройки категории</button>
                    </form>
                </div>
            {/foreach}
        {else}
            <p>Настройки не найдены</p>
        {/if}
        
        <hr>
        
        <div class="storage-usage-section">
            <h4>Использование хранилища пользователями</h4>
            <button type="button" id="load-storage-usage" class="load-storage-btn">Загрузить данные</button>
            <div id="storage-usage-table" style="display:none; margin-top: 20px;">
                <table class="storage-usage-table">
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Email</th>
                            <th>Использовано</th>
                            <th>Лимит</th>
                            <th>% использования</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="storage-usage-body">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
{literal}
document.addEventListener('DOMContentLoaded', function() {
    // Обработка форм сохранения настроек
    const forms = document.querySelectorAll('.settings-category-form');
    
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(form);
            const settingsData = {};
            
            formData.forEach(function(value, key) {
                if (key.startsWith('settings[')) {
                    const settingId = key.match(/settings\[(\d+)\]/)[1];
                    const input = form.querySelector(`[name="${key}"]`);
                    const type = input.dataset.type;
                    
                    if (type === 'number') {
                        settingsData[settingId] = parseInt(value) || 0;
                    } else if (type === 'boolean') {
                        settingsData[settingId] = input.checked ? 1 : 0;
                    } else if (type === 'json') {
                        try {
                            JSON.parse(value);
                            settingsData[settingId] = value;
                        } catch (e) {
                            alert('Неверный формат JSON');
                            return;
                        }
                    } else {
                        settingsData[settingId] = value;
                    }
                }
            });
            
            // Отправляем данные на сервер
            const saveFormData = new FormData();
            saveFormData.append('_token', formData.get('_token'));
            
            for (const [key, value] of Object.entries(settingsData)) {
                saveFormData.append(`settings[${key}]`, value);
            }
            
            fetch('/admin/settings/save', {
                method: 'POST',
                body: saveFormData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                } else {
                    alert('Ошибка: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ошибка при сохранении');
            });
        });
    });
    
    // Загрузка данных об использовании хранилища
    const loadStorageBtn = document.getElementById('load-storage-usage');
    const storageTable = document.getElementById('storage-usage-table');
    const storageBody = document.getElementById('storage-usage-body');
    
    if (loadStorageBtn) {
        loadStorageBtn.addEventListener('click', function() {
            loadStorageBtn.disabled = true;
            loadStorageBtn.textContent = 'Загрузка...';
            
            fetch('/admin/settings/storage-usage')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    storageBody.innerHTML = '';
                    
                    data.data.forEach(function(user) {
                        const row = document.createElement('tr');
                        
                        const percentClass = user.percent_used > 90 ? 'over-90' : (user.percent_used > 75 ? 'over-75' : '');
                        
                        row.innerHTML = `
                            <td>${user.firstname} ${user.lastname} (${user.username})</td>
                            <td>${user.email}</td>
                            <td>${user.used_storage_formatted}</td>
                            <td>${user.max_storage_formatted}</td>
                            <td class="${percentClass}">${user.percent_used}%</td>
                            <td>
                                <button type="button" class="recalculate-btn" data-user-id="${user.user_id}">Пересчитать</button>
                            </td>
                        `;
                        
                        storageBody.appendChild(row);
                    });
                    
                    storageTable.style.display = 'table';
                    
                    // Добавляем обработчики для кнопок пересчета
                    document.querySelectorAll('.recalculate-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            const userId = this.dataset.userId;
                            recalculateStorage(userId);
                        });
                    });
                } else {
                    alert('Ошибка: ' + data.message);
                }
                
                loadStorageBtn.disabled = false;
                loadStorageBtn.textContent = 'Загрузить данные';
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ошибка при загрузке данных');
                loadStorageBtn.disabled = false;
                loadStorageBtn.textContent = 'Загрузить данные';
            });
        });
    }
    
    function recalculateStorage(userId) {
        if (!confirm('Пересчитать использование хранилища для этого пользователя?')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('user_id', userId);
        
        fetch('/admin/settings/recalculate-storage', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Квота пересчитана. Использовано: ' + data.used_storage_formatted);
                // Перезагружаем таблицу
                document.getElementById('load-storage-usage').click();
            } else {
                alert('Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при пересчете');
        });
    }
});
{/literal}
</script>

<style>
.settings-category {
    margin-bottom: 30px;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.settings-category h4 {
    margin-top: 0;
    margin-bottom: 20px;
    color: #333;
    text-transform: capitalize;
}

.settings-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.setting-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
}

.setting-item.readonly {
    opacity: 0.7;
}

.setting-info {
    flex: 1;
}

.setting-info label {
    display: block;
    font-weight: bold;
    margin-bottom: 5px;
}

.setting-info small {
    color: #666;
    font-size: 0.85em;
}

.setting-control {
    flex: 0 0 300px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.setting-control input[type="number"],
.setting-control input[type="text"],
.setting-control textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

.setting-hint {
    font-size: 0.85em;
    color: #666;
}

.save-settings-btn {
    margin-top: 15px;
    padding: 10px 20px;
    background: #4CAF50;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.save-settings-btn:hover {
    background: #45a049;
}

.storage-usage-section {
    margin-top: 30px;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.load-storage-btn {
    padding: 10px 20px;
    background: #2196F3;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.load-storage-btn:hover {
    background: #1976D2;
}

.storage-usage-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.storage-usage-table th,
.storage-usage-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.storage-usage-table th {
    background: #f5f5f5;
    font-weight: bold;
}

.storage-usage-table .over-90 {
    color: #f44336;
    font-weight: bold;
}

.storage-usage-table .over-75 {
    color: #ff9800;
    font-weight: bold;
}

.recalculate-btn {
    padding: 5px 10px;
    background: #FF9800;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 0.85em;
}

.recalculate-btn:hover {
    background: #F57C00;
}
</style>
{/block}
