<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$basePath = $root . '/app/views/core/base.php';
$commonStylePath = $root . '/app/views/core/common.css';
$headerPath = $root . '/app/views/^shared/header/index.php';
$sidebarPath = $root . '/app/views/^shared/sidebar/index.php';
$headerStylePath = $root . '/app/views/^shared/header/style.css';
$sidebarStylePath = $root . '/app/views/^shared/sidebar/style.css';
$cssPath = $root . '/assets/css/workspace-ui-1.0.css';
$brandCssPath = $root . '/assets/css/workspace-brand-1.0.14.css';
$brandMarkPath = $root . '/assets/img/workspace-brand-mark.svg';
$scriptPath = $root . '/assets/js/theme-mode.js';

function uiSystemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] UI system contract: {$message}\n");
        exit(1);
    }
}

foreach ([$basePath, $commonStylePath, $headerPath, $sidebarPath, $headerStylePath, $sidebarStylePath, $cssPath, $brandCssPath, $brandMarkPath, $scriptPath] as $path) {
    uiSystemAssert(is_file($path), 'missing UI system file: ' . $path);
}

$base = file_get_contents($basePath);
$commonStyle = file_get_contents($commonStylePath);
$header = file_get_contents($headerPath);
$sidebar = file_get_contents($sidebarPath);
$headerStyle = file_get_contents($headerStylePath);
$sidebarStyle = file_get_contents($sidebarStylePath);
$css = file_get_contents($cssPath);
$brandCss = file_get_contents($brandCssPath);
$brandMark = file_get_contents($brandMarkPath);
$script = file_get_contents($scriptPath);

uiSystemAssert(
    is_string($base)
    && is_string($commonStyle)
    && is_string($header)
    && is_string($sidebar)
    && is_string($headerStyle)
    && is_string($sidebarStyle)
    && is_string($css)
    && is_string($brandCss)
    && is_string($brandMark)
    && is_string($script),
    'UI system source is unreadable'
);

$uiStylesheet = '/assets/css/workspace-ui-1.0.css';
$controlsMarker = 'echo $controlsCss';
$uiPosition = strpos($base, $uiStylesheet);
$controlsPosition = strpos($base, $controlsMarker);
uiSystemAssert($uiPosition !== false, 'unified UI stylesheet is not loaded');
uiSystemAssert($controlsPosition !== false && $uiPosition > $controlsPosition, 'unified UI stylesheet must load after controls and module styles');
$brandStylesheet = '/assets/css/workspace-brand-1.0.14.css';
$brandPosition = strpos($base, $brandStylesheet);
uiSystemAssert($brandPosition !== false && $brandPosition > $uiPosition, 'брендовый слой 1.0.14 должен загружаться последним');
uiSystemAssert(str_contains($base, '/assets/img/workspace-brand-mark.svg'), 'фирменный знак не используется как favicon');
uiSystemAssert(str_contains($base, '/assets/js/theme-mode.js'), 'theme controller is not loaded');
$commonScript = (string) file_get_contents($root . '/assets/js/common.js');
uiSystemAssert(
    str_contains($commonScript, "document.querySelectorAll('.sidebar__menu-link[href], .sidebar__utility[href]')"),
    'активная навигация не учитывает нижние служебные ссылки'
);
uiSystemAssert(
    str_contains($commonStyle, 'body.sidebar-is-collapsed .wrapper__content{margin-left:var(--ui-sidebar-collapsed)}')
        && substr_count($commonScript, "sidebar-is-collapsed") >= 4
        && str_contains($commonScript, "document.body.classList.toggle('sidebar-is-collapsed', collapsed)"),
    'свёрнутая боковая панель не синхронизирует ширину основного layout'
);
uiSystemAssert(str_contains($base, "localStorage.getItem('workspace.theme') || 'light'"), 'light theme must be the safe default before paint');

