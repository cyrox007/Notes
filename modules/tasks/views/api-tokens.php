<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$tokensList = $view->route('tasks_api_token_list');
$tokensCreate = $view->route('tasks_api_token_create');
$tokensRevoke = $view->route('tasks_api_token_revoke', ['tokenId' => 1]);
$revokeRoot = preg_replace('~/1/revoke/?$~', '/', $tokensRevoke);
$revokeRoot = is_string($revokeRoot) ? rtrim($revokeRoot, '/') : '';
ob_start();
?>
<main class="tasks-keys" data-tasks-api-keys
      data-list-url="<?= $view->e($tokensList) ?>"
      data-create-url="<?= $view->e($tokensCreate) ?>"
      data-revoke-root="<?= $view->e($revokeRoot) ?>">
    <header class="tasks-keys__heading">
        <div>
            <a href="<?= $view->e($view->route('tasks')) ?>" class="tasks-keys__back">← Вернуться к задачам</a>
            <h1>Ключи доступа к задачам</h1>
            <p>Подключайте свои приложения без передачи пароля. Каждый ключ можно отозвать отдельно.</p>
        </div>
    </header>

    <div class="tasks-keys__grid">
        <section class="tasks-keys__panel" aria-labelledby="tasks-keys-create-label">
            <h2 id="tasks-keys-create-label">Новый ключ</h2>
            <form data-token-create>
                <?= $view->csrfInput() ?>
                <label>Название подключения
                    <input type="text" name="name" maxlength="100" required autocomplete="off"
                           placeholder="Например, личный телефон">
                </label>
                <label>Разрешения
                    <select name="scope">
                        <option value="tasks.read">Только просмотр задач</option>
                        <option value="tasks.write">Просмотр, создание и изменение</option>
                    </select>
                </label>
                <label>Срок действия
                    <select name="days">
                        <option value="7">7 дней</option>
                        <option value="30" selected>30 дней</option>
                        <option value="90">90 дней</option>
                    </select>
                </label>
                <p class="tasks-keys__hint">Ключ не может открыть задачи других пользователей. Изменять права существующего ключа нельзя.</p>
                <button type="submit" class="btn-primary">Создать ключ</button>
            </form>
            <section data-token-secret class="tasks-keys__secret" hidden aria-label="Созданный ключ">
                <h3>Сохраните ключ сейчас</h3>
                <p>После закрытия он больше не отобразится. Не пересылайте ключ посторонним.</p>
                <div class="tasks-keys__copy-row">
                    <input data-token-secret-value type="text" readonly autocomplete="off" spellcheck="false"
                           aria-label="Секрет созданного ключа">
                    <button type="button" class="btn-secondary" data-token-copy>Копировать</button>
                </div>
                <button type="button" class="btn-secondary" data-token-hide>Скрыть секрет</button>
            </section>
        </section>

        <section class="tasks-keys__panel" aria-labelledby="tasks-keys-list-label">
            <div class="tasks-keys__list-heading">
                <h2 id="tasks-keys-list-label">Мои подключения</h2>
                <button type="button" class="btn-secondary" data-token-refresh>Обновить</button>
            </div>
            <p data-token-status role="status" aria-live="polite">Загружаем ключи…</p>
            <div data-token-list class="tasks-keys__list" aria-live="off"></div>
        </section>
    </div>
</main>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Ключи доступа к задачам',
    'sitename' => $sitename ?? 'Notes',
    'version' => $version ?? '',
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $user ?? null,
    'workspaceAccess' => $workspaceAccess ?? [],
    'module_styles' => [
        $view->moduleAsset('tasks', 'api-tokens.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('tasks', 'api-tokens.js'),
    ],
], $content);
