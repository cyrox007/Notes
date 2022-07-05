<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1><?php echo $data['title']; ?></h1>
          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="#">Home</a></li>
              <li class="breadcrumb-item active">Projects</li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Main content -->
    <section class="content">
        <form action="" method="post">
            <div class="form-row form-group">
                <!-- text input -->
                <div class="col-sm-10">
                    <input type="text" name="note-name" class="form-control" placeholder="Введите название новой заметки...">
                </div>
                <div class="col-sm-2 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">Создать</button>
                </div>
            </div>
        </form>
      <!-- Default box -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Список записей</h3>

          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse" data-toggle="tooltip" title="Collapse">
              <i class="fas fa-minus"></i></button>
            <button type="button" class="btn btn-tool" data-card-widget="remove" data-toggle="tooltip" title="Remove">
              <i class="fas fa-times"></i></button>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="notes_list">
              <div class="notes__list-head">
                  <p>Название</p>
                  <p>Автор</p>
                  <p></p>
              </div>
              <div class="notes__list-body">
              <? foreach($data['notes'] as $note): ?>
                    
                      <div class="notes__item">
                          <p>
                              <a class="notes__link">
                                    <? echo $note['name_note'];?>
                                </a> 
                          </p>
                          <br/>
                          <span>Создано <? echo $note['date_create']; ?></span>
                            <? if ($note['date_create'] != $note['date_edit']): ?>
                              <br/>
                              <span>
                                  Редактировано <? echo $note['date_edit']; ?>
                              </span>
                              <? endif; ?>
                          <p><? echo $note['author']; ?></p>
                      <?php if (!$data['admin'] && $data['user_id'] != $note['user_id']):?>
                          
                          <p class="">
                        </p>
                      
                      <? else: ?>
                      <div class="">
                          <a class="btn" href="/Notes/edit/<? echo $note['id']?>">
                              <i class="fas fa-pencil-alt"></i>
                              Редактировать
                          </a>
                          <a class="btn" href="/Notes/delete/<? echo $note['id']?>">
                              <i class="fas fa-trash"></i>
                              Удалить
                          </a>
                      </div>
                      <?php endif; ?> 
                  </div>
                  <?php endforeach; ?>
              </div>
          </div>
        </div>
        <!-- /.card-body -->
      </div>
      <!-- /.card -->

    </section>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->
