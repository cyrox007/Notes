{extends file='core/base.tpl'}
{block name="title"}
	Мессенджер
{/block}
{block name="body"}
	<!-- Кнопка запроса разрешений на уведомления (скрыта по умолчанию) -->
	<button id="notification-permission-btn" class="notification-permission-btn" style="display: none;" title="Разрешить уведомления">
			<i class="fa fa-bell"></i> Включить уведомления
	</button>
	<section class="messenger">
		<div class="messenger__dialog-list">
			<header class="messenger__dialog-list__header">
				<div class="messenger__dialog-list__header--btn">
					<button>Новый диалог</button>
				</div>
				<div class="messenger__dialog-list__header--search">
					<input type="search" name="" id="" placeholder="Search...">
					<span><i class="fa fa-search" aria-hidden="true"></i></span>
				</div>
			</header>
			<div class="messenger__dialog-list__items">
				{if !$dialogues}
					<div id="dialogues-empty" class="messager__contact_empty">
						Диалогов нет. Создать?
					</div>
				{else}
					{foreach $userToDialogs as $utd}
						<div class="messenger__dialog-list__item" data-dialog_id="{$utd.dialogs.uid}">
							<div class="messenger__dialog-list__item--img">
								<img src="../../../assets/img/default_avatar.png" alt="Имя диалога">
							</div>
							<div class="messenger__dialog-list__item--body">
								<div class="messenger__dialog-list__item--header">
									<span id="userfullname">
										<b>{$utd.users.u_firstname} {$utd.users.surname}</b>
									</span>
									<span>
										14:30
									</span>
								</div>
								<div class="messenger__dialog-list__item--msg">
									<span>Alex: I would like to share my p ...</span>
								</div>
							</div>
						</div>
					{/foreach}
				{/if}
			</div>
		</div>
		<div class="messenger__dialog-window" id="messager-window" data-uid="">
			<div id="messager-disable" style="display: block;"></div>
			<div id="messager-viewer" style="display: none; flex-direction:column;">
				<header class="messenger__dialog-window__header" id="msg-header">
					<div class="messenger__dialog-window__header--img">
						<img src="../../../assets/img/default_avatar.png" alt="Имя диалога">
					</div>
					<div class="messenger__dialog-window__header--body">
						<span><b id="userfullname">Имя диалога</b></span>
					</div>
				</header>

				<div class="messenger__dialog-window__viewer">
					<div class="messenger__dialog-window__messages" id="msg-view"></div>
				</div>

				<div class="messenger__dialog-window__control">
					<div class="messenger__dialog-window__control_typing">
						<span></span>
					</div>
					<div class="messenger__dialog-window__control_panel">
						<div class="messenger__dialog-window__control--file" id="attach-file">
							<i class="fa fa-paperclip" aria-hidden="true"></i>
						</div>
						<div class="messenger__dialog-window__control--message">
							<input name="message" id="message-field" placeholder="Введите сообщение...">
						</div>
						<div class="messenger__dialog-window__control--send">
							<button id="message-send">
								<i class="fa fa-paper-plane" aria-hidden="true"></i>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>
	<script src="/assets/js/messenger/script.js"></script>
{/block}