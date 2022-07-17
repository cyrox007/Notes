<section class="profile">
  <div class="profile__column-left">
    <div class="profile__card-avatar">
      <p class="profile__user-login"><? echo $data['user'];?></p>
      <img class="profile__user-image" src="<? echo $data['user-photo']; ?>" alt="" srcset="">
      <button class="profile__edit_user-info">Редактировать</button>
    </div>
  </div>
  <div class="profile__column-right">
    <div class="profile__card-info">
      <div class="profile__card-info--data visible">
        <div class="profile__user-fio">
          <? echo $data['user-name'].' '.$data['user-patronymic'].' '.$data['user-surname']; ?>
        </div>
        <div class="profile__user-other-info">
          <p class="profile__user-detals">Личный номер телефона: <? echo $data['user-phone']; ?></p>
          <p class="profile__user-detals">Должность: <? echo $data['user-position']; ?></p>
          <p class="profile__user-detals">Отдел: <? echo $data['department']; ?></p>
          <p class="profile__user-detals">Телефон: <? echo $data['office-phone']; ?></p>
        </div>
        <? if ($data['is-admin']): ?>
        <div class="profile__user-system">
          Адрес сервера: <? echo $_SERVER['SERVER_ADDR']; ?>
          Ваш IP: <? echo $_SERVER['REMOTE_ADDR']; ?>
          <? echo $_SERVER['HTTP_CLIENT_IP'];?>
          <? echo $_SERVER['HTTP_X_FORWARDED_FOR']; ?>
        </div>
        <? endif; ?>
      </div>
      <div class="profile__card-info--edit">
        <div id="close">
          <i class="fa fa-times" aria-hidden="true"></i>
        </div>
        <form name="changeProfile" action="" method="post" enctype="multipart/form-data">
          <div class="profile__card-info--edit--form-group">
            <label for="">Имя: </label>
            <input class="profile__card-info--edit--set-input" type="text" name="set-user-name" id="user-name" placeholder="Введите имя">
          </div>
          <div class="profile__card-info--edit--form-group">
            <label for="">Отчество: </label>
            <input class="profile__card-info--edit--set-input" type="text" name="set-user-patronymic" id="user-patronymic" placeholder="Введите отчество">
          </div>
          <div class="profile__card-info--edit--form-group">
            <label for="">Фамилия: </label>
            <input class="profile__card-info--edit--set-input" type="text" name="set-user-surname" id="user-surname" placeholder="Введите фамилию">
          </div>
          <div class="profile__card-info--edit--form-group">
            <label for="">Телефон: </label>
            <input class="profile__card-info--edit--set-input" type="text" name="set-user-phone" id="user-phone" placeholder="Введите свой номер телефона">
          </div>
          <label style="margin-top: 15px" for="">Изменить изображение пользователя:</label>
          <input style="margin-bottom: 15px" class="set-files" type="file" name="set-user-avatar" id="user-avatar">
          
          <div class="profile__card-info--edit--form-group">
            <label for="">Должность: </label>
            <input class="profile__card-info--edit--set-input" type="text" name="set-user-position" id="user-position" placeholder="Введите вашу должность">
          </div>
          <div class="profile__card-info--edit--form-group">
            <label for="">Отдел: </label>
            <input class="profile__card-info--edit--set-input" type="text" name="set-user-deportament" id="user-deportament" placeholder="Укажите ваш отдел">
          </div>
          <div class="profile__card-info--edit--form-group">
            <label for="">Телефон офиса: </label>
            <input class="profile__card-info--edit--set-input" type="text" name="set-office-phone" id="user-office-phone" placeholder="Укажите телефон офиса">
          </div>
          <button class="profile__card-info--edit--set-save" type="submit">Сохранить изменения</button>
        </form>
        <hr>
        <form name="changePassword" action="/Profile/changePass" method="post">
          <div class="profile__card-info--edit--form-group">
            <label for="">Старый пароль: </label>
            <input class="profile__card-info--edit--set-input" type="password" name="old-password" id="old-password" placeholder="Введите старый пароль...">
          </div>
          <div class="profile__card-info--edit--form-group">
            <label for="">Новый пароль: </label>
            <input class="profile__card-info--edit--set-input" type="password" name="new-password" id="new-password" placeholder="Введите новый пароль...">
          </div>
          <div class="profile__card-info--edit--form-group">
            <label for="">Повторите пароль: </label>
            <input class="profile__card-info--edit--set-input" type="password" name="repeat-new-password" id="repeat-new-password" placeholder="Повторите новый пароль...">
          </div>
          <span id="error-repeat"></span>
          <button id="change-password-btn" class="profile__card-info--edit--set-save" type="submit">Изменить пароль</button>
        </form>
        <hr>
        <form name="deleteUser" action="/Profile/deleteUser" method="post">
          <button class="profile__card-info--edit--delete" type="submit">Удалить аккаунт</button>
        </form>
      </div>
    </div>
  </div>
</section>
<script src="<? echo $data['profile-script']; ?>"></script>