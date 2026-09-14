<header class="navbar">
	<button type="button" id="sidebarControl" class="navbar__menu-button" aria-controls="workspaceSidebar" aria-expanded="true" aria-label="Свернуть или открыть навигацию" title="Меню">
		<i class="fa fa-bars" aria-hidden="true"></i>
	</button>

	<a href="{route_path name='main'}" class="navbar__home" aria-label="На главную">
		<span class="navbar__home-mark" aria-hidden="true">W</span>
		<span class="navbar__home-copy">
			<strong>{$sitename}</strong>
			<small>рабочее пространство</small>
		</span>
	</a>

	<div class="navbar__spacer"></div>

	<nav class="navbar__actions" aria-label="Пользовательские действия">
		{if $user['role'] == 1 || $user['role'] == 111}
			<a class="navbar__action" href="{route_path name="adminpanel"}" title="Админпанель">
				<i class="fa fa-sliders" aria-hidden="true"></i>
				<span>Админ</span>
			</a>
		{/if}

		<a class="navbar__action navbar__action--profile" href="{route_path name='profile'}" title="Профиль">
			<i class="fa fa-user-o" aria-hidden="true"></i>
			<span>{$user['firstname']|default:$user['username']}</span>
		</a>

		{if session key="auth"}
			<form action="{route_path name="logout"}" method="post" class="navbar__logout-form">
				{csrf_token}
				<button type="submit" class="navbar__action navbar__action--logout" title="Выйти">
					<i class="fa fa-sign-out" aria-hidden="true"></i>
					<span>Выйти</span>
				</button>
			</form>
		{/if}
	</nav>
</header>

<div id="notification-region" class="notification-region" aria-live="polite" aria-atomic="true"></div>