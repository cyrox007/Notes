<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$noteRow = isset($note) && is_array($note) ? $note : [];
$showAuthor = !empty($showAuthor);
$readOnly = !empty($readOnly);
$noteName = trim((string) ($noteRow['notename'] ?? ''));
if ($noteName === '') {
    $noteName = 'Без названия';
}
$createdAt = (string) ($noteRow['created_note'] ?? '');
$updatedAt = (string) ($noteRow['updated_note'] ?? '');
$uid = (string) ($noteRow['uid'] ?? '');
?>
<div class="notes__list_item">
    <div class="notes__name"><?= $view->e($noteName) ?></div>
    <?php if ($showAuthor): ?>
        <div class="notes__author"><?= $view->e(($noteRow['author_username'] ?? '') !== '' ? $noteRow['author_username'] : 'Неизвестно') ?></div>
    <?php endif; ?>
    <div class="notes__date">
        Создано: <?= $view->e($createdAt) ?><br>
        <?php if ($createdAt !== $updatedAt): ?>Редактировано: <?= $view->e($updatedAt) ?><?php endif; ?>
    </div>
    <div class="notes__btn">
        <?php if (!$readOnly): ?>
            <a class="notes__btn--edit" href="<?= $view->e($view->route('edit_page', ['uid' => $uid])) ?>" title="Редактировать" aria-label="Редактировать <?= $view->e($noteName) ?>">
                <i class="fa fa-pencil" aria-hidden="true"></i>
            </a>
            <form action="<?= $view->e($view->route('delete_note', ['uid' => $uid])) ?>" method="post" class="notes__delete-form" onsubmit="return confirm('Вы уверены, что хотите удалить эту заметку?')">
                <?= $view->csrfInput() ?>
                <button class="notes__btn--delete" type="submit" title="Удалить" aria-label="Удалить <?= $view->e($noteName) ?>">
                    <i class="fa fa-trash-o" aria-hidden="true"></i>
                </button>
            </form>
        <?php else: ?>
            <span title="Для чужих заметок доступен только просмотр метаданных" aria-label="Только метаданные"><i class="fa fa-lock" aria-hidden="true"></i></span>
        <?php endif; ?>
    </div>
</div>
