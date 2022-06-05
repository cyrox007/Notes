<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>My Workspace 1.2 | <?php echo $data['title']; ?></title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">

 <?php foreach ($data['styles'] as $css):?>
  <link rel="stylesheet" href="<?php echo $css; ?>">
<?php endforeach ?>
  <!-- Ionicons -->
  <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
</head>
<body class="hold-transition sidebar-mini">
<!-- Site wrapper -->
<div class="wrapper">
  <?php include 'app/views/shared/header.php'; ?>
  <?php include 'app/views/'.$content_view; ?>
  <?php include 'app/views/shared/footer.php'; ?>
</div>
<!-- ./wrapper -->
<?php foreach ($data['scripts'] as $js):?>
  <script src="<?php echo $js; ?>"></script>
<?php endforeach ?>
</body>
</html>
