<link rel="stylesheet" href="<? echo $data['font-awesome']; ?>">
<main class="messager">
    <div class="messager__container">
        <div class="messager__contact">
            <? if (!$data['dialogues']): ?>
                <div id="dialogues-empty" class="messager__contact_empty">
                    Диалогов нет. Создать?
                </div>
            <? else: ?>
                <? foreach ($data['dialogues'] as $dialog): ?>
                <div class="messager__contact_item" data-href="<? echo $data['base-url'].'Messager/getMsg/'.$dialog['id']?>">
                    <p class="messager__username"><? echo $dialog['first_name'].' '.$dialog['surname']; ?></p>
                </div>
                <? endforeach; ?>
            <? endif; ?>
            <div class="messager__contact_list" id="view-users" style="display: none;">
                <? foreach ($data['users'] as $user): ?>
                    <a class="messager__contact_link" href="/Messager/startDialog/<? echo $user['user_id']; ?>">
                        <? echo $user['first_name'].' '.$user['surname']; ?>
                    </a>
                <? endforeach; ?>
            </div>
        </div>
        <div class="messager__messages">
            <div id="msg-view" class="messager__view"></div>
            <form action="" method="post" class="messager__send">
                <input class="messager__send--field" type="text" name="message" id="message-field" placeholder="Напишите сообщение...">
                <button class="messager__send--btn" type="submit" name="send" id="message-send">
                    <i class="fa fa-paper-plane" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    </div>
</main>
<script src="<? echo $data['msg-script']; ?>"></script>