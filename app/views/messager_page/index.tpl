{extends file='core/base.tpl'}

{block name=title}Мессенджер{/block}

{block name=body}
<style>
{include file='messager_page/style.css'}
{include file='messager_page/media.css'}
{include file='messager_page/group.css'}
{include file='messager_page/search.css'}
.messenger-chat__actions { display:flex; gap:.35rem; margin-left:auto; }
.messenger-chat__actions .messenger-icon-button[data-active="true"] { color:var(--msg-accent); background:var(--msg-accent-soft); }
.messenger-folder-tabs { display:flex; gap:6px; padding:0 12px 10px; }
.messenger-folder-tab { flex:1; min-width:0; display:flex; align-items:center; justify-content:center; gap:6px; height:34px; padding:0 10px; border:0; border-radius:9px; background:transparent; color:var(--msg-muted); cursor:pointer; font:inherit; font-size:13px; }
.messenger-folder-tab:hover { background:#f5f7fa; }
.messenger-folder-tab[aria-selected="true"] { color:var(--msg-accent); background:var(--msg-accent-soft); font-weight:600; }
.messenger-folder-tab__count { min-width:18px; height:18px; display:inline-grid; place-items:center; padding:0 5px; border-radius:999px; background:rgba(127,127,127,.13); font-size:11px; }
.messenger-dialog-state-icons { display:inline-flex; align-items:center; gap:5px; flex:0 0 auto; color:var(--msg-muted); font-size:11px; }
.messenger-message__status[data-state="sent"] { color:var(--msg-muted); }
.messenger-message__status[data-state="delivered"] { color:#64748b; }
.messenger-message__status[data-state="read"] { color:var(--msg-accent); }
</style>

<section
    class="messenger-app"
    id="messenger-app"
    data-user-uid="{$user.uid|escape}"
    data-user-name="{$user.firstname|escape} {$user.lastname|escape}"
>
    <aside class="messenger-list" aria-label="Список диалогов">
        <header class="messenger-list__header">
            <div>
                <h1 class="messenger-list__title">Сообщения</h1>
                <div class="messenger-connection" id="messenger-connection" data-state="connecting">
                    <span class="messenger-connection__dot" aria-hidden="true"></span>
                    <span id="messenger-connection-text">Подключение…</span>
                </div>
            </div>
            <button class="messenger-icon-button" id="new-chat-button" type="button" title="Новый чат" aria-label="Новый чат">
                <i class="fa fa-pencil-square-o" aria-hidden="true"></i>
            </button>
        </header>

        <div class="messenger-search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input id="dialog-search" type="search" autocomplete="off" placeholder="Поиск чатов">
        </div>

        <div class="messenger-folder-tabs" role="tablist" aria-label="Папки чатов">
            <button class="messenger-folder-tab" id="chat-folder-active" type="button" role="tab" aria-selected="true">
                <i class="fa fa-comments-o" aria-hidden="true"></i>
                <span>Чаты</span>
                <span class="messenger-folder-tab__count" id="chat-folder-active-count">0</span>
            </button>
            <button class="messenger-folder-tab" id="chat-folder-archive" type="button" role="tab" aria-selected="false">
                <i class="fa fa-archive" aria-hidden="true"></i>
                <span>Архив</span>
                <span class="messenger-folder-tab__count" id="chat-folder-archive-count">0</span>
            </button>
        </div>

        <div class="messenger-dialogs" id="dialog-list" aria-live="polite"></div>
        <div class="messenger-list__empty" id="dialog-list-empty" hidden>
            <i class="fa fa-comments-o" aria-hidden="true"></i>
            <strong id="dialog-list-empty-title">Диалогов пока нет</strong>
            <span id="dialog-list-empty-text">Создайте первый чат с коллегой.</span>
        </div>
    </aside>

    <main class="messenger-chat" id="messenger-chat">
        <div class="messenger-chat__empty" id="chat-empty-state">
            <div class="messenger-chat__empty-icon"><i class="fa fa-paper-plane-o" aria-hidden="true"></i></div>
            <strong>Выберите диалог</strong>
            <span>Или создайте новый чат — переписка появится здесь.</span>
        </div>

        <div class="messenger-chat__active" id="chat-active" hidden data-dragging="false">
            <header class="messenger-chat__header">
                <button class="messenger-icon-button messenger-chat__back" id="chat-back-button" type="button" aria-label="Назад к диалогам">
                    <i class="fa fa-arrow-left" aria-hidden="true"></i>
                </button>
                <div class="messenger-avatar" id="chat-avatar" aria-hidden="true">?</div>
                <div class="messenger-chat__identity">
                    <strong id="chat-title">Диалог</strong>
                    <span id="chat-subtitle">&nbsp;</span>
                </div>
                <div class="messenger-chat__actions" aria-label="Действия с чатом">
                    <button class="messenger-icon-button" id="chat-group-button" type="button" hidden title="Информация о группе" aria-label="Информация о группе">
                        <i class="fa fa-users" aria-hidden="true"></i>
                    </button>
                    <button class="messenger-icon-button" id="chat-pin-button" type="button" title="Закрепить чат" aria-label="Закрепить чат">
                        <i class="fa fa-thumb-tack" aria-hidden="true"></i>
                    </button>
                    <button class="messenger-icon-button" id="chat-mute-button" type="button" title="Выключить уведомления на час" aria-label="Выключить уведомления на час">
                        <i class="fa fa-bell-slash-o" aria-hidden="true"></i>
                    </button>
                    <button class="messenger-icon-button" id="chat-archive-button" type="button" title="Архивировать чат" aria-label="Архивировать чат">
                        <i class="fa fa-archive" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <div class="messenger-history" id="message-scroll">
                <button class="messenger-history__older" id="load-older-button" type="button" hidden>
                    Показать более ранние сообщения
                </button>
                <div class="messenger-messages" id="message-list" aria-live="polite"></div>
            </div>

            <div class="messenger-typing" id="typing-indicator" hidden>
                <span></span><span></span><span></span>
                <em id="typing-text">печатает…</em>
            </div>

            <div class="messenger-compose-context" id="compose-context" hidden>
                <div>
                    <strong id="compose-context-title"></strong>
                    <span id="compose-context-text"></span>
                </div>
                <button class="messenger-icon-button" id="compose-context-close" type="button" aria-label="Отменить">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>

            <div class="messenger-upload-status" id="messenger-upload-status" hidden aria-live="polite">
                <span id="messenger-upload-text">Загрузка вложения…</span>
                <span id="messenger-upload-percent">0%</span>
                <progress id="messenger-upload-progress" max="100" value="0"></progress>
            </div>

            <footer class="messenger-composer">
                <button class="messenger-icon-button" id="message-attach-button" type="button" title="Прикрепить файл" aria-label="Прикрепить файл">
                    <i class="fa fa-paperclip" aria-hidden="true"></i>
                </button>
                <input
                    class="messenger-file-input"
                    id="message-file-input"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/gif,image/webp,audio/*,video/mp4,video/webm,video/quicktime,.pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp"
                    aria-label="Выбрать вложение"
                >
                <textarea
                    id="message-input"
                    rows="1"
                    maxlength="4096"
                    placeholder="Сообщение"
                    aria-label="Текст сообщения"
                ></textarea>
                <button class="messenger-send-button" id="message-send-button" type="button" aria-label="Отправить">
                    <i class="fa fa-paper-plane" aria-hidden="true"></i>
                </button>
            </footer>
        </div>
    </main>
</section>

<dialog class="messenger-dialog-modal" id="new-chat-dialog">
    <form method="dialog" class="messenger-dialog-modal__surface" id="new-chat-form">
        <header>
            <div>
                <strong>Новый чат</strong>
                <span>Один участник — личный чат, несколько — группа.</span>
            </div>
            <button class="messenger-icon-button" value="cancel" aria-label="Закрыть">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </header>

        <label class="messenger-field">
            <span>Название группы <small>(необязательно)</small></span>
            <input id="new-chat-name" type="text" maxlength="120" placeholder="Например, Проект Notes">
        </label>

        <div class="messenger-search messenger-search--modal">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input id="contact-search" type="search" autocomplete="off" placeholder="Найти пользователя">
        </div>

        <div class="messenger-contact-list" id="contact-list">
            {foreach $contacts as $contact}
                <label
                    class="messenger-contact"
                    data-contact-search="{$contact.firstname|escape} {$contact.lastname|escape} {$contact.username|escape}"
                >
                    <input class="messenger-contact__checkbox" type="checkbox" value="{$contact.uid|escape}">
                    <span class="messenger-avatar messenger-avatar--small" aria-hidden="true">
                        <i class="fa fa-user"></i>
                    </span>
                    <span class="messenger-contact__identity">
                        <strong>{$contact.firstname|escape} {$contact.lastname|escape}</strong>
                        <small>@{$contact.username|escape}</small>
                    </span>
                </label>
            {foreachelse}
                <div class="messenger-contact-list__empty">Нет доступных пользователей</div>
            {/foreach}
        </div>

        <footer>
            <button class="messenger-secondary-button" value="cancel">Отмена</button>
            <button class="messenger-primary-button" id="create-chat-button" type="button">Создать чат</button>
        </footer>
    </form>
</dialog>

<dialog class="messenger-dialog-modal" id="group-info-dialog">
    <form method="dialog" class="messenger-dialog-modal__surface messenger-group-dialog__surface">
        <header>
            <div>
                <strong>Информация о группе</strong>
                <span>Участники и права доступа</span>
            </div>
            <button class="messenger-icon-button" value="cancel" aria-label="Закрыть">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </header>

        <div class="messenger-group-dialog__body">
            <div id="group-loading">Загрузка информации о группе…</div>
            <div id="group-content" hidden>
                <div class="messenger-group-summary">
                    <div class="messenger-avatar" id="group-summary-avatar" aria-hidden="true">?</div>
                    <div class="messenger-group-summary__identity">
                        <strong id="group-summary-title">Группа</strong>
                        <span id="group-summary-text"></span>
                        <span class="messenger-group-role" id="group-current-role" data-role="member">Участник</span>
                    </div>
                </div>

                <section class="messenger-group-section">
                    <h3>Название</h3>
                    <div class="messenger-group-name-row">
                        <input id="group-name-input" type="text" maxlength="120" aria-label="Название группы">
                        <button class="messenger-primary-button" id="group-save-name" type="button">Сохранить</button>
                    </div>
                </section>

                <section class="messenger-group-section">
                    <h3>Участники</h3>
                    <div class="messenger-group-members" id="group-member-list"></div>
                </section>

                <section class="messenger-group-section" id="group-add-section">
                    <h3>Добавить участников</h3>
                    <div class="messenger-group-add-list" id="group-add-contact-list">
                        {foreach $contacts as $contact}
                            <label class="messenger-contact messenger-group-add-contact" data-contact-uid="{$contact.uid|escape}">
                                <input class="messenger-contact__checkbox messenger-group-add-checkbox" type="checkbox" value="{$contact.uid|escape}">
                                <span class="messenger-avatar messenger-avatar--small" aria-hidden="true">
                                    <i class="fa fa-user"></i>
                                </span>
                                <span class="messenger-contact__identity">
                                    <strong>{$contact.firstname|escape} {$contact.lastname|escape}</strong>
                                    <small>@{$contact.username|escape}</small>
                                </span>
                            </label>
                        {foreachelse}
                            <div class="messenger-contact-list__empty">Нет доступных пользователей</div>
                        {/foreach}
                    </div>
                    <div class="messenger-group-section__footer">
                        <button class="messenger-primary-button" id="group-add-button" type="button">Добавить выбранных</button>
                    </div>
                </section>

                <section class="messenger-group-section messenger-group-danger-row">
                    <button class="messenger-danger-button" id="group-leave-button" type="button">Выйти из группы</button>
                </section>
            </div>
        </div>
    </form>
</dialog>

<script>{include file='messager_page/script.js'}</script>
<script>{include file='messager_page/dialog-actions.js'}</script>
<script>{include file='messager_page/receipts.js'}</script>
<script>{include file='messager_page/media.js'}</script>
<script>{include file='messager_page/group.js'}</script>
<script>{include file='messager_page/search.js'}</script>
{/block}