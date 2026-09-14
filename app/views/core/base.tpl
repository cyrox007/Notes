{* Smarty *}
<!DOCTYPE html>
<html lang="ru">

<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<meta name="description" content="Workspace Organizer — заметки, задачи, файлы и коммуникация в одном рабочем пространстве">
	<meta name="theme-color" content="#0f172a">
	<meta name="color-scheme" content="light">
	<title>{$sitename} {$version} | {block name=title}{/block}</title>

	<style>
		{include file='styles.css'}
	</style>
	<link rel="stylesheet" href="{$base_url}/assets/font-awesome/css/font-awesome.min.css">
	<link rel="icon" href="{$base_url}/favicon.ico" type="image/x-icon">
	<template id="csrf-token-template">{csrf_token}</template>
	<script>
		wspace = {};
		{include file="core/common.js"}
	</script>
</head>

<body>
	<a class="skip-link" href="#main-content">Перейти к содержимому</a>
	<div class="wrapper">
		{include file='^shared/sidebar/index.tpl'}
		<div class="wrapper__content">
			{include file='^shared/header/index.tpl'}
			<main id="main-content" class="content-wrapper" tabindex="-1">
				{block name=body}{/block}
			</main>
			{include file='^shared/footer/index.tpl'}
		</div>
	</div>
</body>

</html>