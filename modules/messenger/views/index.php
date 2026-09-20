<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$contactRows = isset($contacts) && is_array($contacts) ? $contacts : [];
$workspaceActions = isset($workspace_actions) && is_array($workspace_actions) ? $workspace_actions : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$cspNonce = \Core\SecurityHeaders::nonce();

$readModuleAsset = static function (string $file): string {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $source = file_get_contents($path);
    return is_string($source) ? $source : '';
};
$cssFiles = ['style.css', 'media.css', 'forwarding.css', 'reactions.css', 'voice.css', 'group.css', 'search.css', 'workspace-actions.css', 'storage-files.css', 'visual-refresh.css'];
$jsFiles = ['protocol-origin.js', 'script.js', 'activity.js', 'dialog-actions.js', 'receipts.js', 'media.js', 'forwarding.js', 'reactions.js', 'voice.js', 'group.js', 'search.js', 'workspace-actions.js', 'storage-files.js'];
$literalOpen = '{' . 'literal}';
$literalClose = '{/' . 'literal}';

ob_start();
?>
<style nonce="<?= $view->e($cspNonce) ?>">
<?php foreach ($cssFiles as $cssFile): ?><?= $readModuleAsset($cssFile) ?>
<?php endforeach; ?>
.messenger-chat__actions { display:flex; gap:.35rem; margin-left:auto; }
.messenger-chat__actions .messenger-icon-button[data-active="true"] { color:var(--msg-accent); background:var(--msg-accent-soft); }
.messenger-folder-tabs { display:flex; gap:6px; padding:0 12px 10px; }
.messenger-folder-tab { flex:1; min-width:0; display:flex; align-items:center; justify-content:center; gap:6px; height:34px; padding:0 10px; border:0; border-radius:9px; background:transparent; color:var(--msg-muted); cursor:pointer; font:inherit; font-size:13px; }
.messenger-folder-tab:hover { background:var(--msg-accent-soft); }
.messenger-folder-tab[aria-selected="true"] { color:var(--msg-accent); background:var(--msg-accent-soft); font-weight:600; }
.messenger-folder-tab__count { min-width:18px; height:18px; display:inline-grid; place-items:center; padding:0 5px; border-radius:999px; background:rgba(127,127,127,.13); font-size:11px; }
.messenger-dialog-state-icons { display:inline-flex; align-items:center; gap:5px; flex:0 0 auto; color:var(--msg-muted); font-size:11px; }
.messenger-message__status[data-state="sent"] { color:var(--msg-muted); }
.messenger-message__status[data-state="delivered"] { color:var(--msg-muted); }
.messenger-message__status[data-state="read"] { color:var(--msg-accent); }
</style>

<section
    class="messenger-app"
    id="messenger-app"
    data-user-uid="<?= $view->e($currentUser['uid'] ?? '') ?>"
    data-user-name="<?= $view->e(trim((string) ($currentUser['firstname'] ?? '') . ' ' . (string) ($currentUser['lastname'] ?? ''))) ?>"
    data-can-create-note="<?= !empty($workspaceActions['notes']) ? '1' : '0' ?>"
    data-can-create-task="<?= !empty($workspaceActions['tasks']) ? '1' : '0' ?>"
    data-can-use-files="<?= !empty($workspaceActions['files']) ? '1' : '0' ?>"
