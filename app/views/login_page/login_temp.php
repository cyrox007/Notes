<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="My Workspace System">
    <title>My Workspace - <?php echo $data['title']; ?></title>
    <link rel="stylesheet" href="<?php echo $data['style']; ?>">
</head>
<body>
    <?php include 'app/views/'.$content_view; ?>
    <script src="<?php echo $data['script']; ?>"></script>
</body>
</html>