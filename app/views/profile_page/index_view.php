<section class="profile">
  <div class="profile__column-left">
    <div class="profile__card-avatar">
      <p class="profile__user-login"><? echo $data['user'];?></p>
      <img class="profile__user-image" src="<? echo $data['user-photo']; ?>" alt="" srcset="">
      <button class="profile__edit_user-info">Редактировать</button>
    </div>
  </div>
  <div class="profile__column-right">
    <div class="profile__card-info visible">
      <div class="profile__user-fio">
        <? echo $data['user-name'].' '.$data['user-patronymic'].' '.$data['user-surname']; ?>
      </div>
      <div class="profile__user-other-info">
        <p class="profile__user-detals">Личный номер телефона: <? echo $data['user-phone']; ?></p>
        <p class="profile__user-detals">Должность: <? echo $data['user-position']; ?></p>
        <p class="profile__user-detals">Отдел: <? echo $data['department']; ?></p>
        <p class="profile__user-detals">Телефон: <? echo $data['office-phone']; ?></p>
      </div>
    </div>
    <div class="profile__card-info-edit" style="display: none">
      <form action="" method="post" enctype="multipart/form-data">
        <div class="profile__card-info-edit--form-group">
          <label for="">Имя: </label>
          <input class="set-input" type="text" name="set-user-name" id="user-name" placeholder="Введите имя">
        </div>
        <div class="profile__card-info-edit--form-group">
          <label for="">Отчество: </label>
          <input class="set-input" type="text" name="set-user-patronymic" id="user-patronymic" placeholder="Введите отчество">
        </div>
        <div class="profile__card-info-edit--form-group">
          <label for="">Фамилия: </label>
          <input class="set-input" type="text" name="set-user-surname" id="user-name" placeholder="Введите фамилию">
        </div>
        <div class="profile__card-info-edit--form-group">
          <label for="">Телефон: </label>
          <input class="set-input" type="text" name="set-user-phone" id="user-phone" placeholder="Введите свой номер телефона">
        </div>
        <label for="">Изменить изображение пользователя:</label>
        <input class="set-files" type="file" name="set-user-avatar" id="user-avatar">
        <hr style="width: 100%; height: 1px; margin: 15px 0;">
        <div class="profile__card-info-edit--form-group">
          <label for="">Должность: </label>
          <input class="set-input" type="text" name="set-user-position" id="user-position" placeholder="Введите вашу должность">
        </div>
        <div class="profile__card-info-edit--form-group">
          <label for="">Отдел: </label>
          <input class="set-input" type="text" name="set-user-deportament" id="user-deportament" placeholder="Укажите ваш отдел">
        </div>
        <div class="profile__card-info-edit--form-group">
          <label for="">Телефон офиса: </label>
          <input class="set-input" type="text" name="set-office-phone" id="user-office-phone" placeholder="Укажите телефон офиса">
        </div>
        <button class="set-save" type="submit">Сохранить изменения</button>
      </form>
      
    </div>
  </div>
</section>
<script>
  document.addEventListener('DOMContentLoaded', ()=>{
    let btnEditProfile = document.querySelector('.profile__edit_user-info');
    let cardInfo = document.querySelector('.profile__card-info');
    let cardEdit = document.querySelector('.profile__card-info-edit');

    btnEditProfile.addEventListener('click', (e)=>{
      e.preventDefault();
      btnEditProfile.classList.toggle('invisible-btn');
      btnEditProfile.disabled = true;
      cardInfo.classList.toggle('visible');
      setTimeout(()=>{
        cardInfo.style.display = 'none';
        cardEdit.style.display = 'block';
      }, 300);
      setTimeout(()=>{
        cardEdit.style.display = 'block';
      }, 300);
      cardEdit.classList.toggle('visible');
    });
  });
</script>