>
    <aside class="messenger-list" aria-label="Список диалогов">
        <header class="messenger-list__header">
            <div>
                <h1 class="messenger-list__title">Сообщения</h1>
                <div class="messenger-connection" id="messenger-connection" data-state="connecting"><span class="messenger-connection__dot" aria-hidden="true"></span><span id="messenger-connection-text">Подключение…</span></div>
            </div>
            <button class="messenger-icon-button" id="new-chat-button" type="button" title="Новый чат" aria-label="Новый чат"><i class="fa fa-pencil-square-o" aria-hidden="true"></i></button>
        </header>

        <div class="messenger-search"><i class="fa fa-search" aria-hidden="true"></i><input id="dialog-search" type="search" autocomplete="off" placeholder="Поиск чатов"></div>

        <div class="messenger-folder-tabs" role="tablist" aria-label="Папки чатов">
            <button class="messenger-folder-tab" id="chat-folder-active" type="button" role="tab" aria-selected="true"><i class="fa fa-comments-o" aria-hidden="true"></i><span>Чаты</span><span class="messenger-folder-tab__count" id="chat-folder-active-count">0</span></button>
            <button class="messenger-folder-tab" id="chat-folder-archive" type="button" role="tab" aria-selected="false"><i class="fa fa-archive" aria-hidden="true"></i><span>Архив</span><span class="messenger-folder-tab__count" id="chat-folder-archive-count">0</span></button>
        </div>

        <div class="messenger-dialogs" id="dialog-list" aria-live="polite"></div>
        <div class="messenger-list__empty" id="dialog-list-empty" hidden><i class="fa fa-comments-o" aria-hidden="true"></i><strong id="dialog-list-empty-title">Диалогов пока нет</strong><span id="dialog-list-empty-text">Создайте первый чат с коллегой.</span></div>
    </aside>

    <main class="messenger-chat" id="messenger-chat">
        <div class="messenger-chat__empty" id="chat-empty-state"><div class="messenger-chat__empty-icon"><i class="fa fa-paper-plane-o" aria-hidden="true"></i></div><strong>Выберите диалог</strong><span>Или создайте новый чат — переписка появится здесь.</span></div>

        <div class="messenger-chat__active" id="chat-active" hidden data-dragging="false">
            <header class="messenger-chat__header">
                <button class="messenger-icon-button messenger-chat__back" id="chat-back-button" type="button" aria-label="Назад к диалогам"><i class="fa fa-arrow-left" aria-hidden="true"></i></button>
                <div class="messenger-avatar" id="chat-avatar" aria-hidden="true">?</div>
                <div class="messenger-chat__identity"><strong id="chat-title">Диалог</strong><span id="chat-subtitle">&nbsp;</span></div>
                <div class="messenger-chat__actions" aria-label="Действия с чатом">
                    <button class="messenger-icon-button" id="chat-group-button" type="button" hidden title="Информация о группе" aria-label="Информация о группе"><i class="fa fa-users" aria-hidden="true"></i></button>
                    <button class="messenger-icon-button" id="chat-pin-button" type="button" title="Закрепить чат" aria-label="Закрепить чат"><i class="fa fa-thumb-tack" aria-hidden="true"></i></button>
                    <button class="messenger-icon-button" id="chat-mute-button" type="button" title="Выключить уведомления на час" aria-label="Выключить уведомления на час"><i class="fa fa-bell-slash-o" aria-hidden="true"></i></button>
                    <button class="messenger-icon-button" id="chat-archive-button" type="button" title="Архивировать чат" aria-label="Архивировать чат"><i class="fa fa-archive" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="messenger-history" id="message-scroll"><button class="messenger-history__older" id="load-older-button" type="button" hidden>Показать более ранние сообщения</button><div class="messenger-messages" id="message-list" aria-live="polite"></div></div>
            <div class="messenger-typing" id="typing-indicator" hidden><span></span><span></span><span></span><em id="typing-text">печатает…</em></div>
            <div class="messenger-compose-context" id="compose-context" hidden><div><strong id="compose-context-title"></strong><span id="compose-context-text"></span></div><button class="messenger-icon-button" id="compose-context-close" type="button" aria-label="Отменить"><i class="fa fa-times" aria-hidden="true"></i></button></div>
            <div class="messenger-upload-status" id="messenger-upload-status" hidden aria-live="polite"><span id="messenger-upload-text">Загрузка вложения…</span><span id="messenger-upload-percent">0%</span><progress id="messenger-upload-progress" max="100" value="0"></progress></div>

            <footer class="messenger-composer">
                <div class="messenger-composer__tools" aria-label="Вложения и действия">
                    <div class="messenger-workspace-create">
                        <button class="messenger-icon-button" id="workspace-create-button" type="button" title="Создать задачу или заметку" aria-label="Создать задачу или заметку" aria-expanded="false" aria-controls="workspace-create-menu"><i class="fa fa-plus" aria-hidden="true"></i></button>
                        <div class="messenger-workspace-menu" id="workspace-create-menu" hidden>
                            <?php if (!empty($workspaceActions['tasks'])): ?>
                                <button class="messenger-workspace-menu__item" type="button" data-create-workspace="task">
                                    <i class="fa fa-check-square-o" aria-hidden="true"></i>
                                    <span><strong>Создать задачу</strong><small>Не выходя из чата</small></span>
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($workspaceActions['notes'])): ?>
                                <button class="messenger-workspace-menu__item" type="button" data-create-workspace="note">
                                    <i class="fa fa-sticky-note-o" aria-hidden="true"></i>
                                    <span><strong>Создать заметку</strong><small>Сохранить мысль в Notes</small></span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($workspaceActions['files'])): ?>
                        <button class="messenger-icon-button" id="message-storage-button" type="button" title="Файл из личного хранилища" aria-label="Файл из личного хранилища"><i class="fa fa-cloud" aria-hidden="true"></i></button>
                    <?php endif; ?>
                    <button class="messenger-icon-button" id="message-attach-button" type="button" title="Прикрепить файл" aria-label="Прикрепить файл"><i class="fa fa-paperclip" aria-hidden="true"></i></button>
                    <input class="messenger-file-input" id="message-file-input" type="file" multiple accept="image/jpeg,image/png,image/gif,image/webp,audio/*,video/mp4,video/webm,video/quicktime,.pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp" aria-label="Выбрать вложение">
                </div>
                <textarea id="message-input" rows="1" maxlength="4096" placeholder="Сообщение" aria-label="Текст сообщения"></textarea>
                <button class="messenger-send-button" id="message-send-button" type="button" aria-label="Отправить"><i class="fa fa-paper-plane" aria-hidden="true"></i></button>
            </footer>
        </div>
    </main>
