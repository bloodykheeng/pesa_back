<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ $data['title'] }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f1f1f1;
        }

        .header {
            background-color: #002147;
            color: #fff;
            padding: 10px;
            text-align: center;
            font-size: 24px;
        }

        .logo {
            width: 150px;
            height: auto;
            display: block;
            margin: 0 auto;
            margin-top: 20px;
        }

        .content {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        a.button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #002147;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }

        p.footer {
            text-align: center;
            margin-top: 20px;
        }

        h4 {
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Confidential</h1>
        <h4>ELEVATE PESA</h4>
    </div>

    <div class="content">
        <p>{{ $data['body'] }}</p>
        <a href="{{ $data['url'] }}" class="button">Click here to reset your password</a>
        <p class="footer">Thank You</p>
    </div>
</body>

</html>
