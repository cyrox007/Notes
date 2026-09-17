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
	<link rel="stylesheet" href="{$base_url}/assets/css/findability.css">
	<link rel="stylesheet" href="{$base_url}/assets/css/feedback.css">
	<link rel="stylesheet" href="{$base_url}/assets/css/file-manager-polish.css">
	<link rel="stylesheet" href="{$base_url}/assets/css/messenger-connection-ux.css">
	<link rel="stylesheet" href="{$base_url}/assets/css/live-qa-fixes.css?v={$version|escape:'url'}">
	<link rel="stylesheet" href="{$base_url}/assets/css/live-qa-final.css?v={$version|escape:'url'}">
	<link rel="icon" href="{$base_url}/favicon.ico" type="image/x-icon">
	<template id="csrf-token-template">{csrf_token}</template>
	<script>
		wspace = {};
		{include file="core/common.js"}
	</script>
	<script src="{$base_url}/assets/js/findability.js" defer></script>
	<script src="{$base_url}/assets/js/feedback.js" defer></script>
	<script src="{$base_url}/assets/js/notes-draft.js" defer></script>
	<script src="{$base_url}/assets/js/usability-actions.js" defer></script>
	<script src="{$base_url}/assets/js/release-polish.js" defer></script>
	<script src="{$base_url}/assets/js/file-manager-polish.js" defer></script>
	<script src="{$base_url}/assets/js/file-manager-drop-upload.js" defer></script>
	<script src="{$base_url}/assets/js/tasks-kanban.js" defer></script>
	<script src="{$base_url}/assets/js/task-boards-nav.js" defer></script>
	<script src="{$base_url}/assets/js/messenger-connection-ux.js" defer></script>
</head>

<body{if isset($pagination)}
	data-list-q="{$pagination.q|default:''|escape}"
	data-list-page="{$pagination.page|default:1|escape}"
	data-list-limit="{$pagination.limit|default:20|escape}"
	data-list-total="{$pagination.total|default:0|escape}"
	data-list-total-pages="{$pagination.total_pages|default:1|escape}"
	data-list-sort="{$pagination.sort|default:''|escape}"
	data-list-direction="{$pagination.direction|default:'desc'|escape}"
	data-list-filter="{$pagination.filter|default:''|escape}"{/if}>
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