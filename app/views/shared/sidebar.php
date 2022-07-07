<aside class="sidebar">
  <a href="/" class="sidebar__site-title">
    <img src="<?php echo $data['tpl_images']['logo']; ?>" alt="AdminLTE Logo" class="brand-image img-circle elevation-3"
      style="opacity: .8">
    <span class="brand-text font-weight-light"><? echo $data['site']['sitename']; ?></span>
  </a>

  <div class="sidebar__content">
    <div class="sidebar__user-panel">
      <div class="sidebar__user-image">
        <img src="<?php echo $data['user-photo'];?>" class="img-circle elevation-2" alt="User Image">
      </div>
      <div class="sidebar__user-info">
        <a href="/Profile" class="d-block">
          <?php echo $data['user-name'].' '.$data['user-surname']; ?>
        </a>
      </div>
    </div>

    <nav class="sidebar__menu">
      <div class="sidebar__menu-item">
        <a href="/Notes" class="sidebar__menu-link">
          <!-- <i class="nav-icon fa fa-sticky-note" aria-hidden="true"></i> -->
          <p>Блокнот</p>
        </a>
      </div>
      <div class="sidebar__menu-item">
        <a href="/Messager" class="sidebar__menu-link">
          <p>Мессенджер</p>
        </a>
      </div>
    </nav>
  </div>
</aside>