<link rel="stylesheet" href="<? echo $data['font-awesome']; ?>">
<main class="messager">
    <div class="messager__container">
        <div class="messager__contact">
            <? if (!$data['dialogues']): ?>
                <div id="dialogues-empty" class="messager__contact_empty">
                    Диалогов нет. Создать?
                </div>
            <? else: ?>
                <? foreach($data['dialogues'] as $dialogues): ?>
                    <div class="messager__contact_item">
                        <p class="messager__username"><? echo $dialogues['first_name'].' '.$dialogues['surname']; ?></p>
                    </div>
                <? endforeach; ?>
            <? endif; ?>
            <div class="messager__contact_list" id="view-users" style="display: none;">
                <? foreach ($data['all-users'] as $all_users): ?>
                    <a class="messager__contact_link" href="/Messager/startDialog/<? echo $all_users['user_id']; ?>">
                        <? echo $all_users['first_name'].' '.$all_users['surname']; ?>
                    </a>
                <? endforeach; ?>
            </div>
        </div>
        <div class="messager__messages">
            <div class="messager__view"></div>
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