foreach (['light', 'system', 'dark'] as $theme) {
    uiSystemAssert(
        str_contains($sidebar, 'data-theme-option="' . $theme . '"'),
        "theme picker is missing {$theme} mode"
    );
}
uiSystemAssert(str_contains($header, 'data-command-open'), 'top command/search trigger is missing');
uiSystemAssert(str_contains($header, 'data-command-palette'), 'command palette is missing from the structural shell');
uiSystemAssert(str_contains($sidebar, 'data-nav-key="home"'), 'sidebar home navigation is missing');
uiSystemAssert(str_contains($sidebar, 'data-sidebar-toggle'), 'sidebar collapse control is missing');
uiSystemAssert(
    str_contains($sidebar, '/assets/img/workspace-brand-mark.svg')
        && str_contains($sidebar, '<strong>Workspace</strong>')
        && !str_contains($sidebar, 'title="Notes"')
        && !str_contains($sidebar, '<strong>Notes</strong>'),
    'боковая панель вернула старый бренд Notes вместо Workspace Organizer'
);
uiSystemAssert(
    str_contains($sidebar, "route('admin_settings')")
        && str_contains($sidebar, "!empty(\$access['license_manage'])")
        && !str_contains($sidebar, 'title="Настройки профиля"'),
    'нижний пункт «Настройки» должен вести только в системные настройки с подходящим правом'
);
uiSystemAssert(
    str_contains($header, 'data-command-text="профиль profile аккаунт личный"')
        && str_contains($header, '<small>Личный аккаунт и публикации</small>')
        && str_contains($header, "route('admin_settings')")
        && str_contains($header, 'data-command-text="настройки settings системные админ"'),
    'быстрый переход не разводит личный профиль и системные настройки'
);

uiSystemAssert(str_contains($headerStyle, '.workspace-command-trigger'), 'shared header stylesheet lost command bar ownership');
uiSystemAssert(
    str_contains(
        $headerStyle,
        '.workspace-notifications__badge[hidden],.workspace-notifications__panel[hidden],.workspace-notifications__empty[hidden],.workspace-notifications__item[hidden],.workspace-notifications__actions form[hidden]{display:none!important}'
    ),
    'скрытые элементы системных уведомлений могут отображаться как реальные'
);
uiSystemAssert(str_contains($sidebarStyle, '.sidebar__theme'), 'shared sidebar stylesheet lost theme picker ownership');
uiSystemAssert(
    str_contains($sidebarStyle, '.sidebar__utility[aria-current="page"]'),
    'активные системные настройки не имеют состояния текущего раздела'
);
foreach (['.navbar__theme-option', '.navbar__theme-picker', '.sidebar__user-panel', '.sidebar__site-title'] as $legacyShellSelector) {
    uiSystemAssert(
        !str_contains($css, $legacyShellSelector),
        "unified UI CSS still carries obsolete shell selector: {$legacyShellSelector}"
    );
}
uiSystemAssert(!str_contains($css, '--sidebar-width: 198px'), 'legacy responsive sidebar width override returned');

foreach (['core/theme-refresh.css', 'core/product-ux-013.css', '/assets/css/live-qa-fixes.css', '/assets/css/live-qa-final.css', 'messager_page/style.css'] as $legacyLayer) {
    uiSystemAssert(!str_contains($base, $legacyLayer), "legacy visual layer is still loaded: {$legacyLayer}");
}

foreach (['--ui-bg:', '--ui-surface:', '--ui-text:', '--ui-border:', '--ui-primary:', 'html[data-theme="dark"]'] as $marker) {
    uiSystemAssert(str_contains($commonStyle, $marker), "core visual tokens are missing marker: {$marker}");
}
foreach ([
    '.workspace-home__hero',
    '.workspace-home__grid',
    '.module-page-header',
    '.admin-settings-tabs',
    'body[data-workspace-section="notes"]',
    'body[data-workspace-section="admin"]',
    'workspace-modal-content-in',
    'workspace-modal-content-out',
] as $marker) {
    uiSystemAssert(str_contains($css, $marker), "unified workspace layer is missing marker: {$marker}");
}

foreach (['--brand-navy:', '--brand-blue:', '--brand-cyan:', '--brand-violet:', '--brand-orange:', '--brand-teal:', '.workspace-home__hero', '.workspace-viewport--messenger'] as $marker) {
    uiSystemAssert(str_contains($brandCss, $marker), "брендовый слой 1.0.14 потерял маркер: {$marker}");
}
uiSystemAssert(str_contains($brandMark, '<svg') && str_contains($brandMark, 'Workspace Organizer'), 'фирменный SVG-знак повреждён');

foreach ([
    "workspace.theme",
    "prefers-color-scheme: dark",
    "aria-pressed",
    "dataset.theme",
    "#061329",
    "#f4f8ff",
] as $marker) {
    uiSystemAssert(str_contains($script, $marker), "theme controller is missing marker: {$marker}");
}

$feedbackCss = (string) file_get_contents($root . '/assets/css/feedback.css');
$feedbackJs = (string) file_get_contents($root . '/assets/js/feedback.js');
uiSystemAssert(
    str_contains($feedbackCss, 'wspace-dialog-in')
        && str_contains($feedbackCss, 'wspace-dialog-out')
        && str_contains($feedbackJs, "classList.add('is-closing')"),
    'системные confirm/prompt потеряли плавное появление или закрытие'
);

fwrite(STDOUT, "[OK] единая светлая/тёмная бренд-система Workspace Organizer 1.0.14\n");
