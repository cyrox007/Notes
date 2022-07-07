<section class="note-header">
  <h1 class="note__title"><? echo $data['note-name']; ?></h1>
  <p class="note__info">Автор: <? echo $data['note-author']; ?></p>
  <p class="note__info">Дата создания: <? echo $data['note-create']; ?></p>
  <p class="note__info">Дата редактирования: <? echo $data['note-edit']; ?></p>
</section>

<section class="note-content">
  <form class="note__edit" action="" method="post">
      <div class="note__text">
          <textarea name="content" class="textarea" placeholder="Place some text here"><?php echo $data['note-content'] ?></textarea>
      </div>
      <div class="note__submit">
          <button type="submit" class="btn btn-primary">Submit</button>
      </div>
  </form>
</section>