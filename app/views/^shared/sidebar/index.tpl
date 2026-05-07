<aside class="sidebar">
        <a href="/" class="sidebar__site-title">

                {html_image file="{$base_url}assets/img/AdminLTELogo.png" 
                        alt="AdminLTE Logo" class="brand-image img-circle elevation-3" style="opacity: .8"}
                <span class="brand-text font-weight-light">{$smarty.env.SITENAME}</span>
        </a>

        <div class="sidebar__content">
                <div class="sidebar__user-panel">
                        <div class="sidebar__user-image">
                                {if $user['user_image'] == 'default_img'}
                                        {html_image file="{$base_url}assets/img/default_avatar.png" 
                                                alt="{$user['firstname']} {$user['surname']}" class="img-circle elevation-2"}
                                {else}

                                        {html_image file="{$base_url}/{$user.user_image|regex_replace:'#/+#':'/'}" 
                                                alt="{$user['firstname']} {$user['surname']}" class="img-circle elevation-2"}

                                {/if}
                        </div>
                        <div class="sidebar__user-info">
                                <a href="{route_path name='profile'}" class="d-block">
                                        {$user['firstname']} {$user['surname']}
                                </a>
                        </div>
                </div>

                <nav class="sidebar__menu">
                        <div class="sidebar__menu-item">
                                <a href="{route_path name="notes"}" class="sidebar__menu-link">
                                        <i class="fa fa-paperclip" aria-hidden="true"></i>
                                        <p>Блокнот</p>
                                </a>
                        </div>
                        <div class="sidebar__menu-item">
                                <a href="{route_path name="files"}" class="sidebar__menu-link">
                                        <i class="fa fa-folder" aria-hidden="true"></i>
                                        <p>Файлы</p>
                                </a>
                        </div>
                        <div class="sidebar__menu-item">
                                <a href="{route_path name="messenger"}" class="sidebar__menu-link">
                                        <i class="fa fa-comments" aria-hidden="true"></i>
                                        <p>Мессенджер</p>
                                </a>
                        </div>
                </nav>
        </div>
</aside>
