{extends file='core/base.tpl'}
{block name=title}Главная{/block}
{block name=body}
<section class="workspace-home">
    <div class="workspace-home__hero">
        <div class="workspace-home__hero-copy">
            <span class="workspace-home__eyebrow">Личное рабочее пространство</span>
            <h1>Добро пожаловать, {$user['firstname']|default:$user['username']}!</h1>
            <p>Заметки, задачи, файлы и сообщения собраны в одном интерфейсе. Продолжите работу в нужном разделе или быстро перейдите к основным инструментам.</p>
            <div class="workspace-home__hero-actions">
                <a href="{route_path name='notes'}" class="workspace-home__primary-action">
                    <i class="fa fa-plus" aria-hidden="true"></i>
                    Новая заметка
                </a>
                <a href="{route_path name='tasks'}" class="workspace-home__secondary-action">
                    <i class="fa fa-check-square-o" aria-hidden="true"></i>
                    Открыть задачи
                </a>
            </div>
        </div>
        <div class="workspace-home__hero-status" aria-label="Статус рабочей сессии">
            <div class="workspace-home__status-icon"><i class="fa fa-shield" aria-hidden="true"></i></div>
            <div>
                <strong>Защищённая сессия</strong>
                <span>Данные доступны только после авторизации</span>
            </div>
        </div>
    </div>

    <div class="workspace-home__section-heading">
        <div>
            <span>Инструменты</span>
            <h2>Что хотите открыть?</h2>
        </div>
    </div>

    <div class="workspace-home__grid">
        <a class="workspace-module workspace-module--notes" href="{route_path name='notes'}">
            <span class="workspace-module__icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
            <span class="workspace-module__body">
                <strong>Блокнот</strong>
                <small>Личные зашифрованные заметки, вложения и ссылки для просмотра.</small>
            </span>
            <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
        </a>

        <a class="workspace-module workspace-module--tasks" href="{route_path name='tasks'}">
            <span class="workspace-module__icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
            <span class="workspace-module__body">
                <strong>Задачи</strong>
                <small>Приоритеты, сроки, категории, статусы и подзадачи.</small>
            </span>
            <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
        </a>

        <a class="workspace-module workspace-module--files" href="{route_path name='files'}">
            <span class="workspace-module__icon"><i class="fa fa-folder-open-o" aria-hidden="true"></i></span>
            <span class="workspace-module__body">
                <strong>Файлы</strong>
                <small>Личное приватное хранилище с папками и защищённой выдачей.</small>
            </span>
            <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
        </a>

        <a class="workspace-module workspace-module--messenger" href="{route_path name='messenger'}">
            <span class="workspace-module__icon"><i class="fa fa-comments-o" aria-hidden="true"></i></span>
            <span class="workspace-module__body">
                <strong>Мессенджер</strong>
                <small>Личные и групповые чаты, медиа, голосовые сообщения и реакции.</small>
            </span>
            <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
        </a>

        <a class="workspace-module workspace-module--profile" href="{route_path name='profile'}">
            <span class="workspace-module__icon"><i class="fa fa-user-o" aria-hidden="true"></i></span>
            <span class="workspace-module__body">
                <strong>Профиль</strong>
                <small>Контактные данные, аватар, пароль и управление аккаунтом.</small>
            </span>
            <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
        </a>

        {if $user['role'] == 1 || $user['role'] == 111}
            <a class="workspace-module workspace-module--admin" href="{route_path name='adminpanel'}">
                <span class="workspace-module__icon"><i class="fa fa-sliders" aria-hidden="true"></i></span>
                <span class="workspace-module__body">
                    <strong>Администрирование</strong>
                    <small>Пользователи, статусы и пользовательские поля профиля.</small>
                </span>
                <i class="fa fa-arrow-right workspace-module__arrow" aria-hidden="true"></i>
            </a>
        {/if}
    </div>

    <div class="workspace-home__notice">
        <i class="fa fa-lock" aria-hidden="true"></i>
        <div>
            <strong>Workspace хранит приватные файлы вне web-root.</strong>
            <span>Для production используйте HTTPS/WSS, резервные копии private storage и актуальные ключи шифрования.</span>
        </div>
    </div>
</section>
{/block}