</section>

<dialog class="messenger-dialog-modal" id="new-chat-dialog">
    <form method="dialog" class="messenger-dialog-modal__surface" id="new-chat-form">
        <header><div><strong>Новый чат</strong><span>Один участник — личный чат, несколько — группа.</span></div><button class="messenger-icon-button" value="cancel" aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button></header>
        <label class="messenger-field"><span>Название группы <small>(необязательно)</small></span><input id="new-chat-name" type="text" maxlength="120" placeholder="Например, Проект Notes"></label>
        <div class="messenger-search messenger-search--modal"><i class="fa fa-search" aria-hidden="true"></i><input id="contact-search" type="search" autocomplete="off" placeholder="Найти пользователя"></div>
        <div class="messenger-contact-list" id="contact-list">
            <?php if ($contactRows !== []): ?>
                <?php foreach ($contactRows as $contact): ?>
                    <?php if (!is_array($contact)) { continue; } $searchText = trim((string) ($contact['firstname'] ?? '') . ' ' . (string) ($contact['lastname'] ?? '') . ' ' . (string) ($contact['username'] ?? '')); ?>
                    <label class="messenger-contact" data-contact-search="<?= $view->e($searchText) ?>">
                        <input class="messenger-contact__checkbox" type="checkbox" value="<?= $view->e($contact['uid'] ?? '') ?>">
                        <span class="messenger-avatar messenger-avatar--small" aria-hidden="true"><i class="fa fa-user"></i></span>
                        <span class="messenger-contact__identity"><strong><?= $view->e($contact['firstname'] ?? '') ?> <?= $view->e($contact['lastname'] ?? '') ?></strong><small>@<?= $view->e($contact['username'] ?? '') ?></small></span>
                    </label>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="messenger-contact-list__empty">Нет доступных пользователей</div>
            <?php endif; ?>
        </div>
        <footer><button class="messenger-secondary-button" value="cancel">Отмена</button><button class="messenger-primary-button" id="create-chat-button" type="button">Создать чат</button></footer>
    </form>
</dialog>

<dialog class="messenger-dialog-modal messenger-storage-dialog" id="storage-file-dialog">
    <form method="dialog" class="messenger-dialog-modal__surface messenger-storage-dialog__surface">
        <header>
            <div><strong>Личное хранилище</strong><span>Выберите файл и отправьте его вложением или публичной ссылкой.</span></div>
            <button class="messenger-icon-button" value="cancel" aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button>
        </header>
        <div class="messenger-storage-search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input id="storage-file-search" type="search" autocomplete="off" placeholder="Поиск файлов">
        </div>
        <div class="messenger-storage-list" id="storage-file-list" aria-live="polite"></div>
        <div class="messenger-storage-empty" id="storage-file-empty" hidden>Файлы не найдены</div>
        <div class="messenger-storage-selected" id="storage-file-selected" hidden>
            <i class="fa fa-file-o" aria-hidden="true"></i>
            <div><strong id="storage-file-selected-name"></strong><span id="storage-file-selected-meta"></span></div>
        </div>
        <footer class="messenger-storage-actions">
            <button class="messenger-secondary-button" value="cancel">Отмена</button>
            <button class="messenger-secondary-button" id="storage-send-link" type="button" disabled><i class="fa fa-link" aria-hidden="true"></i> Отправить ссылкой</button>
            <button class="messenger-primary-button" id="storage-send-attachment" type="button" disabled><i class="fa fa-paperclip" aria-hidden="true"></i> Отправить файлом</button>
        </footer>
    </form>
</dialog>

