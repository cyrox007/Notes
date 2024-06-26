{* Smarty *}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="My Workspace System">
    <title>My Workspace - {block name=title}{/block}</title>
    <style>
        {include file='assets/css/auth_page/style.css'}
    </style>
</head>
<body>
    {block name=body}{/block}
    <script>
        {include file='assets/js/reg-script.js'}
    </script>
</body>
</html>