{extends file='core/base.tpl'}
{block name=title}
	Мессенджер
{/block}
{block name=body}
	<main class="messager">
		<div class="messager__container">
			<div class="messager__contact">
				<!-- DIALOG LIST VIEW -->
				{if !$dialogues}
					<div id="dialogues-empty" class="messager__contact_empty">
						Диалогов нет. Создать?
					</div>
				{else}
					{foreach $dialogues as $dialog}
						<div class="messager__contact_item" user-id="{$dialog['u_uid']}" data-duid="{$dialog['d_uid']}">
							<p class="messager__username">{$dialog['u_firstname']} {$dialog['u_surname']}</p>
						</div>
					{/foreach}
				{/if}
				<!-- DIALOG LIST VIEW END -->
				<!-- CREATE DIALOG -->
				<div class="messager__contact_list" id="view-users" style="display: none;">
					<h2>Создание чата</h2>
					<form action="/Messager/createDialog" method="post">
						<input type="text" name="dialog-name" id="dialog-name" placeholder="Введите имя чата">
						{foreach $users as $user}
							<label class="messager__contact_link" for="{$user['uid']}">
								<input type="checkbox" name="contact[]" id="{$user['uid']}" value="{$user['uid']}">
								{$user['firstname']} {$user['surname']}
							</label>
						{/foreach}

						<input type="submit" value="Создать чат">
					</form>
				</div>
				<!-- CREATE DIALOG END -->
			</div>
			<!-- DIALOG WINDOW -->
			<div id="messager-window" class="messager__messages" data-uid="">
				<div class="messager__content" id="msg-content" style="display: none;">
					<div class="messager__header" id="msg-header">Заголовок диалога <div id="unread-count"></div>
					</div>
					<div id="msg-view" class="messager__view"></div>
					<div class="messager__send">
						<span id="typingNotification" style="display:none">Печатает</span>
						<div class="messager__send--input">
							<input class="messager__send--field" type="text" name="message" id="message-field"
								placeholder="Напишите сообщение...">
							
						</div>
						<div class="messager__send--buttons">
							<button class="messager__send--btn attach-file" type="button" name="attach-file" id="attach-file">
								<i class="fa fa-paperclip" aria-hidden="true"></i>
							</button>
							<div class="attach-file__dropdown">
								<button class="attach-file__dropdown--item" type="button"
									name="attach-image">Изображение</button>
								<button class="attach-file__dropdown--item" type="button" name="attach-audio">Аудио</button>
								<button class="attach-file__dropdown--item" type="button" name="attach-video">Видео</button>
								<button class="attach-file__dropdown--item" type="button" name="attach-file">Файл</button>
							</div>
							<button class="messager__send--btn" type="button" name="record-media" id="record-media">
								<i class="fa fa-microphone" aria-hidden="true"></i>
							</button>
							<button class="messager__send--btn" type="submit" name="send" id="message-send">
								<i class="fa fa-paper-plane" aria-hidden="true"></i>
							</button>
						</div>
					</div>
				</div>

				<div class="messager__empty" id="msg-empty" style="display: flex;">
					Выберите чат или создайте новый
				</div>
			</div>
			<!-- DIALOG WINDOW END -->
		</div>
	</main>
	<script>
		{include file="messager_page/script.js"}
	</script>
{/block}