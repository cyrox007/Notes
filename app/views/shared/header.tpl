<nav class="navbar">
	<a href="#" id="sidebarControl" class="navbar__link"><span></span></a>
	<a href="/" class="navbar__link">Главная</a>
	{if session key="auth"}
		<form action="{route_path name="logout"}" method="post">
			{csrf_token}
			<button type="submit" class="navbar__link">Выйти</button>
		</form>
	{/if}
	
</nav>