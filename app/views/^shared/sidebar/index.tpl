<aside class="sidebar">
	<a href="/" class="sidebar__site-title">

		{html_image file="/assets/img/AdminLTELogo.png"
		alt="AdminLTE Logo" class="brand-image img-circle elevation-3" style="opacity: .8"}
		<span class="brand-text font-weight-light">{$sitename}</span>
	</a>

	<div class="sidebar__content">
		<div class="sidebar__user-panel">
			<div class="sidebar__user-image">
				{if !$user['avatar'] || $user['avatar'] == 'default_img'}
					{html_image file="/assets/img/default_avatar.png"
					alt="{$user['firstname']} {$user['lastname']}" class="img-circle elevation-2"}
				{else}

					{html_image file="/{$user.avatar|regex_replace:'#/+#':'/'}"
					alt="{$user['firstname']} {$user['lastname']}" class="img-circle elevation-2"}

				{/if}
			</div>
			<div class="sidebar__user-info">
				<a href="{route_path name='profile'}" class="d-block">
					{$user['firstname']} {$user['lastname']}
				</a>
			</div>
		</div>

		<nav class="sidebar__menu">
			<div class="sidebar__menu-item">
				<a href="{route_path name="notes"}" class="sidebar__menu-link">
					<i class="fa fa-paperclip" aria-hidden="true"></i>
					<p>Блокнот</p>
				</a>
			</div>
			<div class="sidebar__menu-item">
				<a href="{route_path name="files"}" class="sidebar__menu-link">
					<i class="fa fa-folder" aria-hidden="true"></i>
					<p>Файлы</p>
				</a>
			</div>
			<div class="sidebar__menu-item">
				<a href="{route_path name="messenger"}" class="sidebar__menu-link">
					<i class="fa fa-comments" aria-hidden="true"></i>
					<p>Мессенджер</p>
				</a>
			</div>
			<div class="sidebar__menu-item">
				<a href="{route_path name="tasks"}" class="sidebar__menu-link">
					<i class="fa fa-tasks" aria-hidden="true"></i>
					<p>Задачи</p>
				</a>
			</div>
			<div class="sidebar__menu-item">
				<a href="{route_path name="profile"}" class="sidebar__menu-link">
					<i class="fa fa-user" aria-hidden="true"></i>
					<p>Профиль</p>
				</a>
			</div>
			{if $user.role == 1 || $user.role == 111}
				<div class="sidebar__menu-item">
					<a href="{route_path name="adminpanel"}" class="sidebar__menu-link">
						<i class="fa fa-cog" aria-hidden="true"></i>
						<p>Админка</p>
					</a>
				</div>
			{/if}
		</nav>
	</div>
</aside>