{extends file='core/base.tpl'}
{block name=title}
    Мессенджер
{/block}
{block name=body}
<main class="messager">
    <div class="messager__container">
        <div class="messager__contact">
            <!-- DIALOG VIEW -->
            {if !$dialogues}
                <div id="dialogues-empty" class="messager__contact_empty">
                    Диалогов нет. Создать?
                </div>
            {else}
                {foreach $dialogues as $dialog}
                    <div class="messager__contact_item" data-href="{$dialog['d_uid']}" user-id="{$dialog['u_uid']}" data-duid="{$dialog['d_uid']}">
                        <p class="messager__username">{$dialog['u_firstname']} {$dialog['u_surname']}</p>
                    </div>
                {/foreach}
            {/if}
            <!-- DIALOG VIEW END -->
            <!-- CREATE DIALOG -->
            <div class="messager__contact_list" id="view-users" style="display: none;">
                <h2>Создание чата</h2>
                <form action="/Messager/createDialog" method="post">
                    <input type="text" name="dialog-name" id="dialog-name" placeholder="Введите имя чата">
                    {foreach $users as $user}
                        <label class="messager__contact_link" for="{$user['user_id']}">
                            <input type="checkbox" name="contact[]" id="{$user['user_id']}" value="{$user['user_id']}">
                            {$user['first_name']} {$user['surname']}
                        </label>
                    {/foreach}
                    
                    <input type="submit" value="Создать чат">
                </form>
            </div>
            <!-- CREATE DIALOG END -->
        </div>
        <div id="messager-window" class="messager__messages" to-user="" dialog-id="">
            {if $dialogues}
                <div id="msg-view" class="messager__view"></div>
                <div class="messager__send">
                    <input class="messager__send--field" type="text" name="message" id="message-field" placeholder="Напишите сообщение...">
                    <button class="messager__send--btn" type="submit" name="send" id="message-send">
                        <i class="fa fa-paper-plane" aria-hidden="true"></i>
                    </button>
                </div>
            {else}
                <div class="messager__empty">
                    Выберите чат или создайте новый
                </div>
            {/if}
        </div>
    </div>
</main>
<script>
    {include file="assets/js/msg_script.js"}
</script>
{/block}