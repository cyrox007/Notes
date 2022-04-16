<main class="edit-note">
    <h2 class="page-title">Редактируем: <? echo "{$data['title']}"; ?></h2>
    <form action="" method="post">
        <textarea class="note-edit" name="textarea" id="" cols="30" rows="10" placeholder="Введите текст вашей заметки..."><? echo "{$data['text']}"; ?></textarea>
        <button type="submit">Сохранить</button>
    </form>
</main>