<link rel="stylesheet" href="<?=$data['styles']['font-awesome']; ?>">
<main class="messager">
    <div class="messager__container">
        <div class="messager__contact">
            <!-- DIALOG VIEW -->
            <? if (!$data['dialogues']): ?>
                <div id="dialogues-empty" class="messager__contact_empty">
                    Диалогов нет. Создать?
                </div>
            <? else: ?>
                <? foreach ($data['dialogues'] as $dialog): ?>
                <div class="messager__contact_item" data-href="<? echo $data['base-url'].'Messager/getMsg/'.$dialog['dialog_id']?>" user-id="<?=$dialog['profile']['user_id']?>" dialog-id="<?=$dialog['dialog_id']?>">
                    <p class="messager__username"><? echo $dialog['profile']['first_name'].' '.$dialog['profile']['surname']; ?></p>
                </div>
                <? endforeach; ?>
            <? endif; ?>
            <!-- DIALOG VIEW END -->
            <!-- CREATE DIALOG -->
            <div class="messager__contact_list" id="view-users" style="display: none;">
                <h2>Создание чата</h2>
                <form action="/Messager/createDialog" method="post">
                    <input type="text" name="dialog-name" id="dialog-name" placeholder="Введите имя чата">
                    <? foreach ($data['users'] as $user): ?>
                        <label class="messager__contact_link" for="<? echo $user['user_id']; ?>">
                            <input type="checkbox" name="contact[]" id="<? echo $user['user_id']; ?>" value="<? echo $user['user_id']; ?>">
                            <? echo $user['first_name'].' '.$user['surname']; ?>
                        </label>
                    <? endforeach; ?>
                    <input type="submit" value="Создать чат">
                </form>
            </div>
            <!-- CREATE DIALOG END -->
        </div>
        <div id="messager-window" class="messager__messages" to-user="" dialog-id="">
            <? if ($data['dialogues']): ?>
                <div id="msg-view" class="messager__view"></div>
                <div class="messager__send">
                    <input class="messager__send--field" type="text" name="message" id="message-field" placeholder="Напишите сообщение...">
                    <button class="messager__send--btn" type="submit" name="send" id="message-send">
                        <i class="fa fa-paper-plane" aria-hidden="true"></i>
                    </button>
                </div>
            <? else: ?>
                <div class="messager__empty">
                    Выберите чат или создайте новый
                </div>
            <? endif; ?>
        </div>
    </div>
</main>
<script>
    <? include_once "templates/js/msg_script.js"; ?>
</script>