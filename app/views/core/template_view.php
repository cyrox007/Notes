<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $data['site']['sitename'].' '.$data['site']['version']; ?> | <?php echo $data['title']; ?></title>

  <? foreach ($data['styles'] as $css):?>
    <link rel="stylesheet" href="<? echo $css; ?>">
  <? endforeach; ?>
  
  <link rel="icon" href="/favicon.ico" type="image/x-icon">
  <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
</head>
<body>

  <!-- Site wrapper -->
  <div class="wrapper">
    <?php include 'app/views/shared/sidebar.php'; ?>
    <div class="wrapper__content">
      <?php include 'app/views/shared/header.php'; ?>
      <div class="content-wrapper">
        <?php include 'app/views/'.$content_view; ?>
      </div>
      <?php include 'app/views/shared/footer.php'; ?>
    </div>
  </div>
  <!-- ./wrapper -->
  <script src="<? echo $data['script']; ?>"></script>
</body>
</html>