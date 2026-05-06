{extends file="core/base.tpl"}
{block name=title}
	{$user['firstname']} {$user['surname']}
{/block}
{block name=body}
<section class="profile">
	<div class="profile__column-left">
		<div class="profile__card-avatar">
			<p class="profile__user-login">
				{$user['username']}
			</p>
			{if $user['user_image'] == 'default_img'}
				{html_image file="{$base_url}/assets/img/default_avatar.png" alt="{$user['firstname']} {$user['surname']}" class="img-circle elevation-2"}
			{else}
				{html_image file="{$base_url}/{$user.user_image}" alt="{$user['firstname']} {$user['surname']}" class="img-circle elevation-2"}
			{/if}

			<button class="profile__edit_user-info">Редактировать</button>
		</div>
	</div>
	<div class="profile__column-right">
		<div class="profile__card-info">
			<div class="profile__card-info--data visible">
				<div class="profile__user-fio">
					{$user['firstname']} {$user['patronymic']} {$user['surname']}
				</div>

				<div class="profile__user-other-info">
					<p class="profile__user-detals">Телефон:
						{$user['phone']}
					</p>
				</div>
				<div class="profile__user-other-info">
					<p class="profile__user-detals">Email:
						{$user['email']}
					</p>
				</div>
				
				{if $user['uid'] != $current_user_uid}
				<div class="profile__actions" style="margin-top: 20px;">
					<button class="profile__write-message-btn" data-user-uid="{$user['uid']}" style="background: #1CA1C1; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px;">
						<i class="fa fa-envelope" aria-hidden="true"></i> Написать сообщение
					</button>
				</div>
				{/if}
				
				<hr>
				{assign var="customData" value=[]}
				{if !empty($user.property)}
					{jsonParse json=$user.property assign="customData"}
					{foreach $customData as $props}
						<div class="profile__user-other-info">
							<p class="profile__user-detals">{$props['label']}:
								{$props['value']}
							</p>
						</div>
					{/foreach}
				{/if}
				
			</div>
			<div class="profile__card-info--edit">
				<div id="close">
					<i class="fa fa-times" aria-hidden="true"></i>
				</div>
				<form name="changeProfile" action="{route_path name='profile-set'}" method="post" enctype="multipart/form-data">
					{csrf_token}
					<div class="profile__card-info--edit--form-group">
						<label for="">Имя: </label>
						<input class="profile__card-info--edit--set-input" type="text" name="set-user-name"
               				id="user-name" value="{$user.firstname|escape}" placeholder="Введите имя">
					</div>
					<div class="profile__card-info--edit--form-group">
						<label for="">Отчество: </label>
						<input class="profile__card-info--edit--set-input" type="text" name="set-user-patronymic"
               				id="user-patronymic" value="{$user.patronymic|escape}" placeholder="Введите отчество">
					</div>
					<div class="profile__card-info--edit--form-group">
						<label for="">Фамилия: </label>
						<input class="profile__card-info--edit--set-input" type="text" name="set-user-surname"
               				id="user-surname" value="{$user.surname|escape}" placeholder="Введите фамилию">
					</div>
					<div class="profile__card-info--edit--form-group">
						<label for="">Телефон: </label>
						<input class="profile__card-info--edit--set-input" type="tel" name="set-user-phone"
               				id="user-phone" value="{$user.phone|escape}" placeholder="Введите телефон">
					</div>
					<div class="profile__card-info--edit--form-group">
						<label for="">Телефон: </label>
						<input class="profile__card-info--edit--set-input" type="email" name="set-user-email"
               				id="user-email" value="{$user.email|escape}" placeholder="Введите email">
					</div>
					<label style="margin-top: 15px" for="">Изменить изображение пользователя:</label>
						<input style="margin-bottom: 15px" class="set-files" type="file" name="set-user-avatar"
							id="user-avatar">

					{assign var="userCustomData" value=[]}

					{if !empty($user.property)}
						{jsonParse json=$user.property assign="parsedData"}
						{foreach from=$parsedData item=item}
							{assign var="tempArray" value=$userCustomData}
							{assign var="tempArray_item" value=[ $item.name => $item ]}
							{assign var="userCustomData" value=$tempArray + $tempArray_item}
						{/foreach}
					{/if}
					
					{foreach from=$fields item=field key=fieldName}
					<div class="profile__card-info--edit--form-group">
						<label for="{$field.field_name|escape}">{$field.field_label|escape}: </label>
						<input class="profile__card-info--edit--set-input" type="text" name="custom[{$field.field_name|escape}][value]"
								id="{$field.field_name|escape}" 
								value="{$userCustomData[$field.field_name].value|default:''|escape}" 
								placeholder="Введите {$field.field_label|escape}">
					
						<input type="hidden" name="custom[{$field.field_name|escape}][label]" value="{$field.field_label|escape}">
					</div>
					{/foreach}

					<button class="profile__card-info--edit--set-save" type="submit">Сохранить изменения</button>
				</form>
				<hr>
				<form name="changePassword" action="{route_path name='profile-password-set'}" method="post">
					{csrf_token}
					<div class="profile__card-info--edit--form-group">
						<label for="">Старый пароль: </label>
						<input class="profile__card-info--edit--set-input" type="password" name="old-password"
							id="old-password" placeholder="Введите старый пароль...">
					</div>
					<div class="profile__card-info--edit--form-group">
						<label for="">Новый пароль: </label>
						<input class="profile__card-info--edit--set-input" type="password" name="new-password"
							id="new-password" placeholder="Введите новый пароль...">
					</div>
					<div class="profile__card-info--edit--form-group">
						<label for="">Повторите пароль: </label>
						<input class="profile__card-info--edit--set-input" type="password" name="repeat-new-password"
							id="repeat-new-password" placeholder="Повторите новый пароль...">
					</div>
					<span id="error-repeat"></span>
					<button id="change-password-btn" class="profile__card-info--edit--set-save" type="submit">Изменить
						пароль</button>
				</form>
				<hr>
				<form name="deleteUser" action="/Profile/deleteUser" method="post">
					<button class="profile__card-info--edit--delete" type="submit">Удалить аккаунт</button>
				</form>
			</div>
		</div>
	</div>
</section>
<script>
	{include file="profile_page/script.js"}
</script>
{/block}