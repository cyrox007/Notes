<link rel="stylesheet" href="<?=$data['font-awesome']; ?>">
<main class="messager">
    <div class="messager__container">
        <div class="messager__contact">
            <? if (!$data['dialogues']): ?>
                <div id="dialogues-empty" class="messager__contact_empty">
                    Диалогов нет. Создать?
                </div>
            <? else: ?>
                <? foreach ($data['dialogues'] as $dialog): ?>
                <div class="messager__contact_item" data-href="<? echo $data['base-url'].'Messager/getMsg/'.$dialog['dialog_id']?>">
                    <p class="messager__username"><? echo $dialog['profile']['first_name'].' '.$dialog['profile']['surname']; ?></p>
                </div>
                <? endforeach; ?>
            <? endif; ?>
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
        </div>
        <div class="messager__messages">
            <? if ($data['dialogues']): ?>
                <div id="msg-view" class="messager__view"></div>
                <form action="" method="post" class="messager__send">
                    <input class="messager__send--field" type="text" name="message" id="message-field" placeholder="Напишите сообщение...">
                    <button class="messager__send--btn" type="submit" name="send" id="message-send">
                        <i class="fa fa-paper-plane" aria-hidden="true"></i>
                    </button>
                </form>
            <? else: ?>
                <div class="messager__empty">
                    Выберите чат или создайте новый
                </div>
            <? endif; ?>
        </div>
    </div>
</main>
<script src="<? echo $data['msg-script']; ?>"></script>