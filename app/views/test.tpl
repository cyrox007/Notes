<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <script>
        const xhr = new XMLHttpRequest();
        let formData = new FormData();

        formData.append("test", 'ok')

        xhr.open("POST", '/test');
        xhr.onload = () => {
            console.log(xhr.responseText);
        };

        xhr.send(formData);
    </script>
</body>
</html>