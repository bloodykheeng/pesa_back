<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Reset Password</title>
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

        .form-container {
            max-width: 400px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
        }

        input[type="password"] {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }

        input[type="submit"] {
            padding: 10px 20px;
            background-color: #002147;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .error-message {
            color: red;
            list-style-type: disc;
            padding-left: 20px;
        }

        h4 {
            color: #fff;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Confidential</h1>
        <h3>ELEVATE PESA</h3>
    </div>

    <div class="form-container">
        @if (isset($validator) && $validator->fails())
            <ul class="error-message">
                @foreach ($validator->errors()->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="post">
            @csrf
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Password">
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirm Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                    placeholder="Confirm Password">
            </div>

            <input type="hidden" name="id" value="{{ $user->id }}">

            <input type="submit" value="Reset Password">
        </form>
    </div>
</body>

</html>
