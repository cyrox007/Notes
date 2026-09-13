{* Smarty *}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Workspace Organizer — защищённое рабочее пространство">
    <meta name="color-scheme" content="light">
    <title>{$sitename|default:'Workspace Organizer'} — {block name=title}{/block}</title>
    <link rel="stylesheet" href="{$base_url|escape}/assets/css/auth_page/style.css">
    <link rel="stylesheet" href="{$base_url|escape}/assets/font-awesome/css/font-awesome.min.css">
    <link rel="icon" href="{$base_url|escape}/favicon.ico" type="image/x-icon">
</head>
<body>
    {block name=body}{/block}
    <script src="{$base_url|escape}/assets/js/reg-script.js" defer></script>
</body>
</html>