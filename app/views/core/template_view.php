<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes v1.3.3 - <? echo "{$data['title']}";?></title>
	<script src="https://kit.fontawesome.com/45cc6d666e.js" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="../../templates/style/style.css">
    <link rel="shortcut icon" href="/img/favicon.png" type="image/x-icon">
</head>
<body>
    <header class="header">
        <i class="fas fa-clipboard"></i>
        <h1 class="title">Блокнот 1.3.3</h1>
        <? if ($_SESSION['key'] != null): ?>
        <a href="/Auth/logout">Выйти</a>
        <? endif ?>
    </header>
    <?php include 'app/views/'.$content_view; ?>
    <hr style="height: 1px;">
    <!-- <footer class="footer">
        <span class="copy">&copy;</span><a href="https://vk.com/jsinteractive" target="_blank" rel="noopener noreferrer">+Ультра</a> 
    </footer> -->
    <script src="../../templates/js/script.js"></script>
</body>
</html>