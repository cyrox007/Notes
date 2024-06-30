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
		{include file='assets/css/style.css'}
	</style>

	<link rel="icon" href="/favicon.ico" type="image/x-icon">
	<link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
	<script>
		wspace = {};
	</script>
</head>

<body>

	<!-- Site wrapper -->
	<div class="wrapper">
		{include file='app/views/shared/sidebar.tpl'}
		<div class="wrapper__content">
			{include file='app/views/shared/header.tpl'}
			<div class="content-wrapper">
				{block name=body}{/block}
			</div>
			{include file='app/views/shared/footer.tpl'}
		</div>
	</div>
	<!-- ./wrapper -->
	<script>
		{include file='assets/js/script.js'}
	</script>
</body>

</html>