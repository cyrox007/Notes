<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$noteRow = isset($note) && is_array($note) ? $note : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$noteTitle = trim((string) ($noteRow['notename'] ?? ''));
if ($noteTitle === '') {
    $noteTitle = 'Без названия';
}

$content = $view->partial('notes_page/editor-013', [
    'note' => $noteRow,
    'attachments' => isset($attachments) && is_array($attachments) ? $attachments : [],
    'shareInfo' => isset($shareInfo) && is_array($shareInfo) ? $shareInfo : null,
    'base_url' => $baseUrl,
]);
$content .= '<script src="' . $view->e($baseUrl) . '/assets/js/notes-editor-013.js" defer></script>';

echo $view->layout('core/base', [
    'title' => 'Блокнот: ' . $noteTitle,
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
