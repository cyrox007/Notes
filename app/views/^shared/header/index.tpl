<nav class="navbar">
	<a href="#" id="sidebarControl" class="navbar__link"><span></span></a>
	<a href="/" class="navbar__link">Главная</a>
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
<div class="notification-popup">
	<div class="notification-popup__content">
		<div class="notification-popup__image">
			{if empty($user.user_image)}
				<img src="{$base_url}assets/img/default_avatar.png" alt="Avatar">
			{else}
				<img src="{$base_url}{$user.user_image}" alt="Avatar">
			{/if}
		</div>
		<div class="notification-popup__info">
			<div class="notification-popup__name">{$user.firstname|default:''} {$user.lastname|default:''}</div>
			<div class="notification-popup__message">Lorem ipsum dolor sit amet, consectetur adipiscing elit.</div>
		</div>
	</div>
</div>