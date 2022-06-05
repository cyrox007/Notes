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
                    <input type="text" class="form-control" placeholder="Введите название новой заметки...">
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
              <?php 
                        foreach($data['files'] as $file): 
                        mb_internal_encoding("UTF-8");
                        // сколько знаков надо убрать сначала - отрезаем в имени дату и время
                        $fname = mb_substr($file, 16);
                        // сколько знаков надо убрать в конце строки - отрезаем расширение .txt
                        $fname = mb_substr($fname, 0, -4); 
                    ?>
                  <tr>
                      <td>
                          #
                      </td>
                      <td>
                          <a>
                            <? echo "{$fname}"?>
                          </a>
                          <br/>
                          <small>
                              Created 01.01.2019
                          </small>
                      </td>
                      <td>
                          <ul class="list-inline">
                              <li class="list-inline-item">
                                  <img alt="<?php echo $data['user'] ?>" class="table-avatar" src="<?php echo $data['userphoto'] ?>">
                              </li>
                          </ul>
                      </td>
                      <td class="project-actions text-right">
                          <a class="btn btn-info btn-sm" href="/Notes/edit/<? echo "{$file}"?>">
                              <i class="fas fa-pencil-alt">
                              </i>
                              Edit
                          </a>
                          <a class="btn btn-danger btn-sm" href="/Notes/delete/<? echo "{$file}"?>">
                              <i class="fas fa-trash">
                              </i>
                              Delete
                          </a>
                      </td>
                  </tr>
                  <?php endforeach; ?>
              </tbody>
          </table>
        </div>
        <!-- /.card-body -->
      </div>
      <!-- /.card -->

    </section>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->