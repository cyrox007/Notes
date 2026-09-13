{extends file='core/base.tpl'}

{block name=title}Мессенджер{/block}

{block name=body}
<style>{include file='messager_page/style.css'}</style>

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

        <div class="messenger-dialogs" id="dialog-list" aria-live="polite"></div>
        <div class="messenger-list__empty" id="dialog-list-empty" hidden>
            <i class="fa fa-comments-o" aria-hidden="true"></i>
            <strong>Диалогов пока нет</strong>
            <span>Создайте первый чат с коллегой.</span>
        </div>
    </aside>

    <main class="messenger-chat" id="messenger-chat">
        <div class="messenger-chat__empty" id="chat-empty-state">
            <div class="messenger-chat__empty-icon"><i class="fa fa-paper-plane-o" aria-hidden="true"></i></div>
            <strong>Выберите диалог</strong>
            <span>Или создайте новый чат — переписка появится здесь.</span>
        </div>

        <div class="messenger-chat__active" id="chat-active" hidden>
            <header class="messenger-chat__header">
                <button class="messenger-icon-button messenger-chat__back" id="chat-back-button" type="button" aria-label="Назад к диалогам">
                    <i class="fa fa-arrow-left" aria-hidden="true"></i>
                </button>
                <div class="messenger-avatar" id="chat-avatar" aria-hidden="true">?</div>
                <div class="messenger-chat__identity">
                    <strong id="chat-title">Диалог</strong>
                    <span id="chat-subtitle">&nbsp;</span>
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

            <footer class="messenger-composer">
                <button class="messenger-icon-button" type="button" disabled title="Вложения подключим после private-storage migration" aria-label="Прикрепить файл">
                    <i class="fa fa-paperclip" aria-hidden="true"></i>
                </button>
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
                    <span class="messenger-avatar messenger-avatar--small">
                        {if $contact.firstname}{$contact.firstname|substr:0:1|upper|escape}{else}?{/if}
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

<script>{include file='messager_page/script.js'}</script>
{/block}
