<?php

declare(strict_types=1);

// Build with build-admin-preparation.py; this template cannot install anything.
/* REQUIREMENTS */
/* HELPERS */

function preparationApply(string $sourceRoot, string $liveRoot, string $privateRoot): string
{
    /* ENGINE */
    throw new RuntimeException('Это шаблон, а не собранный патч.');
}

$root = __DIR__;
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
require_once $root . '/core/Environment.php';
\Core\Environment::load($root . '/.env');
require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);
\Core\SessionSecurity::configure();
session_start();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$permissions = new \App\Services\PermissionService();
if ($uid <= 0 || !$permissions->hasRole($uid, 'superadmin') || !$permissions->hasPermission($uid, 'admin.settings.manage')) {
    http_response_code(403);
    exit('Войдите в Workspace как суперадминистратор, затем откройте эту страницу снова.');
}
$_SESSION['preparation_csrf'] ??= bin2hex(random_bytes(32));
if (!function_exists('gzuncompress')) {
    http_response_code(409);
    exit('Система пока не готова: включите расширение zlib в PHP сайта и перезапустите PHP.');
}
$payloadJson = gzuncompress(base64_decode('__PAYLOAD__', true));
if (!is_string($payloadJson) || !hash_equals('__PAYLOAD_SHA__', hash('sha256', $payloadJson))) {
    http_response_code(500);
    exit('Повреждён подготовительный патч. Скачайте его повторно.');
}
$payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
$files = array_values(array_filter(array_keys($payload), static fn (string $file): bool => $file !== 'core/Version.php'));
$files[] = 'modules/admin/services/AdminUpdateService.php';
$report = PreparationRequirements::inspect($root, $files);
$maintenance = new \App\Services\MaintenanceModeService(null, $root);
if ($maintenance->state()['active']) {
    $report['ready'] = false;
    $report['checks'][] = ['name' => 'Обновление уже выполняется', 'ok' => false, 'detail' => 'Активен режим обслуживания', 'action' => 'Завершите или восстановите текущую транзакцию в админке.'];
}
$success = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['preparation_csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('Проверка формы не пройдена. Обновите страницу.');
    }
    if (!$report['ready']) {
        http_response_code(409);
        $error = 'Установка заблокирована: сначала исправьте указанные требования.';
    } else {
        $transaction = 'prep-' . bin2hex(random_bytes(12));
        $source = null;
        $entered = false;
        $retainMaintenance = false;
        try {
            $private = (new \Core\PrivateStorageResolver($root))->candidate();
            repairUpdaterEnsureParent($private, 0700);
            $source = $private . '/preparation-source-' . bin2hex(random_bytes(12));
            repairUpdaterEnsureParent($source, 0700);
            foreach ($payload as $relative => $entry) {
                if (!is_string($relative) || preg_match('~^(core|bin|app/services|assets/js)/[A-Za-z0-9_/-]+\.(php|js)$~D', $relative) !== 1 || str_contains($relative, '..')) {
                    throw new RuntimeException('Небезопасный путь в патче.');
                }
                $bytes = base64_decode($entry['data'], true);
                if (!is_string($bytes) || !hash_equals($entry['sha256'], hash('sha256', $bytes))) {
                    throw new RuntimeException('Повреждён файл патча.');
                }
                repairUpdaterEnsureParent(dirname($source . '/' . $relative), 0700);
                if (file_put_contents($source . '/' . $relative, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Не удалось распаковать патч в приватное хранилище.');
                }
            }
            $maintenance->enter($transaction, 'Подготовка обновлятора 1.0.16 для перехода на 1.1');
            $entered = true;
            preparationApply($source, $root, $private);
            $success = true;
            $_SESSION['preparation_csrf'] = bin2hex(random_bytes(32));
        } catch (\Throwable $failure) {
            $retainMaintenance = $failure->getCode() === 7;
            error_log('Workspace preparation: ' . $failure->getMessage());
            http_response_code(500);
            $error = $retainMaintenance
                ? 'Не удалось восстановить файлы. Режим обслуживания сохранён; обратитесь к журналу PHP и резервной копии обновлятора в приватном хранилище.'
                : 'Подготовка не завершена. Подробности сохранены в журнале PHP. Не запускайте обновление до устранения причины.';
        } finally {
            if ($entered && !$retainMaintenance) {
                $maintenance->leave($transaction);
            }
            if ($source !== null) {
                repairUpdaterRemoveTree($source);
            }
        }
    }
}
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$adminUrl = rtrim((string) \Core\Config::get('SITEURL', ''), '/') . '/admin/updates';
?>
<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Подготовка 1.0.16 к обновлению</title>
<style>body{font:16px system-ui;background:#f1f6ff;color:#10234b;margin:0;padding:24px}main{max-width:850px;margin:auto;background:white;padding:24px;border:1px solid #d3e1fa;border-radius:16px}li{padding:12px;margin:8px 0;background:#f5f8ff;border-radius:8px}p{line-height:1.5}button,a{display:inline-block;padding:12px 18px;border-radius:8px}button{background:#2671ff;color:white;border:0;cursor:pointer}button:disabled{opacity:.5;cursor:default}.bad{color:#b32338}.good{color:#16754a}</style>
<main><h1>Подготовка 1.0.16 к обновлению на 1.1</h1>
<p>Патч обновляет только исполнитель обновлений. Версия приложения, настройки .env, ключи доверия, база данных и пользовательские материалы сохраняются. После подготовки установите 1.1.0 через раздел обновлений в админке.</p>
<h2 class="<?= $report['ready'] ? 'good' : 'bad' ?>"><?= $report['ready'] ? 'Минимальные требования подготовки выполнены' : 'Система пока не готова' ?></h2>
<p>Это проверка минимальных требований, а не нагрузочная приёмка. Перед полным обновлением админка дополнительно проверит подписанный пакет, миграции и место для резервной копии.</p>
<ul><?php foreach ($report['checks'] as $check): ?><li><strong class="<?= $check['ok'] ? 'good' : 'bad' ?>"><?= $check['ok'] ? '✓' : '✕' ?> <?= $escape($check['name']) ?></strong><br><?= $escape($check['detail']) ?><?php if (!$check['ok']): ?><p><?= $escape($check['action']) ?></p><?php endif ?></li><?php endforeach ?></ul>
<?php if ($error !== ''): ?><p class="bad"><?= $escape($error) ?></p><?php endif ?>
<?php if ($success): ?><p class="good">Подготовительный патч установлен. Версия приложения осталась 1.0.16. Удалите загруженный файл подготовки с сайта.</p><a href="<?= $escape($adminUrl) ?>">Перейти к обновлениям в админке</a>
<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['preparation_csrf']) ?>"><button <?= $report['ready'] ? '' : 'disabled' ?>>Установить подготовительный патч</button></form><?php endif ?></main></html>
