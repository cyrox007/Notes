<aside class="sidebar" id="workspaceSidebar" aria-label="Основная навигация">
	<a href="/" class="sidebar__site-title" title="{$sitename}">
		<span class="sidebar__brand-mark" aria-hidden="true">W</span>
		<span class="sidebar__brand-copy">
			<strong>{$sitename}</strong>
			<small>Workspace</small>
		</span>
	</a>

	<div class="sidebar__content">
		<a href="{route_path name='profile'}" class="sidebar__user-panel" title="Открыть профиль">
			<div class="sidebar__user-image">
				{if !$user['avatar'] || $user['avatar'] == 'default_img'}
					{html_image file="/assets/img/default_avatar.png" alt="{$user['firstname']} {$user['lastname']}"}
				{else}
					{html_image file="/{$user.avatar|regex_replace:'#/+#':'/'}" alt="{$user['firstname']} {$user['lastname']}"}
				{/if}
			</div>
			<div class="sidebar__user-info">
				<strong>{$user['firstname']} {$user['lastname']}</strong>
				<span>@{$user['username']}</span>
			</div>
		</a>

		<div class="sidebar__section-label">Рабочее пространство</div>
		<nav class="sidebar__menu" aria-label="Разделы Workspace">
			<a href="{route_path name="notes"}" class="sidebar__menu-link" title="Блокнот">
				<span class="sidebar__menu-icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
				<span>Блокнот</span>
			</a>
			<a href="{route_path name="tasks"}" class="sidebar__menu-link" title="Задачи">
				<span class="sidebar__menu-icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
				<span>Задачи</span>
			</a>
			<a href="{route_path name="files"}" class="sidebar__menu-link" title="Файлы">
				<span class="sidebar__menu-icon"><i class="fa fa-folder-o" aria-hidden="true"></i></span>
				<span>Файлы</span>
			</a>
			<a href="{route_path name="messenger"}" class="sidebar__menu-link" title="Мессенджер">
				<span class="sidebar__menu-icon"><i class="fa fa-comments-o" aria-hidden="true"></i></span>
				<span>Мессенджер</span>
			</a>
			<a href="{route_path name="profile"}" class="sidebar__menu-link" title="Профиль">
				<span class="sidebar__menu-icon"><i class="fa fa-user-o" aria-hidden="true"></i></span>
				<span>Профиль</span>
			</a>
			{if $user.role == 1 || $user.role == 111}
				<div class="sidebar__section-label sidebar__section-label--admin">Управление</div>
				<a href="{route_path name="adminpanel"}" class="sidebar__menu-link" title="Админпанель">
					<span class="sidebar__menu-icon"><i class="fa fa-sliders" aria-hidden="true"></i></span>
					<span>Админпанель</span>
				</a>
			{/if}
		</nav>
	</div>
</aside>