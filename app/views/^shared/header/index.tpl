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
			<img src="https://notes.loc//uploads/3b679581-4939-47c5-b63e-df76998eb9a9/avatars/27f81de1468c2655ba623069948290b6.jpg" alt="Sender Image">
		</div>
		<div class="notification-popup__info">
			<div class="notification-popup__name">Sender Name</div>
			<div class="notification-popup__message">Lorem ipsum dolor sit amet, consectetur adipiscing elit.</div>
		</div>
	</div>
</div>