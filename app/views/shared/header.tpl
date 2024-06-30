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