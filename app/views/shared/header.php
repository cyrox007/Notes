<nav class="navbar">
  <a href="#" id="sidebarControl" class="navbar__link"><span></span></a>
  <a href="/" class="navbar__link">Главная</a>
  <? if ($_SESSION['auth_login'] != null): ?>
    <a href="/Auth/logout" class="navbar__link">Выйти</a>
  <? endif; ?>
</nav>