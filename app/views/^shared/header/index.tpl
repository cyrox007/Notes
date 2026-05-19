<nav class="navbar">
	<a href="#" id="sidebarControl" class="navbar__link"><span></span></a>
	<a href="/" class="navbar__link">Главная</a>
	<a href="/messenger/" class="navbar__link navbar__messenger-link" title="Мессенджер">
		<i class="fa fa-comments"></i>
		<span class="messenger-badge" style="display: none;">0</span>
	</a>
	{if $user['role'] >= 900}
		<a class="navbar__link" href="{route_path name="adminpanel"}">Админпанель</a>
	{/if}
	{if session key="auth"}
		<form action="{route_path name="logout"}" method="post">
			{csrf_token}
			<button type="submit" class="navbar__link">Выйти</button>
		</form>
	{/if}
	
</nav>

<!-- Всплывающее уведомление о новых сообщениях -->
<div class="notification-popup" id="notification-popup">
	<div class="notification-popup__content">
		<div class="notification-popup__image">
			<img src="/assets/img/default_avatar.png" alt="Sender Image">
		</div>
		<div class="notification-popup__info">
			<div class="notification-popup__name">Новое сообщение</div>
			<div class="notification-popup__message">У вас новое сообщение</div>
		</div>
		<button class="notification-popup__close" onclick="hidePopupNotification()">
			<i class="fa fa-times"></i>
		</button>
	</div>
</div>