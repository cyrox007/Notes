{* Smarty *}
<!DOCTYPE html>
<html lang="ru">

<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="description" content="My Workspace">
	<title>{$smarty.env.SITENAME} {$smarty.env.VERSION} | {block name=title}{/block}</title>

	<style>
		{include file='styles.css'}
	</style>
	<link rel="stylesheet" href="{$base_url}/assets/font-awesome/css/font-awesome.min.css">
	<link rel="icon" href="/favicon.ico" type="image/x-icon">
	<link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
	<script>
		wspace = {};
		{include file="core/common.js"}
	</script>
</head>

<body>

	<!-- Site wrapper -->
	<div class="wrapper">
		{include file='^shared/sidebar/index.tpl'}
		<div class="wrapper__content">
			{include file='^shared/header/index.tpl'}
			<div class="content-wrapper">
				{block name=body}{/block}
			</div>
			{include file='^shared/footer/index.tpl'}
		</div>
	</div>
	<!-- ./wrapper -->
</body>

</html>