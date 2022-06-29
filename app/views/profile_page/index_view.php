<div class="content-wrapper" style="min-height: 1419.25px;">
    <!-- Main content -->
    <section class="content">
      <div class="container-fluid pt-3">
        <div class="row">
          <div class="col-md-3">

            <!-- Profile Image -->
            <div class="card card-primary card-outline">
              <div class="card-body box-profile">
                <div class="text-center">
                  <img class="profile-user-img img-fluid img-circle" src="<?php echo $data['userphoto'];?>" alt="User profile picture">
                </div>

                <h3 class="profile-username text-center"><?php echo $data['username']; ?></h3>

                <p class="text-muted text-center"><?php echo $data['user_position']; ?></p>
                <p class="text-muted text-center"><?php echo $data['user_phone']; ?></p>
                
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->

            <!-- About Me Box -->
            <div class="card card-primary">
              <div class="card-header">
                <h3 class="card-title">Обо мне</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <strong><i class="fas fa-book mr-1"></i> Отдел</strong>

                <p class="text-muted">
                  <?php echo $data['department']; ?>
                </p>

                <hr>

                <strong><i class="fas fa-map-marker-alt mr-1"></i> Телефон офиса</strong>
<br>
                <a href="tel:<?php echo $data['office_phone']; ?>" class="text-muted"><?php echo $data['office_phone']; ?></a>

                
              </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
          <div class="col-md-9">
            <div class="card">
              <div class="card-header p-2">
                <ul class="nav nav-pills">
                  <li class="nav-item"><a class="nav-link active" href="#activity" data-toggle="tab">Личные заметки</a></li>
                  <li class="nav-item"><a class="nav-link" href="#files" data-toggle="tab">Файлы</a></li>
                  <li class="nav-item"><a class="nav-link" href="#settings" data-toggle="tab">Настройки профиля</a></li>
                </ul>
              </div><!-- /.card-header -->
              <div class="card-body">
                <div class="tab-content">
                    <div class="tab-pane active" id="activity">
                      <?php foreach ($data['personal_notes'] as $note): ?>
                      <!-- Post -->
                      <div class="post">
                        <div class="user-block">
                          <span class="username">
                            <a href="#"><?php echo $note['name_note'] ?></a>
                          </span>
                          <span class="description">Дата публикации - <?php echo $note['date_edit'];?></span>
                        </div>
                        <!-- /.user-block -->
                      </div>
                      <!-- /.post -->
                      <table class="table table-striped projects">
                        <thead>
                            <tr>
                                <th style="width: 1%">
                                    #
                                </th>
                                <th style="width: 20%">
                                    Название
                                </th>
                                <th style="width: 30%">
                                    Автор
                                </th>
                                <th style="width: 20%">
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($data['personal_notes'] as $note): 
                          
                          ?>
                            <tr>
                                <td>
                                    #
                                </td>
                                <td>
                                    <a>
                                      <? echo $note['name_note'];?>
                                    </a>
                                    <br/>
                                    <small>
                                        Создано <? echo $note['date_create']; ?>
                                    </small>
                                    <?php if ($note['date_create'] != $note['date_edit']):?>
                                    <br/>
                                    <small>
                                        Редактировано <? echo $note['date_edit']; ?>
                                    </small>
                                    <?php endif ?>
                                </td>
                                <td>
                                    <ul class="list-inline">
                                        <li class="list-inline-item">
                                            <?php echo $note['author']?>
                                            <!-- <img alt="<?php echo $data['user'] ?>" class="table-avatar" src="<?php echo $data['userphoto'] ?>"> -->
                                        </li>
                                    </ul>
                                </td>
                                <td class="project-actions text-right">
                                    <a class="btn btn-info btn-sm" href="/Notes/edit/<? echo $note['id']?>">
                                        <i class="fas fa-pencil-alt">
                                        </i>
                                        Редактировать
                                    </a>
                                    <a class="btn btn-danger btn-sm" href="/Notes/delete/<? echo $note['id']?>">
                                        <i class="fas fa-trash">
                                        </i>
                                        Удалить
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                      <?php endforeach; ?>
                      
                     
                    </div>
                  <!-- /.tab-pane -->
                  <div class="tab-pane" id="files"></div>
                  <div class="tab-pane" id="settings">
                    <form action="" method="post" class="form-horizontal">
                    <div class="form-group row">
                        <label for="inputName" class="col-sm-2 col-form-label">Фамилия</label>
                        <div class="col-sm-10">
                          <input type="text" name="setSurname" class="form-control" id="inputName" placeholder="Фамилия">
                        </div>
                      </div>
                      <div class="form-group row">
                        <label for="inputName" class="col-sm-2 col-form-label">Имя</label>
                        <div class="col-sm-10">
                          <input type="text" name="setName" class="form-control" id="inputName" placeholder="Имя">
                        </div>
                      </div>
                      <div class="form-group row">
                        <label for="inputName" class="col-sm-2 col-form-label">Отчество</label>
                        <div class="col-sm-10">
                          <input type="text" name="setPatronymic" class="form-control" id="inputName" placeholder="Отчество">
                        </div>
                      </div>
                      <div class="form-group row">
                        <label for="customFile" class="col-sm-2 col-form-label">Аватар</label>

                        <div class="col-sm-10 custom-file">
                          <input type="file" class="custom-file-input" id="customFile">
                          <label class="custom-file-label" name="setAvatar" for="customFile">Аватар</label>
                        </div>
                      </div>
                      <div class="form-group row">
                        <label for="inputEmail" class="col-sm-2 col-form-label">Телефон</label>
                        <div class="col-sm-10">
                          <input type="text" name="setUserPhone" class="form-control" id="inputEmail" placeholder="Телефон">
                        </div>
                      </div>
                      <div class="form-group row">
                        <label for="inputName2" class="col-sm-2 col-form-label">Должность</label>
                        <div class="col-sm-10">
                          <input type="text" name="setUserPosition" class="form-control" id="inputName2" placeholder="Должность">
                        </div>
                      </div>
                      <div class="form-group row">
                        <label for="inputExperience" class="col-sm-2 col-form-label">Отдел</label>
                        <div class="col-sm-10">
                          <textarea class="form-control" name="setDepartment" id="inputExperience" placeholder="Отдел"></textarea>
                        </div>
                      </div>
                      <div class="form-group row">
                        <div class="offset-sm-2 col-sm-10">
                          <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                        </div>
                      </div>
                    </form>
                  </div>
                  <!-- /.tab-pane -->
                </div>
                <!-- /.tab-content -->
              </div><!-- /.card-body -->
            </div>
            <!-- /.nav-tabs-custom -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
  </div>