<dialog class="messenger-dialog-modal messenger-workspace-dialog" id="workspace-action-dialog">
    <form class="messenger-dialog-modal__surface messenger-workspace-dialog__surface" id="workspace-action-form">
        <header>
            <div><strong>Создать в Workspace</strong><span>Задача или заметка сохранятся сразу, без перехода из Messenger.</span></div>
            <button class="messenger-icon-button" id="workspace-action-close" type="button" aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button>
        </header>
        <div class="messenger-workspace-kind" role="tablist" aria-label="Тип объекта">
            <button type="button" role="tab" data-workspace-kind="task" aria-selected="true"><i class="fa fa-check-square-o" aria-hidden="true"></i> Задача</button>
            <button type="button" role="tab" data-workspace-kind="note" aria-selected="false"><i class="fa fa-sticky-note-o" aria-hidden="true"></i> Заметка</button>
        </div>
        <div class="messenger-workspace-form">
            <div class="messenger-workspace-source" id="workspace-action-source" hidden>
                <strong>Источник — сообщение из этого чата</strong>
                <span id="workspace-action-source-text"></span>
            </div>
            <label class="messenger-workspace-field">
                <span>Название</span>
                <input id="workspace-action-title" type="text" maxlength="255" required autocomplete="off">
            </label>
            <label class="messenger-workspace-field">
                <span>Описание</span>
                <textarea id="workspace-action-body" maxlength="60000" rows="5" placeholder="Добавьте детали"></textarea>
            </label>
            <div class="messenger-workspace-task-fields" id="workspace-task-fields">
                <label class="messenger-workspace-field">
                    <span>Приоритет</span>
                    <select id="workspace-action-priority">
                        <option value="low">Низкий</option>
                        <option value="medium" selected>Средний</option>
                        <option value="high">Высокий</option>
                        <option value="urgent">Срочный</option>
                    </select>
                </label>
                <label class="messenger-workspace-field">
                    <span>Срок</span>
                    <input id="workspace-action-due" type="datetime-local">
                </label>
            </div>
            <div class="messenger-workspace-submit">
                <button class="messenger-secondary-button" type="button" id="workspace-action-cancel">Отмена</button>
                <button class="messenger-primary-button" type="submit" id="workspace-action-submit">Создать задачу</button>
            </div>
        </div>
    </form>
</dialog>

<dialog class="messenger-dialog-modal" id="group-info-dialog">
    <form method="dialog" class="messenger-dialog-modal__surface messenger-group-dialog__surface">
        <header><div><strong>Информация о группе</strong><span>Участники и права доступа</span></div><button class="messenger-icon-button" value="cancel" aria-label="Закрыть"><i class="fa fa-times" aria-hidden="true"></i></button></header>
        <div class="messenger-group-dialog__body">
            <div id="group-loading">Загрузка информации о группе…</div>
            <div id="group-content" hidden>
                <div class="messenger-group-summary"><div class="messenger-avatar" id="group-summary-avatar" aria-hidden="true">?</div><div class="messenger-group-summary__identity"><strong id="group-summary-title">Группа</strong><span id="group-summary-text"></span><span class="messenger-group-role" id="group-current-role" data-role="member">Участник</span></div></div>
                <section class="messenger-group-section"><h3>Название</h3><div class="messenger-group-name-row"><input id="group-name-input" type="text" maxlength="120" aria-label="Название группы"><button class="messenger-primary-button" id="group-save-name" type="button">Сохранить</button></div></section>
                <section class="messenger-group-section"><h3>Участники</h3><div class="messenger-group-members" id="group-member-list"></div></section>
                <section class="messenger-group-section" id="group-add-section">
                    <h3>Добавить участников</h3>
                    <div class="messenger-group-add-list" id="group-add-contact-list">
                        <?php if ($contactRows !== []): ?>
                            <?php foreach ($contactRows as $contact): ?>
                                <?php if (!is_array($contact)) { continue; } ?>
                                <label class="messenger-contact messenger-group-add-contact" data-contact-uid="<?= $view->e($contact['uid'] ?? '') ?>">
                                    <input class="messenger-contact__checkbox messenger-group-add-checkbox" type="checkbox" value="<?= $view->e($contact['uid'] ?? '') ?>">
                                    <span class="messenger-avatar messenger-avatar--small" aria-hidden="true"><i class="fa fa-user"></i></span>
                                    <span class="messenger-contact__identity"><strong><?= $view->e($contact['firstname'] ?? '') ?> <?= $view->e($contact['lastname'] ?? '') ?></strong><small>@<?= $view->e($contact['username'] ?? '') ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="messenger-contact-list__empty">Нет доступных пользователей</div>
                        <?php endif; ?>
                    </div>
                    <div class="messenger-group-section__footer"><button class="messenger-primary-button" id="group-add-button" type="button">Добавить выбранных</button></div>
                </section>
                <section class="messenger-group-section messenger-group-danger-row"><button class="messenger-danger-button" id="group-leave-button" type="button">Выйти из группы</button></section>
            </div>
        </div>
    </form>
</dialog>

<?php foreach ($jsFiles as $jsFile): ?>
    <?php $scriptSource = str_replace([$literalOpen, $literalClose], '', $readModuleAsset($jsFile)); ?>
    <?php if ($scriptSource !== ''): ?><script nonce="<?= $view->e($cspNonce) ?>"><?= $scriptSource ?></script><?php endif; ?>
<?php endforeach; ?>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Мессенджер',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'body_class' => 'workspace-viewport workspace-viewport--messenger',
], $content);
